<?php
declare(strict_types=1);
final class AuthSession {
    public static function start(array $config): void {
        $directory = $config['storage'].'/sessions';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Cannot create session storage');
        ini_set('session.use_strict_mode', '1'); ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0'); ini_set('session.gc_maxlifetime', '28800');
        session_save_path($directory); session_name('tw_session');
        session_set_cookie_params(['lifetime'=>0, 'path'=>'/', 'secure'=>str_starts_with($config['origin'], 'https://'), 'httponly'=>true, 'samesite'=>'Lax']);
        session_start();
        $now=time();
        if ($now - ($_SESSION['last_seen'] ?? $now) > 3600 || $now - ($_SESSION['started'] ?? $now) > 28800) {
            $_SESSION=[]; session_regenerate_id(true);
        }
        $_SESSION['started'] ??= $now; $_SESSION['last_seen']=$now;
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }
    public static function csrf(mixed $token): void {
        if (!is_string($token) || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) throw new AuthError(403, 'This form expired. Reload your account page and try again.');
    }
    public static function login(array $user): void {
        session_regenerate_id(true);
        $_SESSION=['user_id'=>$user['id'], 'user_key'=>$user['session_key'], 'started'=>time(), 'last_seen'=>time(), 'csrf'=>bin2hex(random_bytes(32))];
    }
    public static function state(string $purpose, ?string $id): string {
        $state = bin2hex(random_bytes(32));
        // Only one outstanding flow. A new flow invalidates older tabs.
        $_SESSION['oauth']=['digest'=>hash('sha256',$state),'purpose'=>$purpose,'user_id'=>$id,'expires'=>time()+600];
        return $state;
    }
    public static function consumeState(mixed $state, string $purpose, ?string $id): void {
        $flow=$_SESSION['oauth'] ?? null; unset($_SESSION['oauth']);
        if (!is_array($flow) || !is_string($state) || strlen($state)!==64 || !hash_equals($flow['digest'],hash('sha256',$state))
            || $flow['purpose']!==$purpose || $flow['user_id']!==$id || $flow['expires']<time()) {
            throw new AuthError(403, 'The Discord sign-in or installation request expired. Start again from your account page.');
        }
    }
    public static function logout(): void {
        $_SESSION=[];
        setcookie(session_name(), '', ['expires'=>time()-3600, 'path'=>'/', 'secure'=>session_get_cookie_params()['secure'], 'httponly'=>true, 'samesite'=>'Lax']);
        session_destroy();
    }
}
