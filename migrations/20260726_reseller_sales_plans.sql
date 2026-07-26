UPDATE reseller_tiers
SET description = CASE code
    WHEN 'reseller' THEN 'شروع فروش با قیمت همکاری و کیف پول پیش‌پرداخت'
    WHEN 'credit_reseller' THEN 'فروش اعتباری، ربات اختصاصی و سقف اعتبار قابل تنظیم'
    ELSE description
END,
features = CASE code
    WHEN 'reseller' THEN JSON_ARRAY('قیمت همکاری', 'پنل نمایندگی', 'ربات اختصاصی')
    WHEN 'credit_reseller' THEN JSON_ARRAY('همه امکانات نماینده', 'خرید اعتباری', 'قیمت‌گذاری اختصاصی')
    ELSE features
END,
sort_order = CASE code WHEN 'reseller' THEN 10 WHEN 'credit_reseller' THEN 20 ELSE sort_order END
WHERE code IN ('reseller', 'credit_reseller') AND (description IS NULL OR features IS NULL);
