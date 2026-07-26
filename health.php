<?php

require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$healthy = $pdo instanceof PDO && $APIKEY !== '' && $domainhosts !== '';
http_response_code($healthy ? 200 : 503);
echo json_encode([
    'ok' => $healthy,
    'service' => 'mirza-bot',
    'version' => trim((string) @file_get_contents(__DIR__ . '/version')),
], JSON_UNESCAPED_SLASHES);
