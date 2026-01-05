-- =====================================================
-- OptForge Public Platform - Database Schema
-- Version: 1.0
-- Date: 2025-12-26
-- Description: Complete database schema for public-facing
--              SaaS platform with subscriptions & memberships
-- =====================================================

-- =====================================================
-- PUBLIC USER AUTHENTICATION
-- =====================================================

CREATE TABLE IF NOT EXISTS public_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    name TEXT NOT NULL,
    company TEXT,
    phone TEXT,
    email_verified INTEGER DEFAULT 0,
    verification_token TEXT,
    verification_token_expires DATETIME,
    reset_token TEXT,
    reset_token_expires DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_login DATETIME,
    status TEXT DEFAULT 'active' CHECK(status IN ('active', 'suspended', 'deleted'))
);

CREATE INDEX IF NOT EXISTS idx_public_users_email ON public_users(email);
CREATE INDEX IF NOT EXISTS idx_public_users_status ON public_users(status);

-- =====================================================

CREATE TABLE IF NOT EXISTS public_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    token_hash TEXT UNIQUE NOT NULL,
    expires_at DATETIME NOT NULL,
    device_info TEXT,
    ip_address TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES public_users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_public_sessions_token ON public_sessions(token_hash);
CREATE INDEX IF NOT EXISTS idx_public_sessions_user ON public_sessions(user_id);
CREATE INDEX IF NOT EXISTS idx_public_sessions_expires ON public_sessions(expires_at);

-- =====================================================
-- SUBSCRIPTION PLANS & BILLING
-- =====================================================

CREATE TABLE IF NOT EXISTS subscription_plans (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    slug TEXT UNIQUE NOT NULL,
    description TEXT,
    price_monthly REAL NOT NULL,
    price_yearly REAL NOT NULL,
    currency TEXT DEFAULT 'SAR',
    
    -- Credit limits (0 = unlimited)
    credits_phone INTEGER DEFAULT 0,
    credits_email INTEGER DEFAULT 0,
    credits_export INTEGER DEFAULT 0,
    
    -- Feature limits
    max_saved_searches INTEGER DEFAULT 10,
    max_saved_lists INTEGER DEFAULT 5,
    max_list_items INTEGER DEFAULT 100,
    
    -- Features as JSON array
    features TEXT,
    
    is_active INTEGER DEFAULT 1,
    sort_order INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_subscription_plans_slug ON subscription_plans(slug);
CREATE INDEX IF NOT EXISTS idx_subscription_plans_active ON subscription_plans(is_active);

-- =====================================================

CREATE TABLE IF NOT EXISTS user_subscriptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    plan_id INTEGER NOT NULL,
    
    status TEXT DEFAULT 'active' CHECK(status IN ('active', 'cancelled', 'expired', 'past_due', 'trialing')),
    billing_cycle TEXT CHECK(billing_cycle IN ('monthly', 'yearly')),
    
    current_period_start DATETIME NOT NULL,
    current_period_end DATETIME NOT NULL,
    cancel_at_period_end INTEGER DEFAULT 0,
    cancelled_at DATETIME,
    
    -- Payment provider details
    payment_provider TEXT, -- 'stripe', 'paytabs', etc.
    payment_customer_id TEXT,
    payment_subscription_id TEXT,
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES public_users(id) ON DELETE CASCADE,
    FOREIGN KEY (plan_id) REFERENCES subscription_plans(id)
);

CREATE INDEX IF NOT EXISTS idx_user_subscriptions_user ON user_subscriptions(user_id);
CREATE INDEX IF NOT EXISTS idx_user_subscriptions_status ON user_subscriptions(status);
CREATE INDEX IF NOT EXISTS idx_user_subscriptions_period ON user_subscriptions(current_period_end);

-- =====================================================

CREATE TABLE IF NOT EXISTS payment_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    subscription_id INTEGER,
    
    amount REAL NOT NULL,
    currency TEXT DEFAULT 'SAR',
    status TEXT CHECK(status IN ('pending', 'succeeded', 'failed', 'refunded')),
    
    payment_provider TEXT,
    payment_intent_id TEXT,
    invoice_url TEXT,
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES public_users(id),
    FOREIGN KEY (subscription_id) REFERENCES user_subscriptions(id)
);

CREATE INDEX IF NOT EXISTS idx_payment_history_user ON payment_history(user_id);
CREATE INDEX IF NOT EXISTS idx_payment_history_status ON payment_history(status);

-- =====================================================
-- USAGE TRACKING
-- =====================================================

