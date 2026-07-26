CREATE TABLE IF NOT EXISTS wallet_ledger (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(500) NOT NULL,
    entry_type ENUM('debit','credit','refund','commission','adjustment') NOT NULL,
    amount BIGINT NOT NULL,
    balance_before BIGINT NOT NULL,
    balance_after BIGINT NOT NULL,
    reference_type VARCHAR(60) NOT NULL,
    reference_id VARCHAR(200) NOT NULL,
    idempotency_key VARCHAR(255) NOT NULL UNIQUE,
    metadata JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_wallet_ledger_user_created (user_id, created_at),
    KEY idx_wallet_ledger_reference (reference_type, reference_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS premium_orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id VARCHAR(200) NOT NULL UNIQUE,
    user_id VARCHAR(500) NOT NULL,
    product_id INT UNSIGNED NULL,
    operation ENUM('new','renew','extra_volume','extra_time') NOT NULL DEFAULT 'new',
    status ENUM('pending','funded','provisioning','active','failed','refunded') NOT NULL DEFAULT 'pending',
    base_price BIGINT UNSIGNED NOT NULL DEFAULT 0,
    final_price BIGINT UNSIGNED NOT NULL DEFAULT 0,
    pricing_rule_id BIGINT UNSIGNED NULL,
    pricing_source VARCHAR(60) NULL,
    failure_reason TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_premium_orders_user_status (user_id, status),
    KEY idx_premium_orders_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
