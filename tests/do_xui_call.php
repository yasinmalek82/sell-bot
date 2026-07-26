<?php
// Explicit integration test. It never contacts a panel unless DO_REAL=1.
if (getenv('DO_REAL') !== '1') {
    fwrite(STDERR, "Set DO_REAL=1, XUI_ENDPOINT and XUI_TOKEN to run this integration test.\n");
    exit(2);
}

$endpoint = rtrim((string) getenv('XUI_ENDPOINT'), '/');
$token = (string) getenv('XUI_TOKEN');
if ($endpoint === '' || $token === '') {
    fwrite(STDERR, "XUI_ENDPOINT and XUI_TOKEN are required.\n");
    exit(2);
}

putenv('SKIP_DB=1');
require_once __DIR__ . '/../x-ui_single.php';

$panel = [
    'url_panel' => $endpoint,
    'password_panel' => $token,
    'inbounds' => getenv('XUI_INBOUNDS') ?: '[1]',
    'on_hold_test' => '0',
    'conecton' => '',
];

$email = getenv('XUI_TEST_EMAIL') ?: ('mirzabot-test-' . time());
$response = addClient(
    $panel,
    $email,
    time() + 86400,
    bin2hex(random_bytes(8)),
    0,
    $panel['inbounds'],
    'integration-test',
    'Mirza Bot integration test',
    (int) (getenv('XUI_TEST_IP_LIMIT') ?: 1)
);

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit(($response['status'] ?? 0) === 200 ? 0 : 1);
