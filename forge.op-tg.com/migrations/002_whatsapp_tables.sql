-- WhatsApp Integration Tables Migration
-- Created: 2025-12-29

-- إعدادات الواتساب
CREATE TABLE IF NOT EXISTS whatsapp_settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    api_url TEXT DEFAULT 'https://wa.washeej.com/api/qr/rest/send_message',
    auth_token TEXT,
    sender_number TEXT,
    is_active INTEGER DEFAULT 0,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(user_id)
);

-- قوالب الرسائل
CREATE TABLE IF NOT EXISTS whatsapp_templates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    content_type TEXT DEFAULT 'text' CHECK(content_type IN ('text', 'image', 'video', 'document', 'audio')),
    message_text TEXT,
    media_url TEXT,
    is_default INTEGER DEFAULT 0,
    is_active INTEGER DEFAULT 1,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- سجل الإرسال
CREATE TABLE IF NOT EXISTS whatsapp_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    lead_id INTEGER,
    template_id INTEGER,
    recipient_number TEXT NOT NULL,
    recipient_name TEXT,
    message_text TEXT,
    content_type TEXT DEFAULT 'text',
    status TEXT DEFAULT 'pending' CHECK(status IN ('pending', 'sent', 'failed')),
    api_response TEXT,
    error_message TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- إنشاء indexes للأداء
CREATE INDEX IF NOT EXISTS idx_whatsapp_settings_user ON whatsapp_settings(user_id);
CREATE INDEX IF NOT EXISTS idx_whatsapp_templates_user ON whatsapp_templates(user_id);
CREATE INDEX IF NOT EXISTS idx_whatsapp_logs_user ON whatsapp_logs(user_id);
CREATE INDEX IF NOT EXISTS idx_whatsapp_logs_status ON whatsapp_logs(status);
CREATE INDEX IF NOT EXISTS idx_whatsapp_logs_created ON whatsapp_logs(created_at);

-- إدراج قالب افتراضي للترحيب
INSERT OR IGNORE INTO whatsapp_templates (id, user_id, name, content_type, message_text, is_default) 
VALUES (1, 0, 'ترحيب عام', 'text', 'مرحباً {{name}}، نشكرك على اهتمامك بخدماتنا. كيف يمكننا مساعدتك؟', 1);
