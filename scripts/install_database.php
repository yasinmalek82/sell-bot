<?php

declare(strict_types=1);

$root = dirname(__DIR__);
chdir($root);
putenv('SKIP_TELEGRAM_WEBHOOK=1');

require $root . '/table.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "Database connection is unavailable.\n");
    exit(1);
}

$migrations = [
    '20260726_premium_resellers.sql',
    '20260726_premium_ledger.sql',
    '20260726_reseller_sales_plans.sql',
    '20260726_reseller_webhooks.sql',
    '20260726_unified_reseller_menu.sql',
];

foreach ($migrations as $migration) {
    $argv = [__FILE__, $migration];
    require $root . '/migrations/run.php';
}

$panelPassword = (string) getenv('PANEL_ADMIN_PASSWORD');
if ($panelPassword === '' || strlen($panelPassword) < 12) {
    fwrite(STDERR, "PANEL_ADMIN_PASSWORD must contain at least 12 characters.\n");
    exit(1);
}

$adminId = (string) ($adminnumber ?? '');
if (!preg_match('/^[0-9]{5,20}$/', $adminId)) {
    fwrite(STDERR, "BOT_ADMIN_ID is invalid.\n");
    exit(1);
}

$stmt = $pdo->prepare(
    "INSERT INTO admin (id_admin, username, password, rule)
     VALUES (:id, 'admin', :password, 'administrator')
     ON DUPLICATE KEY UPDATE username='admin', password=VALUES(password), rule='administrator'"
);
$stmt->execute([
    ':id' => $adminId,
    ':password' => password_hash($panelPassword, PASSWORD_BCRYPT, ['cost' => 12]),
]);

fwrite(STDOUT, "Database installation completed.\n");
