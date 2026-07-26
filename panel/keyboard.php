<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_auth();

$keyboard = json_decode(file_get_contents("php://input"), true);
$method = $_SERVER['REQUEST_METHOD'];
if ($method == "POST" && is_array($keyboard)) {
    // The editor posts JSON, so protect it with same-origin browser headers and
    // then strictly allow-list every persisted Telegram field.
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $expectedOrigin = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
        . '://' . ($_SERVER['HTTP_HOST'] ?? '');
    $fetchSite = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
    if (($origin !== '' && !hash_equals($expectedOrigin, $origin)) || ($fetchSite !== '' && $fetchSite !== 'same-origin')) {
        http_response_code(403);
        exit;
    }
    try {
        $keyboardmain = ['keyboard' => telegramKeyboardNormalizeRows($keyboard)];
        update("setting", "keyboardmain", json_encode($keyboardmain, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), null, null);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true]);
    } catch (InvalidArgumentException $e) {
        http_response_code(422);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
} else {
    $keyboardmain = telegramKeyboardDefaultJson();
    $action = filter_input(INPUT_GET, 'action');
    if ($action === "reaset") {
        csrf_check_get();
        update("setting", "keyboardmain", $keyboardmain, null, null);
        header('Location: keyboard.php');
        exit;
    }
}
?>

<!doctype html>
<html lang="FA">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?= $textbotlang['panel']['keyboardManageTitle'] ?></title>

    <script>window.__MIRZA_API_ORIGIN = <?= json_encode(rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/mirzaprobotconfig/panel/keyboard.php')), '/')) ?>;</script>
    <script type="module" crossorigin src="js/sort_keyboard.js"></script>
    <link rel="stylesheet" crossorigin href="css/sort_keyboard.css">
    <style>
        @import url(https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap);

        * {
            font-family: 'Vazirmatn' !important;
        }

        button {
            font-family: yekan;
        }

        .btnback {
            position: fixed;
            top: 10px;
            left: 10px;
            padding: 7px;
            background-color: #3d3d3d;
            color: #fff;
            border-radius: 6px;
            font-family: yekan;
            font-size: 13px;
            font-weight: bold;
        }

        .btndefult {
            position: fixed;
            top: 10px;
            left: 150px;
            padding: 7px;
            background-color: #fff;
            border: 2px solid #3d3d3d;
            color: #3d3d3d;
            border-radius: 6px;
            font-family: yekan;
            font-size: 13px;
            font-weight: bold;
        }
    </style>
</head>

<body>
    <a class="btnback" href="index.php"><?= $textbotlang['panel']['keyboardSortHint'] ?></a>
    <a class="btndefult" href="keyboard.php?action=reaset&amp;_csrf=<?= csrf_token() ?>" onclick="return confirm('چیدمان به حالت پیش‌فرض برگردد؟')"><?= $textbotlang['panel']['keyboardSaveBtn'] ?></a>
    <div id="root"></div>
</body>

</html>
