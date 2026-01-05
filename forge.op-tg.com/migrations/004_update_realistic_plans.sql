-- =====================================================
-- تحديث الباقات لتتوافق مع الميزات الفعلية
-- Update Subscription Plans to Match Real Features
-- Version: 1.1
-- Date: 2025-12-30
-- =====================================================

-- الميزات الفعلية:
-- ✅ كشف أرقام الهاتف (credits_phone)
-- ✅ حملات البحث (لا يوجد حد في الجدول - يُذكر في features)
-- ✅ رسائل واتساب (لا يوجد حد في الجدول - يُذكر في features)
-- ✅ تصدير غير محدود (credits_export = 0 يعني غير محدود)
-- ❌ كشف إيميلات (credits_email = 0)
-- ❌ بحوثات محفوظة (max_saved_searches = 0)
-- ❌ قوائم محفوظة (max_saved_lists = 0)

-- حذف الباقات القديمة وإضافة الجديدة
DELETE FROM subscription_plans;

-- الباقة المجانية
INSERT INTO subscription_plans (
    id, name, slug, description, 
    price_monthly, price_yearly, currency, 
    credits_phone, credits_email, credits_export, 
    max_saved_searches, max_saved_lists, max_list_items, 
    features, is_active, sort_order
) VALUES (
    1, 'مجاني', 'free', 'للتجربة والاستكشاف',
    0, 0, 'SAR',
    10, 0, 0,  -- 10 كشف هاتف، بدون إيميل، تصدير غير محدود
    0, 0, 0,   -- بدون بحوثات أو قوائم
    '["10 كشف أرقام هاتف", "حملة بحث واحدة", "تصدير غير محدود", "بدون واتساب"]',
    1, 0
);

-- الباقة الأساسية
INSERT INTO subscription_plans (
    id, name, slug, description, 
    price_monthly, price_yearly, currency, 
    credits_phone, credits_email, credits_export, 
    max_saved_searches, max_saved_lists, max_list_items, 
    features, is_active, sort_order
) VALUES (
    2, 'أساسي', 'basic', 'مثالي للأفراد والشركات الصغيرة',
    149, 1490, 'SAR',
    100, 0, 0,  -- 100 كشف هاتف، بدون إيميل، تصدير غير محدود
    0, 0, 0,   -- بدون بحوثات أو قوائم
    '["100 كشف أرقام هاتف شهرياً", "10 حملات بحث", "100 رسالة واتساب شهرياً", "تصدير غير محدود", "دعم أولوية"]',
    1, 1
);

-- الباقة الاحترافية
INSERT INTO subscription_plans (
    id, name, slug, description, 
    price_monthly, price_yearly, currency, 
    credits_phone, credits_email, credits_export, 
    max_saved_searches, max_saved_lists, max_list_items, 
    features, is_active, sort_order
) VALUES (
    3, 'احترافي', 'professional', 'للشركات المتوسطة والفرق',
    349, 3490, 'SAR',
    500, 0, 0,  -- 500 كشف هاتف، بدون إيميل، تصدير غير محدود
    0, 0, 0,   -- بدون بحوثات أو قوائم
    '["500 كشف أرقام هاتف شهرياً", "50 حملة بحث", "500 رسالة واتساب شهرياً", "تصدير غير محدود", "دعم متقدم", "أولوية في الدعم"]',
    1, 2
);

-- باقة المؤسسات
INSERT INTO subscription_plans (
    id, name, slug, description, 
    price_monthly, price_yearly, currency, 
    credits_phone, credits_email, credits_export, 
    max_saved_searches, max_saved_lists, max_list_items, 
    features, is_active, sort_order
) VALUES (
    4, 'مؤسسات', 'enterprise', 'للشركات الكبيرة والاحتياجات الخاصة',
    699, 6990, 'SAR',
    0, 0, 0,  -- كل شيء غير محدود (0 = unlimited)
    0, 0, 0,   -- بدون بحوثات أو قوائم
    '["كشف أرقام هاتف غير محدود", "حملات بحث غير محدودة", "رسائل واتساب غير محدودة", "تصدير غير محدود", "مدير حساب مخصص", "دعم على مدار الساعة", "API مخصص"]',
    1, 3
);

-- =====================================================
-- END OF MIGRATION
-- =====================================================
