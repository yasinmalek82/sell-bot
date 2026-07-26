CREATE TABLE IF NOT EXISTS reseller_tiers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(60) NOT NULL UNIQUE,
    name VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    legacy_agent VARCHAR(20) NULL,
    default_discount_bps INT UNSIGNED NOT NULL DEFAULT 0,
    credit_limit BIGINT UNSIGNED NOT NULL DEFAULT 0,
    min_retail_margin BIGINT UNSIGNED NOT NULL DEFAULT 0,
    auto_approve TINYINT(1) NOT NULL DEFAULT 0,
    can_create_bot TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_reseller_tiers_legacy_agent (legacy_agent)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO reseller_tiers
    (code, name, legacy_agent, auto_approve, can_create_bot)
VALUES
    ('retail', 'کاربر عادی', 'f', 1, 0),
    ('reseller', 'نماینده', 'n', 1, 1),
    ('credit_reseller', 'نماینده اعتباری', 'n2', 0, 1);

CREATE TABLE IF NOT EXISTS reseller_profiles (
    user_id VARCHAR(500) PRIMARY KEY,
    tier_id INT UNSIGNED NOT NULL,
    status ENUM('pending','active','suspended','expired','rejected') NOT NULL DEFAULT 'pending',
    parent_user_id VARCHAR(500) NULL,
    approved_at DATETIME NULL,
    expires_at DATETIME NULL,
    settings JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_reseller_profiles_tier_status (tier_id, status),
    KEY idx_reseller_profiles_parent (parent_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_prices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    tier_id INT UNSIGNED NULL,
    reseller_user_id VARCHAR(500) NULL,
    operation ENUM('new','renew','extra_volume','extra_time') NOT NULL DEFAULT 'new',
    mode ENUM('fixed','percentage_discount','markup') NOT NULL DEFAULT 'fixed',
    amount BIGINT NOT NULL DEFAULT 0,
    percent_bps INT UNSIGNED NOT NULL DEFAULT 0,
    min_retail_price BIGINT UNSIGNED NULL,
    max_retail_price BIGINT UNSIGNED NULL,
    priority INT NOT NULL DEFAULT 0,
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_product_prices_resolve (product_id, operation, is_active, reseller_user_id, tier_id, priority),
    KEY idx_product_prices_window (starts_at, ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_audiences (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    tier_id INT UNSIGNED NULL,
    reseller_user_id VARCHAR(500) NULL,
    is_visible TINYINT(1) NOT NULL DEFAULT 1,
    is_purchasable TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_product_audiences_resolve (product_id, reseller_user_id, tier_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
