<?php

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/function.php';

if (!($pdo instanceof PDO)) {
    fwrite(STDERR, "Database unavailable.\n");
    exit(1);
}
$ownerId = (string) ($argv[1] ?? '');
if (!preg_match('/^[0-9]{4,30}$/', $ownerId)) {
    fwrite(STDERR, "Usage: php scripts/provision_reseller_bot.php <owner-id>\n");
    exit(1);
}

try {
    $result = (new ResellerBotService($pdo, dirname(__DIR__), $domainhosts))->provisionExisting($ownerId);
    fwrite(STDOUT, json_encode([
        'ok' => true,
        'owner_id' => $result['owner_id'],
        'username' => $result['username'],
        'webhook_url' => $result['webhook_url'],
        'runtime' => $result['runtime'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
} catch (Throwable $e) {
    fwrite(STDERR, "Provisioning failed: {$e->getMessage()}\n");
    exit(1);
}
