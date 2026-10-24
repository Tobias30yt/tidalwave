<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/src/Services/Auth/bootstrap.php';
$config=require dirname(__DIR__).'/config/auth.php';
try {
    auth_configure($config);
    $discord=new DiscordClient();
    $app=$discord->request('GET','/applications/@me','Bot '.$config['bot_token']);
    if (($app['id'] ?? null)!==$config['client_id']) throw new RuntimeException('Verification token belongs to a different application.');
    // POST creates or updates this named command only; never bulk-overwrite other commands.
    $discord->request('POST','/applications/'.$config['client_id'].'/guilds/'.$config['guild_id'].'/commands','Bot '.$config['bot_token'],[
        'name'=>'verify','description'=>'Verify your Discord account with the TidalWave website.','type'=>1,
        'options'=>[],
    ]);
    echo "Registered /verify in the configured verification server.\n";
} catch (Throwable $e) { fwrite(STDERR,'Registration failed: '.$e->getMessage()."\n"); exit(1); }
