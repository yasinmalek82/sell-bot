<?php
require_once 'config.php';

class CurlRequest {
    private $url;
    private $headers = [];
    private $timeout = null;
    private $authToken = null;
    private $api_key = null;
    private $cookie = null;
    public function __construct($url) {
        global $request_exec_timeout;
        $this->url = $url;
        $this->timeout = $request_exec_timeout;
    }

    public function setTimeout($seconds) {
        $this->timeout = $seconds;
    }

    public function setHeaders(array $headers) {
        $this->headers = array_merge($this->headers, $headers);
    }

    public function setBearerToken($token) {
        $this->authToken = $token;
    }
    
    public function api_key($token) {
        $this->api_key = $token;
    }

    public function setCookie($cookieStr) {
        $this->cookie = $cookieStr;
    }

    private function prepareHeaders() {
        $headers = $this->headers;

        if ($this->authToken) {
            $headers[] = "Authorization: Bearer {$this->authToken}";
        }
        if ($this->api_key) {
            $headers[] = $this->api_key;
        }

        return $headers;
    }

    private function execute($method, $data = null) {
        $this->timeout = !$this->timeout  ?  10000 : $this->timeout;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, $this->timeout);
        $verifyTls = getenv('CURL_SSL_VERIFY') !== '0';
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifyTls);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verifyTls ? 2 : 0);

        // Optional verbose logging for debugging external panel requests.
        $enableVerbose = getenv('XUI_DEBUG') === '1';
        $verboseHandle = null;
        if ($enableVerbose) {
            $logDir = __DIR__ . '/logs';
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0755, true);
            }
            $verboseFile = $logDir . '/curl_verbose.log';
            $verboseHandle = fopen($verboseFile, 'a');
            if ($verboseHandle) {
                curl_setopt($ch, CURLOPT_VERBOSE, true);
                curl_setopt($ch, CURLOPT_STDERR, $verboseHandle);
                $entry = date('c') . " | CURL REQUEST: {$this->url} METHOD:" . strtoupper($method) . "\n";
                if (!empty($this->headers)) {
                    $safeHeaders = array_map(static function ($header) {
                        return stripos($header, 'Authorization:') === 0 ? 'Authorization: [REDACTED]' : $header;
                    }, $this->prepareHeaders());
                    $entry .= "HEADERS: " . json_encode($safeHeaders) . "\n";
                }
                if ($data && getenv('XUI_DEBUG_PAYLOAD') === '1') {
                    $entry .= "DATA: " . (is_string($data) ? $data : json_encode($data)) . "\n";
                }
                @fwrite($verboseHandle, $entry);
            }
        }

        $finalHeaders = $this->prepareHeaders();
        if (!empty($finalHeaders)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $finalHeaders);
        }
        if ($this->cookie) {
            curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookie);   
        }
        if ($data) {
            if (is_array($data)) {
                $data = http_build_query($data);
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }

        $response = curl_exec($ch);
        $curlErrNo = curl_errno($ch);
        $curlErr = $curlErrNo ? curl_error($ch) : null;
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlInfo = curl_getinfo($ch);

        if ($verboseHandle) {
            $entry = date('c') . " | CURL RESULT: HTTP={$httpCode} ERRNO={$curlErrNo} ERROR=" . ($curlErr ?? 'NULL') . " INFO=" . json_encode($curlInfo) . "\n\n";
            @fwrite($verboseHandle, $entry);
            @fclose($verboseHandle);
        }

        if ($curlErrNo) {
            return [
                'status' => $httpCode,
                'body' => $response,
                'error' => $curlErr,
                'curl_info' => $curlInfo
            ];
        }

        return [
            'status' => $httpCode,
            'body' => $response,
            'curl_info' => $curlInfo
        ];
    }

    public function get() {
        return $this->execute("GET");
    }

    public function post($data) {
        return $this->execute("POST", $data);
    }

    public function put($data) {
        return $this->execute("PUT", $data);
    }

    public function delete($data = null) {
        return $this->execute("DELETE", $data);
    }
    public function PATCH($data = null){
        return $this->execute('PATCH',$data);
    }
}
