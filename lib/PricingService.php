<?php

final class PricingService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Resolve one authoritative price. An exact reseller rule wins over its
     * tier, and Premium rules win over the legacy per-user discount.
     */
    public function quote(array $product, array $user, string $operation = 'new'): array
    {
        $allowedOperations = ['new', 'renew', 'extra_volume', 'extra_time'];
        if (!in_array($operation, $allowedOperations, true)) {
            throw new InvalidArgumentException('Unsupported pricing operation.');
        }

        $productId = (int) ($product['id'] ?? 0);
        $userId = (string) ($user['id'] ?? '');
        $tierId = isset($user['reseller_tier_id']) ? (int) $user['reseller_tier_id'] : 0;
        $basePrice = $this->money($product['price_product'] ?? 0);
        $rule = $productId > 0 ? $this->findRule($productId, $userId, $tierId, $operation) : null;

        if ($rule !== null) {
            $finalPrice = $this->applyRule($basePrice, $rule);
            $source = $rule['reseller_user_id'] !== null ? 'reseller_override' : 'tier_rule';
        } else {
            $legacyDiscount = max(0, min(100, (float) ($user['pricediscount'] ?? 0)));
            $finalPrice = (int) round($basePrice * (1 - ($legacyDiscount / 100)));
            $source = $legacyDiscount > 0 ? 'legacy_user_discount' : 'product_default';
        }

        $finalPrice = max(0, $finalPrice);

        return [
            'product_id' => $productId,
            'user_id' => $userId,
            'operation' => $operation,
            'base_price' => $basePrice,
            'final_price' => $finalPrice,
            'discount_amount' => max(0, $basePrice - $finalPrice),
            'markup_amount' => max(0, $finalPrice - $basePrice),
            'source' => $source,
            'rule_id' => $rule !== null ? (int) $rule['id'] : null,
            'tier_id' => $tierId ?: null,
        ];
    }

    private function findRule(int $productId, string $userId, int $tierId, string $operation): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM product_prices
             WHERE product_id = :product_id
               AND operation = :operation
               AND is_active = 1
               AND (starts_at IS NULL OR starts_at <= NOW())
               AND (ends_at IS NULL OR ends_at >= NOW())
               AND (
                    (reseller_user_id IS NOT NULL AND reseller_user_id = :user_id)
                    OR
                    (reseller_user_id IS NULL AND tier_id IS NOT NULL AND tier_id = :tier_id)
               )
             ORDER BY
               CASE WHEN reseller_user_id IS NOT NULL THEN 0 ELSE 1 END,
               priority DESC,
               id DESC
             LIMIT 1"
        );
        $stmt->execute([
            ':product_id' => $productId,
            ':operation' => $operation,
            ':user_id' => $userId,
            ':tier_id' => $tierId,
        ]);
        $rule = $stmt->fetch(PDO::FETCH_ASSOC);

        return $rule === false ? null : $rule;
    }

    private function applyRule(int $basePrice, array $rule): int
    {
        $amount = $this->money($rule['amount'] ?? 0, true);
        $percentBps = max(0, (int) ($rule['percent_bps'] ?? 0));

        switch ($rule['mode']) {
            case 'fixed':
                $price = $amount;
                break;
            case 'percentage_discount':
                $price = (int) round($basePrice * (1 - min(10000, $percentBps) / 10000));
                break;
            case 'markup':
                $price = $basePrice + $amount + (int) round($basePrice * ($percentBps / 10000));
                break;
            default:
                throw new RuntimeException('Invalid pricing rule mode.');
        }

        if ($rule['min_retail_price'] !== null) {
            $price = max($price, $this->money($rule['min_retail_price']));
        }
        if ($rule['max_retail_price'] !== null) {
            $price = min($price, $this->money($rule['max_retail_price']));
        }

        return $price;
    }

    private function money($value, bool $allowNegative = false): int
    {
        if (!is_numeric($value)) {
            return 0;
        }
        $money = (int) round((float) $value);
        return $allowNegative ? $money : max(0, $money);
    }
}

if (!function_exists('premiumProductQuote')) {
    function premiumProductQuote(PDO $pdo, array $product, array $user, string $operation = 'new'): array
    {
        static $services = [];
        $key = spl_object_id($pdo);
        if (!isset($services[$key])) {
            $services[$key] = new PricingService($pdo);
        }
        return $services[$key]->quote($product, $user, $operation);
    }
}
