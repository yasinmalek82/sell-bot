<?php
// run_xui_integration.php
// Usage examples:
// Dry-run (no network):
//   php mirzaprobotconfig/tests/run_xui_integration.php --email=alice@example.com --inbounds=3,5 --limit=0
// Real run (use environment variables, DO_REAL=1 to send):
//   export XUI_ENDPOINT="https://docs.sanaei.dev"
//   export XUI_TOKEN="<YOUR_TOKEN>"
//   export DO_REAL=1
//   php mirzaprobotconfig/tests/run_xui_integration.php --email=alice@example.com --inbounds=3,5 --limit=0

$opts = getopt('', ['email::','totalGB::','expiryTime::','inbounds::','limit::','note::']);

$email = $opts['email'] ?? 'alice@example.com';
$totalGB = isset($opts['totalGB']) ? intval($opts['totalGB']) : 53687091200;
$expiryTime = isset($opts['expiryTime']) ? intval($opts['expiryTime']) : 1735689600000;
$inbounds = isset($opts['inbounds']) ? array_map('intval', explode(',', $opts['inbounds'])) : [3,5];
$limitIp = isset($opts['limit']) ? intval($opts['limit']) : 0;
$note = $opts['note'] ?? '';

$payload = [
    'client' => [
        'email' => $email,
        'totalGB' => $totalGB,
        'expiryTime' => $expiryTime,
        'tgId' => 0,
        'limitIp' => $limitIp,
        'enable' => true,
        'comment' => $note
    ],
    'inboundIds' => $inbounds
];

echo "--- Payload (JSON) ---\n";
echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";

// Prepare example curl (safe to run locally with env token)
$endpoint = getenv('XUI_ENDPOINT') ?: '<XUI_ENDPOINT>'; 
$token = getenv('XUI_TOKEN') ?: '<XUI_BEARER_TOKEN>';

$curlExample = "curl -X POST \"{$endpoint}/panel/api/clients/add\" \\\n  -H \"Authorization: Bearer {$token}\" \\\n  -H \"Content-Type: application/json\" \\\n  -d '" . json_encode($payload, JSON_UNESCAPED_SLASHES) . "'";

echo "# Example curl (replace token/endpoint or set env vars XUI_ENDPOINT and XUI_TOKEN):\n" . $curlExample . "\n\n";

$doReal = getenv('DO_REAL') === '1' || getenv('DO_REAL') === 'true';
if (!$doReal) {
    echo "Dry-run mode (no network). To perform real request, set DO_REAL=1 and provide XUI_ENDPOINT and XUI_TOKEN environment variables.\n";
    exit(0);
}

// Real request path: use native PHP cURL here to avoid requiring project config (and DB connection).
$endpoint = rtrim(getenv('XUI_ENDPOINT'), '/');
$token = getenv('XUI_TOKEN');
if (empty($endpoint) || empty($token)) {
    echo "XUI_ENDPOINT and XUI_TOKEN must be set for DO_REAL=1. Aborting.\n";
    exit(1);
}

$apiUrlEnv = getenv('XUI_API_URL');
if (!empty($apiUrlEnv)) {
    // If full API URL provided, use it (allows custom port/path)
    $url = rtrim($apiUrlEnv, '\/');
} else {
    $url = $endpoint . '/panel/api/clients/add';
}
$jsonData = json_encode($payload, JSON_UNESCAPED_SLASHES);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/json',
    'Content-Type: application/json',
    'Authorization: Bearer ' . $token
]);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
$verifyTls = getenv('CURL_SSL_VERIFY') !== '0';
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifyTls);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verifyTls ? 2 : 0);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$responseBody = curl_exec($ch);
if ($responseBody === false) {
    $err = curl_error($ch);
    curl_close($ch);
    echo "cURL error: " . $err . "\n";
    exit(1);
}
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$response = [
    'status' => $httpCode,
    'body' => $responseBody
];

echo "--- Request Result ---\n";
print_r($response);
