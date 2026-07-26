<?php

if (!defined('MIRZA_VERIFIED_TELEGRAM_WEBHOOK') || empty($resellerBotRow['id_user']) || empty($resellerBotRow['username'])) {
    http_response_code(403);
    exit;
}

$runtimeName = preg_replace(
    '/[^A-Za-z0-9_-]/',
    '',
    (string) $resellerBotRow['id_user'] . (string) $resellerBotRow['username']
);
$runtimeDir = __DIR__ . '/vpnbot/' . $runtimeName;
$entrypoint = $runtimeDir . '/index.php';
if (!is_file($entrypoint)) {
    error_log('Reseller runtime entrypoint missing: ' . $runtimeName);
    http_response_code(503);
    exit;
}

$previousDirectory = getcwd();
chdir($runtimeDir);
$ApiToken = (string) $resellerBotRow['bot_token'];
try {
    require $entrypoint;
} finally {
    if (is_string($previousDirectory)) {
        chdir($previousDirectory);
    }
}
