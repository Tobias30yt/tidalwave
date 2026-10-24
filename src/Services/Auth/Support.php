<?php
declare(strict_types=1);
final class AuthError extends RuntimeException {
    public function __construct(public readonly int $status, string $message) { parent::__construct($message); }
}
function discord_id(mixed $value): bool { return is_string($value) && preg_match('/^[0-9]{17,20}$/D', $value) === 1; }
function auth_configure(array $config): void {
    $url = parse_url($config['origin']);
    $local = ($config['allow_local_http'] ?? false) && in_array($url['host'] ?? '', ['localhost', '127.0.0.1'], true);
    if (!$url || !in_array($url['scheme'] ?? '', $local ? ['http', 'https'] : ['https'], true)
        || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
        || !empty($url['path']) || empty($url['host'])) throw new AuthError(503, 'Account setup is incomplete. Please contact TidalWave.');
    foreach (['client_id', 'guild_id'] as $key) if (!discord_id($config[$key])) throw new AuthError(503, 'Account setup is incomplete. Please contact TidalWave.');
    foreach (['app_key', 'public_key'] as $key) if (!preg_match('/^[a-f0-9]{64}$/iD', $config[$key])) throw new AuthError(503, 'Account setup is incomplete. Please contact TidalWave.');
    if (!$config['client_secret'] || !$config['bot_token']) throw new AuthError(503, 'Account setup is incomplete. Please contact TidalWave.');
    foreach (['curl', 'pdo_sqlite', 'sodium'] as $extension) if (!extension_loaded($extension)) throw new AuthError(503, 'The account service is unavailable. Please contact TidalWave.');
}
