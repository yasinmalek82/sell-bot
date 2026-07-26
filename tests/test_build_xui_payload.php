<?php
putenv('SKIP_DB=1');
require_once __DIR__ . '/../x-ui_single.php';

function assertSameValue($expected, $actual, $message)
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$panel = [
    'on_hold_test' => '0',
    'conecton' => '',
];

// Unlimited traffic/time with a two-IP restriction is the main regression case.
$payload = buildXuiAddClientPayload(
    $panel,
    'unlimited_user',
    0,
    'sub123',
    0,
    '[3, 5, 5]',
    'unlimited_product',
    'test note',
    2
);
assertSameValue(0, $payload['client']['totalGB'], 'Unlimited traffic must remain 0.');
assertSameValue(0, $payload['client']['expiryTime'], 'Unlimited time must remain 0.');
assertSameValue(2, $payload['client']['limitIp'], 'IP limit must be independent from traffic/time limits.');
assertSameValue([3, 5], $payload['inboundIds'], 'Inbound IDs must be normalized and de-duplicated.');

$updated = buildXuiUpdateClientPayload([
    'email' => 'unlimited_user',
    'subId' => 'must-survive',
    'limitIp' => 2,
    'totalGB' => 0,
    'expiryTime' => 0,
    'enable' => true,
    'comment' => 'must-survive',
    'createdAt' => 123,
], ['enable' => false], 'unlimited_user');
assertSameValue('must-survive', $updated['subId'], 'Update must preserve the subscription ID.');
assertSameValue(2, $updated['limitIp'], 'Update must preserve the IP limit.');
assertSameValue('must-survive', $updated['comment'], 'Update must preserve untouched fields.');
assertSameValue(false, $updated['enable'], 'Requested update must override the current value.');
assertSameValue(false, array_key_exists('createdAt', $updated), 'Read-only timestamps must not be sent back.');

$payload['client']['limitIp'] = normalizeXuiIpLimit(-4);
assertSameValue(0, $payload['client']['limitIp'], 'Negative IP limits must normalize to unlimited (0).');

$onHoldPanel = ['conecton' => 'onconecton'];
$onHold = buildXuiAddClientPayload($onHoldPanel, 'held_user', time() + 86400, 'sub456', 0, [3], 'held', '', 1);
if ($onHold['client']['expiryTime'] >= 0) {
    fwrite(STDERR, "FAIL: On-hold expiry must be a negative duration.\n");
    exit(1);
}

try {
    decodeXuiInboundIds('not-json');
    fwrite(STDERR, "FAIL: Invalid inbound JSON must be rejected.\n");
    exit(1);
} catch (InvalidArgumentException $e) {
    // Expected.
}

echo "OK: 3x-ui payload tests passed.\n";
