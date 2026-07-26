<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/request.php';
ini_set('error_log', 'error_log');

function normalizeXuiIpLimit($ipLimit)
{
    return max(0, (int) $ipLimit);
}

function decodeXuiInboundIds($inboundIds)
{
    if (is_string($inboundIds)) {
        $inboundIds = json_decode($inboundIds, true);
    }
    if (!is_array($inboundIds)) {
        throw new InvalidArgumentException('3x-ui inbound IDs must be a JSON array.');
    }

    $normalized = [];
    foreach ($inboundIds as $inboundId) {
        $inboundId = (int) $inboundId;
        if ($inboundId > 0) {
            $normalized[] = $inboundId;
        }
    }
    $normalized = array_values(array_unique($normalized));
    if ($normalized === []) {
        throw new InvalidArgumentException('At least one valid 3x-ui inbound ID is required.');
    }
    return $normalized;
}

function buildXuiAddClientPayload($panel, $username, $expire, $subId, $total, $inboundIds, $productName, $note = '', $ipLimit = 0)
{
    $onHold = $productName === 'usertest'
        ? (($panel['on_hold_test'] ?? '0') === '1')
        : (($panel['conecton'] ?? '') === 'onconecton');

    if ((int) $expire === 0) {
        $expiryTime = 0;
    } elseif ($onHold) {
        $remainingMilliseconds = max(0, ((int) $expire - time()) * 1000);
        $expiryTime = -$remainingMilliseconds;
    } else {
        $expiryTime = (int) $expire * 1000;
    }

    return [
        'inboundIds' => decodeXuiInboundIds($inboundIds),
        'client' => [
            'email' => (string) $username,
            'totalGB' => max(0, (int) $total),
            'expiryTime' => $expiryTime,
            'tgId' => 0,
            'comment' => (string) $note,
            'enable' => true,
            'subId' => (string) $subId,
            // 0 means unlimited IPs in 3x-ui; traffic can independently be unlimited.
            'limitIp' => normalizeXuiIpLimit($ipLimit),
        ],
    ];
}

function buildXuiUpdateClientPayload(array $currentClient, array $changes, $fallbackEmail)
{
    $client = array_merge($currentClient, $changes);
    $client['email'] = (string) ($client['email'] ?? $fallbackEmail);
    $client['totalGB'] = max(0, (int) ($client['totalGB'] ?? 0));
    $client['expiryTime'] = (int) ($client['expiryTime'] ?? 0);
    $client['limitIp'] = normalizeXuiIpLimit($client['limitIp'] ?? 0);
    $client['enable'] = (bool) ($client['enable'] ?? true);
    unset($client['createdAt'], $client['updatedAt'], $client['created_at'], $client['updated_at']);
    return $client;
}

function get_clinets($username, $panel)
{
    $url = rtrim($panel['url_panel'], '/') . '/panel/api/clients/get/' . rawurlencode($username);
    $headers = array(
        'Accept: application/json',
        'Content-Type: application/json',
    );
    $req = new CurlRequest($url);
    $req->setHeaders($headers);
    $req->setBearerToken($panel['password_panel']);
    $response = $req->get();
    return $response;
}
function addClient($panel, $usernameac, $Expire, $subId, $Total, $inboundid, $name_product, $note = "", $ip_limit = 0)
{
    try {
        $config = buildXuiAddClientPayload($panel, $usernameac, $Expire, $subId, $Total, $inboundid, $name_product, $note, $ip_limit);
        $configpanel = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        return ['status' => 0, 'body' => '', 'error' => $e->getMessage()];
    }
    $url = rtrim($panel['url_panel'], '/') . '/panel/api/clients/add';
    if (getenv('XUI_DEBUG') === '1') {
        $logDir = __DIR__ . '/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0750, true);
        }
        @file_put_contents(
            $logDir . '/xui_payload.log',
            date('c') . " | add client payload\n" . $configpanel . "\n\n",
            FILE_APPEND | LOCK_EX
        );
    }
    $headers = array(
        'Accept: application/json',
        'Content-Type: application/json',
    );
    $req = new CurlRequest($url);
    $req->setHeaders($headers);
    $req->setBearerToken($panel['password_panel']);
    $response = $req->post($configpanel);
    return $response;
}
function updateClient($panel, $uuid, array $config)
{
    try {
        $configpanel = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        return ['status' => 0, 'body' => '', 'error' => $e->getMessage()];
    }
    $url = rtrim($panel['url_panel'], '/') . '/panel/api/clients/update/' . rawurlencode($uuid);
    $headers = array(
        'Accept: application/json',
        'Content-Type: application/json',
    );
    $req = new CurlRequest($url);
    $req->setHeaders($headers);
    $req->setBearerToken($panel['password_panel']);
    $response = $req->post($configpanel);
    return $response;
}
function ResetUserDataUsagex_uisin($usernamepanel, $panel)
{
    $url = rtrim($panel['url_panel'], '/') . '/panel/api/clients/resetTraffic/' . rawurlencode($usernamepanel);
    $headers = array(
        'Accept: application/json',
        'Content-Type: application/json',
    );
    $req = new CurlRequest($url);
    $req->setHeaders($headers);
    $req->setBearerToken($panel['password_panel']);
    $response = $req->post(array());
    return $response;
}
function removeClient($panel, $username)
{
    $url = rtrim($panel['url_panel'], '/') . '/panel/api/clients/del/' . rawurlencode($username);
    $headers = array(
        'Accept: application/json',
        'Content-Type: application/json',
    );
    $req = new CurlRequest($url);
    $req->setHeaders($headers);
    $req->setBearerToken($panel['password_panel']);
    $response = $req->post(array());
    return $response;
}
function status_server_xui($panel)
{
    $url = $panel['url_panel'] . "/panel/api/server/status";
    $headers = array(
        'Accept: application/json',
        'Content-Type: application/json',
    );
    $req = new CurlRequest($url);
    $req->setHeaders($headers);
    $req->setBearerToken($panel['password_panel']);
    $response = $req->get();
    return $response;
}
function attach_service($panel, $username, $configpanel)
{
    $url = rtrim($panel['url_panel'], '/') . '/panel/api/clients/' . rawurlencode($username) . '/attach';
    $headers = array(
        'Accept: application/json',
        'Content-Type: application/json',
    );
    $req = new CurlRequest($url);
    $req->setHeaders($headers);
    $req->setBearerToken($panel['password_panel']);
    try {
        $payload = ['inboundIds' => decodeXuiInboundIds($configpanel)];
        $payload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        return ['status' => 0, 'body' => '', 'error' => $e->getMessage()];
    }
    $response = $req->post($payload);
    return $response;
}

function used_data_3xui($panel, $username)
{
    $url = rtrim($panel['url_panel'], '/') . '/panel/api/clients/traffic/' . rawurlencode($username);
    $headers = array(
        'Accept: application/json',
        'Content-Type: application/json',
    );
    $req = new CurlRequest($url);
    $req->setHeaders($headers);
    $req->setBearerToken($panel['password_panel']);
    $response = $req->get();
    return $response;
}
