<?php

final class ResellerBotService
{
    private PDO $pdo;
    private string $projectDir;
    private string $domain;

    public function __construct(PDO $pdo, string $projectDir, string $domain)
    {
        $this->pdo = $pdo;
        $this->projectDir = rtrim($projectDir, DIRECTORY_SEPARATOR);
        $this->domain = preg_replace('~^https?://~i', '', rtrim(trim($domain), '/'));
    }

    public function provisionExisting(string $ownerId, string $source = 'legacy'): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM botsaz WHERE id_user=:owner LIMIT 1');
        $stmt->execute([':owner' => $ownerId]);
        $bot = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$bot || empty($bot['bot_token'])) {
            throw new RuntimeException('Reseller bot record or token is missing.');
        }
        return $this->provision($ownerId, (string) $bot['bot_token'], $source, $bot);
    }

    public function provision(string $ownerId, string $token, string $source = 'legacy', ?array $existing = null): array
    {
        if ($this->domain === '') {
            throw new RuntimeException('BOT_DOMAIN is required.');
        }
        $info = $this->botApi($token, 'getMe');
        if (empty($info['ok']) || empty($info['result']['username'])) {
            throw new RuntimeException($info['description'] ?? 'Invalid reseller bot token.');
        }
        $username = (string) $info['result']['username'];
        $runtimeName = $this->runtimeName($ownerId, $username);
        $runtimeDir = $this->projectDir . '/vpnbot/' . $runtimeName;
        $templateDir = $this->projectDir . '/vpnbot/Default';
        if (!is_dir($runtimeDir)) {
            if (!function_exists('copyDirectoryContents') || !copyDirectoryContents($templateDir, $runtimeDir)) {
                throw new RuntimeException('Could not create reseller bot runtime.');
            }
        } else {
            // Upgrade executable files while preserving each seller's token,
            // button text, product overrides and local customer data.
            foreach (['index.php', 'admin.php', 'keyboard.php', 'botapi.php', 'func.php', 'version'] as $runtimeFile) {
                $sourceFile = $templateDir . '/' . $runtimeFile;
                if (is_file($sourceFile) && !copy($sourceFile, $runtimeDir . '/' . $runtimeFile)) {
                    throw new RuntimeException('Could not upgrade reseller runtime: ' . $runtimeFile);
                }
            }
        }
        $configPath = $runtimeDir . '/config.php';
        $config = is_file($configPath) ? file_get_contents($configPath) : false;
        if (!is_string($config)) {
            throw new RuntimeException('Reseller bot runtime config is missing.');
        }
        if (str_contains($config, 'BotTokenNew')) {
            $config = str_replace('BotTokenNew', addslashes($token), $config);
            if (file_put_contents($configPath, $config, LOCK_EX) === false) {
                throw new RuntimeException('Could not configure reseller bot runtime.');
            }
        }

        $webhookKey = (string) ($existing['webhook_key'] ?? '');
        $webhookSecret = (string) ($existing['webhook_secret'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $webhookKey)) {
            $webhookKey = bin2hex(random_bytes(16));
        }
        if (!preg_match('/^[A-Za-z0-9_-]{32,128}$/', $webhookSecret)) {
            $webhookSecret = bin2hex(random_bytes(32));
        }

        $settings = json_decode((string) ($existing['setting'] ?? '{}'), true);
        if (!is_array($settings)) {
            $settings = [];
        }
        $settings += [
            'minpricetime' => 4000,
            'pricetime' => 4000,
            'minpricevolume' => 4000,
            'pricevolume' => 4000,
            'support_username' => '',
            'Channel_Report' => 0,
            'cart_info' => '',
            'show_product' => true,
            'store_name' => 'فروشگاه ' . $username,
            'welcome_text' => "سلام {name} عزیز 👋\nبه {store} خوش آمدید.\nبرای ادامه یکی از گزینه‌ها را انتخاب کنید:",
            'about_text' => 'ارائه سرویس‌های اینترنت و اشتراک با پشتیبانی مستقیم فروشگاه.',
        ];

        $adminIds = (string) ($existing['admin_ids'] ?? json_encode([$ownerId]));
        $persistValues = [
            ':owner' => $ownerId,
            ':token' => $token,
            ':admins' => $adminIds,
            ':username' => $username,
            ':time' => date('Y/m/d H:i:s'),
            ':setting' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':source' => $source,
            ':webhook_key' => $webhookKey,
            ':webhook_secret' => $webhookSecret,
        ];
        if ($existing) {
            $stmt = $this->pdo->prepare(
                "UPDATE botsaz SET bot_token=:token,admin_ids=:admins,username=:username,time=:time,
                    setting=:setting,provision_source=:source,provision_status='provisioning',
                    last_provision_error=NULL,webhook_key=:webhook_key,webhook_secret=:webhook_secret
                 WHERE id_user=:owner"
            );
        } else {
            $stmt = $this->pdo->prepare(
                "INSERT INTO botsaz
                    (id_user,bot_token,admin_ids,username,time,setting,hide_panel,provision_source,
                     provision_status,last_provision_error,webhook_key,webhook_secret)
                 VALUES (:owner,:token,:admins,:username,:time,:setting,'{}',:source,'provisioning',NULL,:webhook_key,:webhook_secret)"
            );
        }
        $stmt->execute($persistValues);

        try {
            $webhookUrl = 'https://' . $this->domain . '/telegram/reseller/' . $webhookKey;
            $response = $this->botApi($token, 'setWebhook', [
                'url' => $webhookUrl,
                'secret_token' => $webhookSecret,
                'allowed_updates' => json_encode(['message', 'callback_query', 'pre_checkout_query']),
                'drop_pending_updates' => false,
            ]);
            if (empty($response['ok'])) {
                throw new RuntimeException($response['description'] ?? 'Could not register reseller webhook.');
            }
            $this->botApi($token, 'setMyCommands', [
                'commands' => json_encode([
                    ['command' => 'start', 'description' => 'شروع و نمایش فروشگاه'],
                    ['command' => 'panel', 'description' => 'مدیریت و شخصی‌سازی فروشگاه'],
                ], JSON_UNESCAPED_UNICODE),
            ]);
            $done = $this->pdo->prepare("UPDATE botsaz SET provision_status='active',last_provision_error=NULL WHERE id_user=:owner");
            $done->execute([':owner' => $ownerId]);
            return ['owner_id' => $ownerId, 'username' => $username, 'webhook_url' => $webhookUrl, 'runtime' => $runtimeName];
        } catch (Throwable $e) {
            $failed = $this->pdo->prepare("UPDATE botsaz SET provision_status='failed',last_provision_error=:error WHERE id_user=:owner");
            $failed->execute([':error' => mb_substr($e->getMessage(), 0, 2000), ':owner' => $ownerId]);
            throw $e;
        }
    }

    public static function runtimeName(string $ownerId, string $username): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '', $ownerId . $username);
    }

    private function botApi(string $token, string $method, array $data = []): array
    {
        $ch = curl_init('https://api.telegram.org/bot' . $token . '/' . $method);
        if ($ch === false) {
            throw new RuntimeException('Could not initialise Telegram request.');
        }
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15]);
        $raw = curl_exec($ch);
        if (!is_string($raw)) {
            throw new RuntimeException(curl_error($ch) ?: 'Telegram request failed.');
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : ['ok' => false, 'description' => 'Invalid Telegram response.'];
    }
}
