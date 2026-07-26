<?php

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/WalletService.php';
require_once __DIR__ . '/user_fixture.php';

if (!($pdo instanceof PDO)) {
    fwrite(STDERR, "Database unavailable.\n");
    exit(1);
}

function walletAssert($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException("{$message}: expected {$expected}, got {$actual}");
    }
}

$sourceUser = testUserTemplate($pdo);

$testUserId = 'wallet-test-' . bin2hex(random_bytes(5));
$invoiceId = 'invoice-' . bin2hex(random_bytes(5));
$sourceUser['id'] = $testUserId;
$sourceUser['username'] = 'wallet_test_user';
$sourceUser['Balance'] = 100000;
$sourceUser['agent'] = 'f';
$sourceUser['maxbuyagent'] = 0;
$columns = array_keys($sourceUser);
foreach ($columns as $column) {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $column)) {
        throw new RuntimeException('Unsafe user column in wallet test.');
    }
}
$quotedColumns = implode(', ', array_map(static fn ($column) => "`{$column}`", $columns));
$placeholders = implode(', ', array_fill(0, count($columns), '?'));
$pdo->prepare("INSERT INTO user ({$quotedColumns}) VALUES ({$placeholders})")->execute(array_values($sourceUser));

try {
    $wallet = new WalletService($pdo);
    $first = $wallet->debitPurchase($testUserId, 60000, $invoiceId);
    walletAssert(40000, (int) $first['balance_after'], 'Atomic debit');

    $replay = $wallet->debitPurchase($testUserId, 60000, $invoiceId);
    walletAssert(true, (bool) $replay['idempotent_replay'], 'Debit idempotency');
    walletAssert(40000, (int) $pdo->query("SELECT Balance FROM user WHERE id=" . $pdo->quote($testUserId))->fetchColumn(), 'Replay balance');

    $insufficient = false;
    try {
        $wallet->debitPurchase($testUserId, 50000, $invoiceId . '-second');
    } catch (InsufficientWalletBalance $e) {
        $insufficient = true;
    }
    walletAssert(true, $insufficient, 'Insufficient balance protection');

    $refund = $wallet->refundPurchase($testUserId, 60000, $invoiceId);
    walletAssert(100000, (int) $refund['balance_after'], 'Automatic refund');
    $wallet->refundPurchase($testUserId, 60000, $invoiceId);
    walletAssert(100000, (int) $pdo->query("SELECT Balance FROM user WHERE id=" . $pdo->quote($testUserId))->fetchColumn(), 'Refund idempotency');

    $count = $pdo->prepare('SELECT COUNT(*) FROM wallet_ledger WHERE user_id = ?');
    $count->execute([$testUserId]);
    walletAssert(2, (int) $count->fetchColumn(), 'Ledger entry count');
    fwrite(STDOUT, "Wallet service tests passed.\n");
} finally {
    $pdo->prepare('DELETE FROM wallet_ledger WHERE user_id = ?')->execute([$testUserId]);
    $pdo->prepare('DELETE FROM user WHERE id = ?')->execute([$testUserId]);
}
