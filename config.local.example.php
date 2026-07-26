<?php
// Copy to config.local.php and keep that file private, or set equivalent env vars.
return [
    'db_host' => '127.0.0.1',
    'db_name' => 'mirzaprobot',
    'db_user' => 'mirzaprobot',
    'db_pass' => 'change-me',
    'bot_token' => '123456789:replace-with-a-new-token',
    'admin_id' => '123456789',
    'domain' => 'bot.example.com',
    'bot_username' => 'example_bot',
    // Generate with: openssl rand -hex 32
    'webhook_secret' => 'replace-with-a-random-64-character-secret',
    // Recommended on servers: use /etc/cron.d/mirza instead of web-triggered cron setup.
    'disable_self_cron' => true,
];
