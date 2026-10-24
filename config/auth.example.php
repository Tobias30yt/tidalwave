<?php
declare(strict_types=1);
// Copy to auth.local.php (gitignored); see docs/AUTHENTICATION.md.
return [
    'enabled' => false, // Enable only after completing all fields and setup.
    'origin' => 'https://YOUR_DOMAIN', // No trailing slash or path.
    'app_key' => '', // Generate: php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
    // One dedicated Discord application for website login + verification bot.
    'client_id' => '',
    'client_secret' => '',
    'public_key' => '', // General Information → Public Key, 64 hex characters.
    'bot_token' => '',
    'guild_id' => '', // Your verification server ID.
    'guild_invite_url' => null, // Your real https://discord.gg/... invitation.
    'installations' => [
        'synatra' => [
            'enabled' => false,
            'client_id' => '',
            'client_secret' => '',
            'bot_token' => '',
            'permissions' => '0', // Replace with only permissions Synatra needs.
        ],
    ],
];
