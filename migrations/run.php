<?php

require_once dirname(__DIR__) . '/config.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "Database connection is unavailable.\n");
    exit(1);
}

$migration = $argv[1] ?? '';
if ($migration === '' || basename($migration) !== $migration || !preg_match('/^[A-Za-z0-9_.-]+\.sql$/', $migration)) {
    fwrite(STDERR, "Usage: php migrations/run.php <migration.sql>\n");
    exit(1);
}

$path = __DIR__ . DIRECTORY_SEPARATOR . $migration;
if (!is_file($path)) {
    fwrite(STDERR, "Migration not found.\n");
    exit(1);
}

$sql = file_get_contents($path);
if ($sql === false) {
    fwrite(STDERR, "Migration could not be read.\n");
    exit(1);
}

try {
    if ($migration === '20260726_reseller_sales_plans.sql') {
        $ensurePlanColumn = static function (PDO $pdo, string $column, string $definition): void {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'reseller_tiers\' AND COLUMN_NAME = :column'
            );
            $stmt->execute([':column' => $column]);
            if ((int) $stmt->fetchColumn() === 0) {
                if (!preg_match('/^[A-Za-z0-9_]+$/', $column)) {
                    throw new RuntimeException('Unsafe migration identifier.');
                }
                $pdo->exec("ALTER TABLE `reseller_tiers` ADD COLUMN `{$column}` {$definition}");
            }
        };
        $ensurePlanColumn($pdo, 'signup_fee', 'BIGINT UNSIGNED NOT NULL DEFAULT 0');
        $ensurePlanColumn($pdo, 'duration_days', 'INT UNSIGNED NOT NULL DEFAULT 0');
        $ensurePlanColumn($pdo, 'description', 'TEXT NULL');
        $ensurePlanColumn($pdo, 'features', 'JSON NULL');
        $ensurePlanColumn($pdo, 'sort_order', 'INT NOT NULL DEFAULT 0');
    }
    if ($migration === '20260726_reseller_webhooks.sql') {
        $ensureWebhookColumn = static function (PDO $pdo, string $column, string $definition): void {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS '
                . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=\'botsaz\' AND COLUMN_NAME=:column'
            );
            $stmt->execute([':column' => $column]);
            if ((int) $stmt->fetchColumn() === 0) {
                $pdo->exec("ALTER TABLE `botsaz` ADD COLUMN `{$column}` {$definition}");
            }
        };
        $ensureWebhookColumn($pdo, 'webhook_key', 'VARCHAR(32) NULL');
        $ensureWebhookColumn($pdo, 'webhook_secret', 'VARCHAR(128) NULL');
        $indexStmt = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() "
            . "AND TABLE_NAME='botsaz' AND INDEX_NAME='uq_botsaz_webhook_key'"
        );
        if ((int) $indexStmt->fetchColumn() === 0) {
            $pdo->exec('CREATE UNIQUE INDEX uq_botsaz_webhook_key ON botsaz (webhook_key)');
        }
        $sql = '';
    }
    if (trim($sql) !== '') {
        $pdo->exec($sql);
    }
    if ($migration === '20260726_premium_resellers.sql') {
        $ensureColumn = static function (PDO $pdo, string $table, string $column, string $definition): void {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
            );
            $stmt->execute([':table' => $table, ':column' => $column]);
            if ((int) $stmt->fetchColumn() === 0) {
                if (!preg_match('/^[A-Za-z0-9_]+$/', $table . $column)) {
                    throw new RuntimeException('Unsafe migration identifier.');
                }
                $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            }
        };

        $ensureColumn($pdo, 'user', 'reseller_tier_id', 'INT UNSIGNED NULL');
        $ensureColumn($pdo, 'botsaz', 'managed_bot_id', 'VARCHAR(100) NULL');
        $ensureColumn($pdo, 'botsaz', 'provision_source', "VARCHAR(30) NOT NULL DEFAULT 'legacy'");
        $ensureColumn($pdo, 'botsaz', 'provision_status', "VARCHAR(30) NOT NULL DEFAULT 'active'");
        $ensureColumn($pdo, 'botsaz', 'last_provision_error', 'TEXT NULL');

        $pdo->exec("UPDATE user u
            JOIN reseller_tiers rt ON rt.legacy_agent = u.agent
            SET u.reseller_tier_id = rt.id
            WHERE u.reseller_tier_id IS NULL");
    }
    if ($migration === '20260726_premium_ledger.sql') {
        $ensureColumn = static function (PDO $pdo, string $table, string $column, string $definition): void {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
            );
            $stmt->execute([':table' => $table, ':column' => $column]);
            if ((int) $stmt->fetchColumn() === 0) {
                if (!preg_match('/^[A-Za-z0-9_]+$/', $table . $column)) {
                    throw new RuntimeException('Unsafe migration identifier.');
                }
                $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            }
        };
        $ensureColumn($pdo, 'invoice', 'product_id', 'INT UNSIGNED NULL');
        $ensureColumn($pdo, 'invoice', 'base_price', 'BIGINT UNSIGNED NULL');
        $ensureColumn($pdo, 'invoice', 'pricing_rule_id', 'BIGINT UNSIGNED NULL');
        $ensureColumn($pdo, 'invoice', 'pricing_source', 'VARCHAR(60) NULL');
        $ensureColumn($pdo, 'invoice', 'reseller_margin', 'BIGINT NOT NULL DEFAULT 0');
    }
    fwrite(STDOUT, "Migration applied: {$migration}\n");
} catch (Throwable $e) {
    fwrite(STDERR, "Migration failed: {$e->getMessage()}\n");
    exit(1);
}
