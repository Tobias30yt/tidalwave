<?php
declare(strict_types=1);
final class AuthStore {
    public readonly PDO $db;
    public function __construct(string $directory, private readonly string $key) {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Cannot create private auth storage');
        $this->db = new PDO('sqlite:'.$directory.'/accounts.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=1500; PRAGMA journal_mode=WAL;');
        $this->db->exec('CREATE TABLE IF NOT EXISTS users (
            id TEXT PRIMARY KEY, username TEXT NOT NULL, created_at INTEGER NOT NULL,
            verified_at INTEGER, verified_guild TEXT, blocked INTEGER NOT NULL DEFAULT 0, session_key TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS challenges (
            user_id TEXT PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
            digest TEXT NOT NULL UNIQUE, expires_at INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS limits (bucket TEXT PRIMARY KEY, count INTEGER NOT NULL, expires_at INTEGER NOT NULL);
        CREATE TABLE IF NOT EXISTS interactions (id TEXT PRIMARY KEY, expires_at INTEGER NOT NULL);
        CREATE TABLE IF NOT EXISTS blocked_accounts (id TEXT PRIMARY KEY);
        CREATE TABLE IF NOT EXISTS installations (
            user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE, bot_id TEXT NOT NULL,
            guild_id TEXT NOT NULL, installed_at INTEGER NOT NULL, PRIMARY KEY(user_id, bot_id, guild_id)
        );');
    }
    public function query(string $sql, array $params = []): PDOStatement {
        $statement = $this->db->prepare($sql); $statement->execute($params); return $statement;
    }
    public function user(string $id): ?array {
        return $this->query('SELECT * FROM users WHERE id = ?', [$id])->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    public function signIn(array $profile): array {
        if (!discord_id($profile['id'] ?? null) || !is_string($profile['username'] ?? null) || strlen($profile['username']) > 128 || ($profile['bot'] ?? false)) {
            throw new AuthError(502, 'Discord returned an invalid account. Please try signing in again.');
        }
        if ($this->query('SELECT 1 FROM blocked_accounts WHERE id=?',[$profile['id']])->fetchColumn()) throw new AuthError(403, 'This account cannot access bot installation.');
        $this->query('INSERT INTO users(id, username, created_at, session_key) VALUES(?,?,?,?) ON CONFLICT(id) DO UPDATE SET username=excluded.username', [$profile['id'], $profile['username'], time(), bin2hex(random_bytes(16))]);
        $user = $this->user($profile['id']);
        if ($user['blocked']) throw new AuthError(403, 'This account cannot access bot installation.');
        return $user;
    }
    public function limit(string $subject, int $maximum, int $seconds): void {
        $now = time();
        $bucket = hash_hmac('sha256', $subject.':'.intdiv($now, $seconds), $this->key);
        $count = $this->query('INSERT INTO limits(bucket,count,expires_at) VALUES(?,1,?) ON CONFLICT(bucket) DO UPDATE SET count=count+1 RETURNING count', [$bucket, $now + $seconds])->fetchColumn();
        if ((int)$count > $maximum) throw new AuthError(429, 'Too many attempts. Please wait a few minutes and try again.');
    }
    public function challenge(string $id): string {
        $this->limit('challenge:'.$id, 5, 600);
        $code = strtoupper(bin2hex(random_bytes(8)));
        $this->query('INSERT INTO challenges(user_id,digest,expires_at) VALUES(?,?,?) ON CONFLICT(user_id) DO UPDATE SET digest=excluded.digest, expires_at=excluded.expires_at', [$id, hash('sha256', $code), time()+600]);
        return implode('-', str_split($code, 4));
    }
    public function verify(string $id, string $guild, string $code): bool {
        $this->limit('verify:'.$id, 10, 600);
        $code = strtoupper(str_replace('-', '', trim($code)));
        if (!preg_match('/^[A-F0-9]{16}$/D', $code)) return false;
        $this->db->beginTransaction();
        try {
            // DELETE RETURNING makes the one-time consumption atomic, including concurrent requests.
            $match = $this->query('DELETE FROM challenges WHERE user_id=? AND digest=? AND expires_at>? AND EXISTS(SELECT 1 FROM users WHERE id=? AND blocked=0) RETURNING user_id', [$id, hash('sha256', $code), time(), $id])->fetchColumn();
            if ($match !== false) $this->query('UPDATE users SET verified_at=?, verified_guild=? WHERE id=?', [time(), $guild, $id]);
            $this->db->commit(); return $match !== false;
        } catch (Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }
    public function verified(array $user, array $config): bool {
        return !$user['blocked'] && $user['verified_guild'] === $config['guild_id']
            && (int)$user['verified_at'] > time() - (int)$config['verification_lifetime'];
    }
    public function revoke(string $id, bool $block = false): void {
        $this->db->beginTransaction();
        try {
            $this->query('UPDATE users SET verified_at=NULL, verified_guild=NULL, blocked=? WHERE id=?', [$block ? 1 : 0, $id]);
            if ($block) $this->query('INSERT OR IGNORE INTO blocked_accounts(id) VALUES(?)',[$id]);
            else $this->query('DELETE FROM blocked_accounts WHERE id=?',[$id]);
            $this->query('DELETE FROM challenges WHERE user_id=?', [$id]);
            $this->db->commit();
        } catch (Throwable $e) { $this->db->rollBack(); throw $e; }
    }
    public function cleanup(): void {
        foreach (['challenges', 'limits', 'interactions'] as $table) $this->query('DELETE FROM '.$table.' WHERE expires_at < ?', [time()]);
    }
}
