<?php
// Build and print an x-ui add-client payload matching the documentation example.
// Edit variables below as needed, then run:
// php mirzaprobotconfig/tests/test_xui_payload_match_docs.php

$client = [
    "email" => "alice@example.com",
    "totalGB" => 53687091200,
    "expiryTime" => 1735689600000,
    "tgId" => 0,
    "limitIp" => 0,
    "enable" => true
];
$inboundIds = [3, 5];

$payload = [
    'client' => $client,
    'inboundIds' => $inboundIds
];

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

// Also print a curl command you can run (replace <TOKEN> and endpoint as needed):
$curl = "curl -X POST \"https://docs.sanaei.dev/panel/api/clients/add\" \\\n  -H \"Authorization: Bearer <TOKEN>\" \\\n  -H \"Content-Type: application/json\" \\\n  -d '" . json_encode($payload, JSON_UNESCAPED_SLASHES) . "'";

echo "\n# Example curl:\n" . $curl . "\n";
