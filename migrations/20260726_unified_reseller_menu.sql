UPDATE reseller_tiers
SET can_create_bot=0,
    features=CASE code
        WHEN 'reseller' THEN JSON_ARRAY('قیمت همکاری', 'منوی اختصاصی نماینده', 'فروش حضوری سریع', 'مدیریت مشتری‌ها')
        WHEN 'credit_reseller' THEN JSON_ARRAY('همه امکانات نماینده', 'خرید اعتباری', 'قیمت‌گذاری اختصاصی', 'گزارش فروش')
        ELSE features
    END
WHERE code IN ('reseller','credit_reseller');
