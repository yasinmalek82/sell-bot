<?php
// This variable added for high load panels which their response time is long and bot can't communicate with online panel!
// null for default settings
$request_exec_timeout = null;
$localConfigFile = __DIR__ . '/config.local.php';
$localConfig = is_file($localConfigFile) ? require $localConfigFile : [];
if (!is_array($localConfig)) {
    $localConfig = [];
}

$dbhost = getenv('DB_HOST') ?: ($localConfig['db_host'] ?? '127.0.0.1');
$dbname = getenv('DB_NAME') ?: ($localConfig['db_name'] ?? 'mirzaprobot');
$usernamedb = getenv('DB_USER') ?: ($localConfig['db_user'] ?? 'mirzaprobot');
$passworddb = getenv('DB_PASS') ?: ($localConfig['db_pass'] ?? '');
$skipDb = getenv('SKIP_DB') === '1';
$connect = null;
$pdo = null;
if (!$skipDb) {
    mysqli_report(MYSQLI_REPORT_OFF);
    try {
        $connect = mysqli_connect($dbhost, $usernamedb, $passworddb, $dbname);
    } catch (mysqli_sql_exception $e) {
        error_log('Database connection failed: ' . $e->getMessage());
        $connect = null;
    }
    if ($connect === false || ($connect instanceof mysqli && $connect->connect_error)) {
        error_log('Database connection failed: ' . mysqli_connect_error());
        $connect = null;
    } else {
        mysqli_set_charset($connect, 'utf8mb4');
        $options = [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, ];
        $dsn = "mysql:host=$dbhost;dbname=$dbname;charset=utf8mb4";
        try {
            $pdo = new PDO($dsn, $usernamedb, $passworddb, $options);
        } catch (\PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            $pdo = null;
        }
    }
}
$APIKEY = getenv('BOT_TOKEN') ?: getenv('APIKEY') ?: ($localConfig['bot_token'] ?? '');
$adminnumber = getenv('BOT_ADMIN_ID') ?: ($localConfig['admin_id'] ?? '');
$domainhosts = getenv('BOT_DOMAIN') ?: ($localConfig['domain'] ?? '');
$usernamebot = getenv('BOT_USERNAME') ?: ($localConfig['bot_username'] ?? '');
$telegramWebhookSecret = getenv('TELEGRAM_WEBHOOK_SECRET') ?: ($localConfig['webhook_secret'] ?? '');
$disableSelfCron = getenv('MIRZA_DISABLE_SELF_CRON') === '1'
    || !empty($localConfig['disable_self_cron']);
?>
