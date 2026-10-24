<?php
declare(strict_types=1);
$defaults = [
    'enabled' => false,
    'origin' => '',
    'allow_local_http' => false,
    'app_key' => '',
    'client_id' => '',
    'client_secret' => '',
    'public_key' => '',
    'bot_token' => '',
    'guild_id' => '',
    'guild_invite_url' => null,
    'storage' => dirname(__DIR__).'/storage/auth',
    'verification_lifetime' => 30 * 86400,
    // Full bot credentials live here, never in public bot cards.
    'installations' => [],
];
$local = __DIR__.'/auth.local.php';
return is_file($local) ? array_replace($defaults, require $local) : $defaults;
