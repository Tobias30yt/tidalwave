<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/src/Services/Auth/bootstrap.php';
$config=require dirname(__DIR__).'/config/auth.php';
try {
    $command=$argv[1] ?? '';
    if (!in_array($command,['init','cleanup','revoke','block','unblock'],true)) throw new RuntimeException('Usage: php bin/auth-admin.php init|cleanup|revoke ID|block ID|unblock ID');
    auth_configure($config);
    $store=new AuthStore($config['storage'],$config['app_key']);
    if ($command==='init') { echo "Account database initialized.\n"; exit; }
    if ($command==='cleanup') {
        $store->cleanup();
        foreach (glob($config['storage'].'/sessions/sess_*') ?: [] as $file) {
            // Only expired files within the configured private session directory.
            if (is_file($file) && filemtime($file)<time()-28800) unlink($file);
        }
        echo "Expired challenges, limits, interaction receipts and sessions removed.\n"; exit;
    }
    $id=$argv[2] ?? '';
    if (!discord_id($id)) throw new RuntimeException('A valid Discord user ID is required.');
    $blocked=$command==='block' || ($command==='revoke' && (bool)$store->query('SELECT 1 FROM blocked_accounts WHERE id=?',[$id])->fetchColumn());
    $store->revoke($id,$blocked);
    echo $blocked ? "Account blocked and verification revoked.\n" : "Verification reset; a fresh verification is required.\n";
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage()."\n"); exit(1); }
