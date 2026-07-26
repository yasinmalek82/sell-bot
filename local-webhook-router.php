<?php
// Local Telegram-only gateway. Never serves the admin panel or project files.
$config = require __DIR__ . '/config.local.php';
$expectedSecret = (string) ($config['webhook_secret'] ?? '');
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$requestSecret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not Found';
    exit;
}

if ($requestPath === '/telegram/webhook') {
    if ($expectedSecret === '' || !hash_equals($expectedSecret, $requestSecret)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Forbidden';
        exit;
    }
    define('MIRZA_VERIFIED_TELEGRAM_WEBHOOK', true);
    require __DIR__ . '/index.php';
    exit;
}

if (!preg_match('~^/telegram/reseller/([a-f0-9]{32})$~', $requestPath, $matches)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not Found';
    exit;
}

require __DIR__ . '/config.php';
$resellerLookup = $pdo->prepare("SELECT * FROM botsaz WHERE webhook_key=:webhook_key AND provision_status='active' LIMIT 1");
$resellerLookup->execute([':webhook_key' => $matches[1]]);
$resellerBotRow = $resellerLookup->fetch(PDO::FETCH_ASSOC);
if (!$resellerBotRow || empty($resellerBotRow['webhook_secret']) || !hash_equals((string) $resellerBotRow['webhook_secret'], $requestSecret)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden';
    exit;
}

define('MIRZA_VERIFIED_TELEGRAM_WEBHOOK', true);
require __DIR__ . '/reseller-webhook.php';
