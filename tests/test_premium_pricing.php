<?php

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/PricingService.php';
require_once dirname(__DIR__) . '/lib/ResellerService.php';
require_once __DIR__ . '/user_fixture.php';

if (!($pdo instanceof PDO)) {
    fwrite(STDERR, "Database unavailable.\n");
    exit(1);
}

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
}

$pdo->beginTransaction();
try {
    $tierId = (int) $pdo->query("SELECT id FROM reseller_tiers WHERE code = 'reseller'")->fetchColumn();
    if ($tierId <= 0) {
        throw new RuntimeException('Default reseller tier is missing.');
    }

    $productStmt = $pdo->prepare(
        "INSERT INTO product
            (code_product, name_product, price_product, Volume_constraint, Location,
             Service_time, agent, note, data_limit_reset, one_buy_status, hide_panel, ip_limit)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $productStmt->execute([
        'premium-test-' . bin2hex(random_bytes(3)),
        'Premium pricing test',
        '120000',
        '100',
        '/all',
        '30',
        'f',
        '',
        'no_reset',
        '0',
        '{}',
        2,
    ]);
    $productId = (int) $pdo->lastInsertId();
    $product = ['id' => $productId, 'price_product' => 120000];
    $service = new PricingService($pdo);

    $tierRule = $pdo->prepare(
        "INSERT INTO product_prices
            (product_id, tier_id, operation, mode, amount, priority)
         VALUES (?, ?, 'new', 'fixed', 90000, 10)"
    );
    $tierRule->execute([$productId, $tierId]);

    $quote = $service->quote($product, [
        'id' => 'pricing-tier-user',
        'reseller_tier_id' => $tierId,
        'pricediscount' => 5,
    ]);
    assertSameValue(90000, $quote['final_price'], 'Tier rule must beat legacy discount');
    assertSameValue('tier_rule', $quote['source'], 'Tier source');

    $userRule = $pdo->prepare(
        "INSERT INTO product_prices
            (product_id, reseller_user_id, operation, mode, amount, priority)
         VALUES (?, ?, 'new', 'fixed', 78000, 1)"
    );
    $userRule->execute([$productId, 'pricing-tier-user']);

    $quote = $service->quote($product, [
        'id' => 'pricing-tier-user',
        'reseller_tier_id' => $tierId,
        'pricediscount' => 5,
    ]);
    assertSameValue(78000, $quote['final_price'], 'Exact reseller rule must beat tier rule');
    assertSameValue('reseller_override', $quote['source'], 'Exact reseller source');

    $legacyQuote = $service->quote($product, [
        'id' => 'retail-legacy-user',
        'reseller_tier_id' => 0,
        'pricediscount' => 10,
    ]);
    assertSameValue(108000, $legacyQuote['final_price'], 'Legacy fallback discount');

    $link = (new ResellerService($pdo))->managedBotLink('neo666_bot', 'seller_demo', '123456');
    if (!str_starts_with($link, 'https://t.me/newbot/neo666_bot/')) {
        throw new RuntimeException('Managed bot link is invalid.');
    }

    $pdo->rollBack();

    // Verify self-service activation with an isolated clone that is removed
    // immediately after the assertion. ResellerService owns its transaction,
    // so this intentionally runs after the pricing rollback.
    $sourceUser = testUserTemplate($pdo);
    $testUserId = 'premium-test-' . bin2hex(random_bytes(5));
    $sourceUser['id'] = $testUserId;
    $sourceUser['username'] = 'premium_test_user';
    $sourceUser['agent'] = 'f';
    $sourceUser['reseller_tier_id'] = null;
    $columns = array_keys($sourceUser);
    foreach ($columns as $column) {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $column)) {
            throw new RuntimeException('Unsafe user column in onboarding test.');
        }
    }
    $quotedColumns = implode(', ', array_map(static fn ($column) => "`{$column}`", $columns));
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $cloneStmt = $pdo->prepare("INSERT INTO user ({$quotedColumns}) VALUES ({$placeholders})");
    $cloneStmt->execute(array_values($sourceUser));

    try {
        (new ResellerService($pdo))->activate($testUserId, 'reseller');
        $activated = $pdo->prepare('SELECT agent, reseller_tier_id FROM user WHERE id = ?');
        $activated->execute([$testUserId]);
        $activatedUser = $activated->fetch(PDO::FETCH_ASSOC);
        assertSameValue('n', $activatedUser['agent'], 'Self-service activation legacy role');
        assertSameValue($tierId, (int) $activatedUser['reseller_tier_id'], 'Self-service activation tier');
    } finally {
        $pdo->prepare('DELETE FROM Requestagent WHERE id = ?')->execute([$testUserId]);
        $pdo->prepare('DELETE FROM reseller_profiles WHERE user_id = ?')->execute([$testUserId]);
        $pdo->prepare('DELETE FROM user WHERE id = ?')->execute([$testUserId]);
    }

    $creditTier = $pdo->query("SELECT * FROM reseller_tiers WHERE code='credit_reseller'")->fetch(PDO::FETCH_ASSOC);
    if ($creditTier && empty($creditTier['auto_approve'])) {
        $pendingUserId = 'premium-pending-' . bin2hex(random_bytes(5));
        $sourceUser['id'] = $pendingUserId;
        $sourceUser['username'] = 'premium_pending_user';
        $sourceUser['agent'] = 'f';
        $sourceUser['reseller_tier_id'] = null;
        $cloneStmt->execute(array_values($sourceUser));
        try {
            $pendingTier = (new ResellerService($pdo))->subscribe($pendingUserId, 'credit_reseller');
            assertSameValue('pending', $pendingTier['subscription_status'] ?? '', 'Manual plan remains pending');
            $pendingCheck = $pdo->prepare('SELECT u.agent, rp.status FROM user u JOIN reseller_profiles rp ON rp.user_id=u.id WHERE u.id=?');
            $pendingCheck->execute([$pendingUserId]);
            $pendingState = $pendingCheck->fetch(PDO::FETCH_ASSOC);
            assertSameValue('f', $pendingState['agent'], 'Pending reseller must not receive a privileged role');
            assertSameValue('pending', $pendingState['status'], 'Pending reseller profile status');
        } finally {
            $pdo->prepare('DELETE FROM Requestagent WHERE id = ?')->execute([$pendingUserId]);
            $pdo->prepare('DELETE FROM reseller_profiles WHERE user_id = ?')->execute([$pendingUserId]);
            $pdo->prepare('DELETE FROM user WHERE id = ?')->execute([$pendingUserId]);
        }
    }

    fwrite(STDOUT, "Premium pricing and reseller onboarding tests passed.\n");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Premium pricing tests failed: {$e->getMessage()}\n");
    exit(1);
}