CREATE TABLE IF NOT EXISTS usage_tracking (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    month TEXT NOT NULL, -- Format: YYYY-MM
    
    phone_reveals INTEGER DEFAULT 0,
    email_reveals INTEGER DEFAULT 0,
    exports_count INTEGER DEFAULT 0,
    searches_count INTEGER DEFAULT 0,
    api_calls INTEGER DEFAULT 0,
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES public_users(id) ON DELETE CASCADE,
    UNIQUE(user_id, month)
);

CREATE INDEX IF NOT EXISTS idx_usage_tracking_user_month ON usage_tracking(user_id, month);

-- =====================================================
-- SAVED SEARCHES
-- =====================================================

CREATE TABLE IF NOT EXISTS saved_searches (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    description TEXT,
    
    -- Filters stored as JSON
    filters TEXT NOT NULL,
    
    result_count INTEGER DEFAULT 0,
    last_run DATETIME,
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES public_users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_saved_searches_user ON saved_searches(user_id);

-- =====================================================
-- SAVED LISTS
-- =====================================================

CREATE TABLE IF NOT EXISTS saved_lists (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    description TEXT,
    color TEXT, -- For UI categorization
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES public_users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_saved_lists_user ON saved_lists(user_id);

-- =====================================================

CREATE TABLE IF NOT EXISTS saved_list_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    list_id INTEGER NOT NULL,
    lead_id INTEGER NOT NULL,
    notes TEXT,
    added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (list_id) REFERENCES saved_lists(id) ON DELETE CASCADE,
    FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
    UNIQUE(list_id, lead_id)
);

CREATE INDEX IF NOT EXISTS idx_saved_list_items_list ON saved_list_items(list_id);
CREATE INDEX IF NOT EXISTS idx_saved_list_items_lead ON saved_list_items(lead_id);

-- =====================================================
-- REVEALED CONTACTS (Credit Tracking)
-- =====================================================

CREATE TABLE IF NOT EXISTS revealed_contacts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    lead_id INTEGER NOT NULL,
    reveal_type TEXT CHECK(reveal_type IN ('phone', 'email', 'full')),
    revealed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES public_users(id) ON DELETE CASCADE,
    FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
    UNIQUE(user_id, lead_id, reveal_type)
);

CREATE INDEX IF NOT EXISTS idx_revealed_contacts_user ON revealed_contacts(user_id);
CREATE INDEX IF NOT EXISTS idx_revealed_contacts_lead ON revealed_contacts(lead_id);

-- =====================================================
-- EXPORT HISTORY
-- =====================================================

CREATE TABLE IF NOT EXISTS export_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    export_type TEXT CHECK(export_type IN ('csv', 'excel', 'pdf')),
    filters TEXT, -- JSON of filters used
    record_count INTEGER,
    file_path TEXT,
    expires_at DATETIME, -- Download link expiration
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES public_users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_export_history_user ON export_history(user_id);

-- =====================================================
-- SEED DATA: Default Subscription Plans
-- =====================================================

INSERT OR IGNORE INTO subscription_plans (id, name, slug, description, price_monthly, price_yearly, currency, credits_phone, credits_email, credits_export, max_saved_searches, max_saved_lists, max_list_items, features, is_active, sort_order) VALUES
(1, 'مجاني', 'free', 'للتجربة والاستكشاف الأولي', 0, 0, 'SAR', 0, 0, 0, 5, 2, 50, '["بحث أساسي","نتائج محدودة","بيانات أساسية فقط"]', 1, 0),

(2, 'أساسي', 'basic', 'مثالي للأفراد والشركات الصغيرة', 199, 1990, 'SAR', 100, 0, 100, 25, 10, 500, '["فلاتر متقدمة","100 كشف هاتف شهرياً","100 تصدير شهرياً","حفظ 25 بحث","10 قوائم محفوظة"]', 1, 1),

(3, 'احترافي', 'professional', 'للشركات المتوسطة والفرق', 399, 3990, 'SAR', 0, 100, 500, 100, 50, 2000, '["كشف هواتف غير محدود","100 كشف إيميل شهرياً","500 تصدير شهرياً","100 بحث محفوظ","50 قائمة محفوظة","دعم متقدم"]', 1, 2),

(4, 'مؤسسات', 'enterprise', 'للشركات الكبيرة والاحتياجات الخاصة', 799, 7990, 'SAR', 0, 0, 0, 0, 0, 0, '["كل شيء غير محدود","API مخصص","دعم أولوية","تكاملات مخصصة","مدير حساب مخصص","تقارير متقدمة"]', 1, 3);

-- =====================================================
-- END OF SCHEMA
-- =====================================================
