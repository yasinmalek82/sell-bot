<?php

final class ResellerService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function activate(string $userId, string $tierCode = 'reseller'): array
    {
        $tierStmt = $this->pdo->prepare(
            'SELECT * FROM reseller_tiers WHERE code = :code AND is_active = 1 LIMIT 1'
        );
        $tierStmt->execute([':code' => $tierCode]);
        $tier = $tierStmt->fetch(PDO::FETCH_ASSOC);
        if (!$tier) {
            throw new RuntimeException('Reseller tier is not configured.');
        }

        $userStmt = $this->pdo->prepare('SELECT id, username FROM user WHERE id = :id LIMIT 1');
        $userStmt->execute([':id' => $userId]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            throw new RuntimeException('User was not found.');
        }

        $this->pdo->beginTransaction();
        try {
            $profileStmt = $this->pdo->prepare(
                "INSERT INTO reseller_profiles
                    (user_id, tier_id, status, approved_at, settings)
                 VALUES (:user_id, :tier_id, 'active', NOW(), JSON_OBJECT())
                 ON DUPLICATE KEY UPDATE
                    tier_id = VALUES(tier_id),
                    status = 'active',
                    approved_at = COALESCE(approved_at, NOW()),
                    updated_at = CURRENT_TIMESTAMP"
            );
            $profileStmt->execute([
                ':user_id' => $userId,
                ':tier_id' => (int) $tier['id'],
            ]);

            $updateUser = $this->pdo->prepare(
                'UPDATE user SET agent = :legacy_agent, reseller_tier_id = :tier_id WHERE id = :user_id'
            );
            $updateUser->execute([
                ':legacy_agent' => $tier['legacy_agent'] ?: 'n',
                ':tier_id' => (int) $tier['id'],
                ':user_id' => $userId,
            ]);

            $requestStmt = $this->pdo->prepare(
                "INSERT INTO Requestagent (id, username, time, Description, status, type)
                 VALUES (:id, :username, :time, :description, 'accept', :type)
                 ON DUPLICATE KEY UPDATE status = 'accept', type = VALUES(type)"
            );
            $requestStmt->execute([
                ':id' => $userId,
                ':username' => $user['username'] ?: 'NOT_USERNAME',
                ':time' => (string) time(),
                ':description' => 'Premium self-service onboarding',
                ':type' => $tier['legacy_agent'] ?: 'n',
            ]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $tier;
    }

    public function availablePlans(): array
    {
        $stmt = $this->pdo->query(
            "SELECT * FROM reseller_tiers
             WHERE is_active = 1 AND legacy_agent IN ('n','n2')
             ORDER BY sort_order, id"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function subscribe(string $userId, string $tierCode): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM reseller_tiers
             WHERE code = :code AND is_active = 1 AND legacy_agent IN ('n','n2') LIMIT 1"
        );
        $stmt->execute([':code' => $tierCode]);
        $tier = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$tier) {
            throw new RuntimeException('Reseller plan is not available.');
        }

        if (empty($tier['auto_approve'])) {
            $userStmt = $this->pdo->prepare('SELECT username FROM user WHERE id = :id LIMIT 1');
            $userStmt->execute([':id' => $userId]);
            $username = $userStmt->fetchColumn();
            if ($username === false) {
                throw new RuntimeException('User was not found.');
            }
            $this->pdo->beginTransaction();
            try {
                $pending = $this->pdo->prepare(
                    "INSERT INTO reseller_profiles (user_id, tier_id, status, settings)
                     VALUES (:user_id, :tier_id, 'pending', JSON_OBJECT())
                     ON DUPLICATE KEY UPDATE tier_id=VALUES(tier_id), status='pending', updated_at=CURRENT_TIMESTAMP"
                );
                $pending->execute([':user_id' => $userId, ':tier_id' => (int) $tier['id']]);
                $request = $this->pdo->prepare(
                    "INSERT INTO Requestagent (id, username, time, Description, status, type)
                     VALUES (:id, :username, :time, :description, 'waiting', :type)
                     ON DUPLICATE KEY UPDATE status='waiting', type=VALUES(type), Description=VALUES(Description)"
                );
                $request->execute([
                    ':id' => $userId,
                    ':username' => $username ?: 'NOT_USERNAME',
                    ':time' => (string) time(),
                    ':description' => 'Premium plan approval: ' . $tierCode,
                    ':type' => $tier['legacy_agent'],
                ]);
                $this->pdo->commit();
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            }
            $tier['subscription_status'] = 'pending';
            return $tier;
        }

        $fee = max(0, (int) ($tier['signup_fee'] ?? 0));
        $reference = 'reseller-plan-' . $userId . '-' . $tier['id'] . '-' . date('Ym');
        if ($fee > 0) {
            $wallet = new WalletService($this->pdo);
            $wallet->debitPurchase($userId, $fee, $reference, [
                'kind' => 'reseller_subscription',
                'tier_code' => $tierCode,
            ]);
        }

        try {
            $activated = $this->activate($userId, $tierCode);
            $days = min(3650, max(0, (int) ($tier['duration_days'] ?? 0)));
            if ($days > 0) {
                $expiry = $this->pdo->prepare(
                    'UPDATE reseller_profiles SET expires_at = :expires_at WHERE user_id = :user_id'
                );
                $expiry->bindValue(':expires_at', date('Y-m-d H:i:s', time() + ($days * 86400)));
                $expiry->bindValue(':user_id', $userId);
                $expiry->execute();
            }
            return $activated;
        } catch (Throwable $e) {
            if ($fee > 0) {
                (new WalletService($this->pdo))->refundPurchase($userId, $fee, $reference, [
                    'kind' => 'reseller_subscription_failed',
                    'tier_code' => $tierCode,
                ]);
            }
            throw $e;
        }
    }

    public function managedBotLink(string $managerUsername, string $resellerUsername, string $userId): string
    {
        $managerUsername = ltrim(trim($managerUsername), '@');
        if (!preg_match('/^[A-Za-z0-9_]{5,32}$/', $managerUsername)) {
            throw new InvalidArgumentException('Manager bot username is invalid.');
        }

        $base = preg_replace('/[^A-Za-z0-9_]/', '', ltrim($resellerUsername, '@'));
        $base = $base !== '' ? substr($base, 0, 20) : 'seller' . substr($userId, -6);
        $suggested = rtrim($base, '_') . '_shop_bot';
        $suggested = substr($suggested, 0, 32);
        if (!str_ends_with(strtolower($suggested), 'bot')) {
            $suggested = substr($suggested, 0, 29) . 'bot';
        }

        return 'https://t.me/newbot/' . rawurlencode($managerUsername) . '/' . rawurlencode($suggested)
            . '?name=' . rawurlencode('فروشگاه ' . ($resellerUsername ?: $userId));
    }

    /**
     * Persist and provision a Bot API 9.6 managed_bot update using the existing
     * reseller bot runtime. Returns false for unrelated updates.
     */
    public function handleManagedBotUpdate(array $update, callable $telegramCall, string $projectDir, string $domain): bool
    {
        if (!isset($update['managed_bot']['user'], $update['managed_bot']['bot'])) {
            return false;
        }

        $owner = $update['managed_bot']['user'];
        $bot = $update['managed_bot']['bot'];
        $ownerId = (string) ($owner['id'] ?? '');
        $botId = (string) ($bot['id'] ?? '');
        $botUsername = (string) ($bot['username'] ?? '');
        if ($ownerId === '' || $botId === '' || $botUsername === '') {
            throw new RuntimeException('Incomplete managed_bot update.');
        }

        $this->activate($ownerId, 'reseller');
        $tokenResponse = $telegramCall('getManagedBotToken', ['user_id' => $botId]);
        if (!is_array($tokenResponse) || empty($tokenResponse['ok']) || empty($tokenResponse['result'])) {
            throw new RuntimeException('Telegram did not return the managed bot token.');
        }
        $token = (string) $tokenResponse['result'];

        $adminIds = json_encode([$ownerId]);
        $existingStmt = $this->pdo->prepare('SELECT id FROM botsaz WHERE id_user=:owner LIMIT 1');
        $existingStmt->execute([':owner' => $ownerId]);
        if ($existingStmt->fetchColumn()) {
            $stmt = $this->pdo->prepare(
                "UPDATE botsaz SET bot_token=:token,admin_ids=:admins,username=:username,
                    managed_bot_id=:managed_bot_id,provision_source='telegram_managed',
                    provision_status='provisioning',last_provision_error=NULL,time=:time WHERE id_user=:owner"
            );
        } else {
            $stmt = $this->pdo->prepare(
                "INSERT INTO botsaz (id_user,bot_token,admin_ids,username,time,setting,hide_panel,
                    managed_bot_id,provision_source,provision_status,last_provision_error)
                 VALUES (:owner,:token,:admins,:username,:time,'{}',JSON_OBJECT(),:managed_bot_id,
                    'telegram_managed','provisioning',NULL)"
            );
        }
        $stmt->execute([':owner' => $ownerId, ':token' => $token, ':admins' => $adminIds,
            ':username' => $botUsername, ':time' => date('Y/m/d H:i:s'), ':managed_bot_id' => $botId]);
        (new ResellerBotService($this->pdo, $projectDir, $domain))->provisionExisting($ownerId, 'telegram_managed');

        return true;
    }

    private function callBotApiWithToken(string $token, string $method, array $data): array
    {
        $ch = curl_init('https://api.telegram.org/bot' . $token . '/' . $method);
        if ($ch === false) {
            return ['ok' => false, 'description' => 'Unable to initialise cURL.'];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
        ]);
        $raw = curl_exec($ch);
        if (!is_string($raw)) {
            return ['ok' => false, 'description' => curl_error($ch)];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : ['ok' => false, 'description' => 'Invalid Telegram response.'];
    }
}
