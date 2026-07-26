<?php
require_once __DIR__ . '/config.php';
function telegram($method, $datas = [], $token = null)
{
    global $APIKEY;

    $token = $token === null ? $APIKEY : $token;
    $url = "https://api.telegram.org/bot" . $token . "/" . $method;

    if (isset($datas['message_thread_id']) && intval($datas['message_thread_id']) <= 0) {
        unset($datas['message_thread_id']);
    }

    $ch = curl_init($url);
    if ($ch === false) {
        error_log('Unable to initialise cURL for Telegram request.');
        return [
            'ok' => false,
            'description' => 'Unable to initialise cURL for Telegram request.'
        ];
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $datas);

    $rawResponse = curl_exec($ch);
    if ($rawResponse === false) {
        $curlError = curl_error($ch);

        if ($curlError !== '') {
            error_log('Telegram request failed: ' . $curlError);
        }

        return [
            'ok' => false,
            'description' => $curlError !== '' ? $curlError : 'Telegram request failed.'
        ];
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $decodedResponse = json_decode($rawResponse, true);
    if (!is_array($decodedResponse)) {
        $logSnippet = substr($rawResponse, 0, 200);
        error_log(sprintf('Invalid response from Telegram API (HTTP %d): %s', $httpCode, $logSnippet));

        return [
            'ok' => false,
            'error_code' => $httpCode,
            'description' => 'Invalid response received from Telegram.'
        ];
    }

    if (isset($decodedResponse['ok']) && !$decodedResponse['ok']) {
        error_log(json_encode($decodedResponse));
    }

    if (getenv('TELEGRAM_DEBUG') === '1') {
        try {
            $debugLog = [
                'time' => date('c'),
                'method' => $method,
                'http_code' => $httpCode,
                'ok' => $decodedResponse['ok'] ?? null,
                'description' => $decodedResponse['description'] ?? null,
            ];
            file_put_contents(sys_get_temp_dir() . '/telegram_api.log', json_encode($debugLog, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (Throwable $e) {
            // Debug logging must never affect bot delivery.
        }
    }

    return $decodedResponse;
}
function sendmessage($chat_id,$text,$keyboard,$parse_mode,$bot_token = null){
    if(intval($chat_id) == 0)return ['ok' => false];
    if (getenv('TELEGRAM_DEBUG') === '1') {
        try {
            $call = [
                'time' => date('c'),
                'chat_id' => $chat_id,
                'text_length' => mb_strlen((string) $text),
                'parse_mode' => $parse_mode,
            ];
            file_put_contents(sys_get_temp_dir() . '/telegram_send_calls.log', json_encode($call, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (Throwable $e) {
            // Debug logging must never affect bot delivery.
        }
    }
    return telegram('sendmessage',[
        'chat_id' => $chat_id,
        'text' => $text,
        'reply_markup' => $keyboard,
        'parse_mode' => $parse_mode,
        
        ],$bot_token);
}
function sendDocument($chat_id, $documentPath, $caption) {
        return telegram('sendDocument',[
        'chat_id' => $chat_id,
        'document' => new CURLFile($documentPath),
        'caption' => $caption,
        ]);
}

function forwardMessage($chat_id,$message_id,$chat_id_user){
    return telegram('forwardMessage',[
        'from_chat_id'=> $chat_id,
        'message_id'=> $message_id,
        'chat_id'=> $chat_id_user,
    ]);
}
function sendphoto($chat_id,$photoid,$caption){
    telegram('sendphoto',[
        'chat_id' => $chat_id,
        'photo'=> $photoid,
        'caption'=> $caption,
    ]);
}
function sendvideo($chat_id,$videoid,$caption){
    telegram('sendvideo',[
        'chat_id' => $chat_id,
        'video'=> $videoid,
        'caption'=> $caption,
    ]);
}
function senddocumentsid($chat_id,$documentid,$caption){
    telegram('sendDocument',[
        'chat_id' => $chat_id,
        'document'=> $documentid,
        'caption'=> $caption,
    ]);
}
function Editmessagetext($chat_id, $message_id, $text, $keyboard,$parse_mode = 'HTML'){
    return telegram('editmessagetext', [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'text' => $text,
        'reply_markup' => $keyboard,
        'parse_mode' => $parse_mode,

    ]);
}
 function deletemessage($chat_id, $message_id){
  telegram('deletemessage', [
'chat_id' => $chat_id, 
'message_id' => $message_id,
]);
 }
function getFileddire($photoid){
  return telegram('getFile', [
'file_id' => $photoid, 
]);
 }
function pinmessage($from_id,$message_id){
  return telegram('pinChatMessage', [
'chat_id' => $from_id, 
'message_id' => $message_id, 
]);
 }
 function unpinmessage($from_id){
  return telegram('unpinAllChatMessages', [
'chat_id' => $from_id, 
]);
 }
  function answerInlineQuery($inline_query_id,$results){
  return telegram('answerInlineQuery', [
      "inline_query_id" => $inline_query_id,
        "results" => json_encode($results)
]);
 }
function convertPersianNumbersToEnglish($string) {
    $persian_numbers = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $english_numbers = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

    return str_replace($persian_numbers, $english_numbers, $string);
}

function isDuplicateUpdate($updateId)
{
    if (!is_numeric($updateId) || $updateId <= 0) {
        return false;
    }

    $cacheDir = __DIR__ . '/storage/cache';
    if (!is_dir($cacheDir) && !mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
        return false;
    }

    $cacheFile = $cacheDir . '/recent_updates.json';
    $handle = fopen($cacheFile, 'c+');
    if ($handle === false) {
        return false;
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            return false;
        }

        rewind($handle);
        $contents = stream_get_contents($handle);
        $recentUpdates = $contents ? json_decode($contents, true) : [];
        if (!is_array($recentUpdates)) {
            $recentUpdates = [];
        }

        $now = time();
        $timeToLive = 120; // seconds

        // Drop expired entries
        foreach ($recentUpdates as $id => $timestamp) {
            if (!is_numeric($timestamp) || ($now - (int)$timestamp) > $timeToLive) {
                unset($recentUpdates[$id]);
            }
        }

        if (array_key_exists($updateId, $recentUpdates)) {
            flock($handle, LOCK_UN);
            fclose($handle);
            return true;
        }

        $recentUpdates[$updateId] = $now;

        // keep size reasonable
        if (count($recentUpdates) > 200) {
            asort($recentUpdates);
            $recentUpdates = array_slice($recentUpdates, -200, null, true);
        }

        $encoded = json_encode($recentUpdates);
        if ($encoded !== false) {
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, $encoded);
        }

        flock($handle, LOCK_UN);
        fclose($handle);
    } catch (Throwable $e) {
        try {
            flock($handle, LOCK_UN);
        } catch (Throwable $ignored) {
        }
        fclose($handle);
        return false;
    }

    return false;
}
// #-----------------------------#
$update = json_decode(file_get_contents("php://input"), true);
// Never persist message bodies, payment payloads or managed-bot metadata by
// default. Debug logs contain identifiers only and must be explicitly enabled.
if (getenv('TELEGRAM_DEBUG') === '1') {
    try {
        $debugUpdate = [
            'time' => date('c'),
            'update_id' => $update['update_id'] ?? null,
            'kind' => isset($update['managed_bot']) ? 'managed_bot'
                : (isset($update['message']) ? 'message'
                    : (isset($update['callback_query']) ? 'callback_query' : 'other')),
        ];
        file_put_contents(
            sys_get_temp_dir() . '/telegram_incoming.log',
            json_encode($debugUpdate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    } catch (Throwable $e) {
        // Debug logging must never affect webhook processing.
    }
}
$update_id = $update['update_id'] ?? 0;
if (isDuplicateUpdate($update_id)) {
    http_response_code(200);
    exit;
}
$from_id = $update['message']['from']['id'] ?? $update['callback_query']['from']['id'] ?? $update["inline_query"]['from']['id'] ?? 0;
$time_message = $update['message']['date'] ?? $update['callback_query']['date'] ?? $update["inline_query"]['date'] ?? 0;
$is_bot = $update['message']['from']['is_bot'] ?? false;
$chat_member = $update['chat_member'] ?? null;
$language_code = strtolower($update['message']['from']['language_code'] ?? $update['callback_query']['from']['language_code'] ?? "fa");
$Chat_type = $update["message"]["chat"]["type"] ?? $update['callback_query']['message']['chat']['type'] ?? '';
$text = $update["message"]["text"]  ?? '';
if(isset($update['pre_checkout_query'])){
    $Chat_type = "private";
    $from_id = $update['pre_checkout_query']['from']['id'];
}
$text =convertPersianNumbersToEnglish($text);
$text_inline = $update["callback_query"]["message"]['text'] ?? '';
$message_id = $update["message"]["message_id"] ?? $update["callback_query"]["message"]["message_id"] ?? 0;
$time_message = $update["message"]["date"] ?? $update["callback_query"]["date"] ?? 0;
$photo = $update["message"]["photo"] ?? 0;
$document = $update["message"]["document"] ?? 0;
$fileid = $update["message"]["document"]["file_id"] ?? 0;
$photoid = $photo ? end($photo)["file_id"] : '';
$caption = $update["message"]["caption"] ?? '';
$video = $update["message"]["video"] ?? 0;
$videoid = $video ? $video["file_id"] : 0;
$forward_from_id = $update["message"]["reply_to_message"]["forward_from"]["id"] ?? 0;
$datain = $update["callback_query"]["data"] ?? '';
$last_name = $update['message']['from']['last_name']  ?? $update["callback_query"]["from"]["last_name"] ?? $update["inline_query"]['from']['last_name'] ?? '';
$first_name = $update['message']['from']['first_name']  ?? $update["callback_query"]["from"]["first_name"] ?? $update["inline_query"]['from']['first_name'] ?? '';
$username = $update['message']['from']['username'] ?? $update['callback_query']['from']['username'] ?? $update["callback_query"]["from"]["username"] ?? 'NOT_USERNAME';
$user_phone =$update["message"]["contact"]["phone_number"] ?? 0;
$contact_id = $update["message"]["contact"]["user_id"] ?? 0;
$callback_query_id = $update["callback_query"]["id"] ?? 0;
$inline_query_id = $update["inline_query"]["id"] ?? 0;
$query = $update["inline_query"]["query"] ?? 0;

// Simple test command for x-ui integration
try {
    $cmd = trim(explode(' ', $text)[0] ?? '');
    $cmdLower = strtolower($cmd);
    $isTestCmd = ($cmdLower === '/testxui' || $cmdLower === '/testxui@' . strtolower($usernamebot));
} catch (Throwable $e) {
    $isTestCmd = false;
}

if ($isTestCmd) {
    // parse simple args from text: inbounds=70,75 limit=2 days=30 email=alice@example.com note=hi real
    $argsText = trim(substr($text, strlen($cmd)) ?? '');
    $inbounds = [70];
    $limitIp = 0;
    $days = 30;
    $emailTest = 'bottest@example.com';
    $note = '';
    $doReal = false;

    // parse key=value pairs
    foreach (preg_split('/\s+/', $argsText) as $part) {
        if (stripos($part, 'inbounds=') === 0) {
            $vals = substr($part, strlen('inbounds='));
            $inbounds = array_map('intval', array_filter(array_map('trim', explode(',', $vals))));
        } elseif (stripos($part, 'limit=') === 0) {
            $limitIp = intval(substr($part, strlen('limit=')));
        } elseif (stripos($part, 'days=') === 0) {
            $days = intval(substr($part, strlen('days=')));
        } elseif (stripos($part, 'email=') === 0) {
            $emailTest = substr($part, strlen('email='));
        } elseif (stripos($part, 'note=') === 0) {
            $note = substr($part, strlen('note='));
        } elseif (strtolower($part) === 'real') {
            $doReal = true;
        }
    }

    $expiryTime = (time() + max(1, $days) * 86400) * 1000; // ms

    $payload = [
        'client' => [
            'email' => $emailTest,
            'totalGB' => 53687091200,
            'expiryTime' => $expiryTime,
            'tgId' => 0,
            'limitIp' => $limitIp,
            'enable' => true,
            'comment' => $note
        ],
        'inboundIds' => $inbounds
    ];

    $pretty = htmlspecialchars(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), ENT_QUOTES | ENT_SUBSTITUTE);
    $message = "<b>x-ui test payload (dry-run)</b>\n<pre>" . $pretty . "</pre>";

    // If admin requested real run, and env vars available, perform it
    if ($doReal && intval($from_id) === intval($adminnumber)) {
        $apiUrlEnv = getenv('XUI_API_URL');
        $tokenEnv = getenv('XUI_TOKEN');
        if (!empty($apiUrlEnv) && !empty($tokenEnv)) {
            $url = rtrim($apiUrlEnv, '\/');
            $jsonData = json_encode($payload, JSON_UNESCAPED_SLASHES);
            $ch2 = curl_init($url);
            curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch2, CURLOPT_HTTPHEADER, [
                'Accept: application/json',
                'Content-Type: application/json',
                'Authorization: Bearer ' . $tokenEnv
            ]);
            curl_setopt($ch2, CURLOPT_POST, true);
            curl_setopt($ch2, CURLOPT_POSTFIELDS, $jsonData);
            curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch2, CURLOPT_TIMEOUT, 30);
            $respBody = curl_exec($ch2);
            $httpCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
            curl_close($ch2);

            $message = "<b>x-ui test (real)</b>\nStatus: " . intval($httpCode) . "\n<pre>" . htmlspecialchars(substr($respBody ?? '', 0, 2000), ENT_QUOTES | ENT_SUBSTITUTE) . "</pre>";
        } else {
            $message = "<b>Real run requested</b> but XUI_API_URL or XUI_TOKEN environment variables are missing.\nSet them on the server or run as admin with env vars.\n(Example: export XUI_API_URL=\"https://host:2000/.../panel/api/clients/add\"; export XUI_TOKEN=\"token\")";
        }
    }

    sendmessage($from_id, $message, null, 'HTML');
    http_response_code(200);
    exit;
}
