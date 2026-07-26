<?php

final class InsufficientWalletBalance extends RuntimeException
{
}

final class WalletService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function debitPurchase(string $userId, int $amount, string $invoiceId, array $metadata = []): array
    {
        return $this->apply(
            $userId,
            max(0, $amount) * -1,
            'debit',
            'invoice',
            $invoiceId,
            'purchase:' . $invoiceId,
            $metadata
        );
    }

    public function refundPurchase(string $userId, int $amount, string $invoiceId, array $metadata = []): array
    {
        return $this->apply(
            $userId,
            max(0, $amount),
            'refund',
            'invoice',
            $invoiceId,
            'refund:' . $invoiceId,
            $metadata
        );
    }

    public function credit(string $userId, int $amount, string $referenceType, string $referenceId, string $idempotencyKey, array $metadata = []): array
    {
        return $this->apply(
            $userId,
            max(0, $amount),
            'credit',
            $referenceType,
            $referenceId,
            $idempotencyKey,
            $metadata
        );
    }

    private function apply(
        string $userId,
        int $signedAmount,
        string $entryType,
        string $referenceType,
        string $referenceId,
        string $idempotencyKey,
        array $metadata
    ): array {
        if ($userId === '' || $idempotencyKey === '') {
            throw new InvalidArgumentException('Wallet identifiers are required.');
        }

        $this->pdo->beginTransaction();
        try {
            $existingStmt = $this->pdo->prepare(
                'SELECT * FROM wallet_ledger WHERE idempotency_key = :key LIMIT 1'
            );
            $existingStmt->execute([':key' => $idempotencyKey]);
            $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                $this->pdo->commit();
                $existing['idempotent_replay'] = true;
                return $existing;
            }

            $userStmt = $this->pdo->prepare(
                'SELECT Balance, agent, maxbuyagent, reseller_tier_id FROM user WHERE id = :id FOR UPDATE'
            );
            $userStmt->execute([':id' => $userId]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) {
                throw new RuntimeException('Wallet user was not found.');
            }

            $before = (int) $user['Balance'];
            $after = $before + $signedAmount;
            $creditLimit = 0;
            if ($user['agent'] === 'n2') {
                $creditLimit = max(0, (int) ($user['maxbuyagent'] ?? 0));
                if (!empty($user['reseller_tier_id'])) {
                    $tierStmt = $this->pdo->prepare('SELECT credit_limit FROM reseller_tiers WHERE id = ?');
                    $tierStmt->execute([(int) $user['reseller_tier_id']]);
                    $creditLimit = max($creditLimit, (int) $tierStmt->fetchColumn());
                }
            }
            if ($signedAmount < 0 && $after < (0 - $creditLimit)) {
                throw new InsufficientWalletBalance('Insufficient wallet balance.');
            }

            $updateStmt = $this->pdo->prepare('UPDATE user SET Balance = :balance WHERE id = :id');
            $updateStmt->execute([':balance' => $after, ':id' => $userId]);

            $ledgerStmt = $this->pdo->prepare(
                "INSERT INTO wallet_ledger
                    (user_id, entry_type, amount, balance_before, balance_after,
                     reference_type, reference_id, idempotency_key, metadata)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $ledgerStmt->execute([
                $userId,
                $entryType,
                $signedAmount,
                $before,
                $after,
                $referenceType,
                $referenceId,
                $idempotencyKey,
                json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            $ledgerId = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();

            return [
                'id' => $ledgerId,
                'user_id' => $userId,
                'entry_type' => $entryType,
                'amount' => $signedAmount,
                'balance_before' => $before,
                'balance_after' => $after,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'idempotency_key' => $idempotencyKey,
                'idempotent_replay' => false,
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
