-- Migration: Bulk WhatsApp Messaging Tables
-- Created: 2025-12-29

-- جدول حملات الإرسال المجمع
CREATE TABLE IF NOT EXISTS whatsapp_bulk_campaigns (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    template_id INTEGER,
    name TEXT,
    message_text TEXT,
    status TEXT DEFAULT 'pending', -- pending, processing, completed, cancelled
    total_count INTEGER DEFAULT 0,
    sent_count INTEGER DEFAULT 0,
    failed_count INTEGER DEFAULT 0,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
    completed_at TEXT
);

-- فهرس للبحث السريع
CREATE INDEX IF NOT EXISTS idx_campaigns_user_status ON whatsapp_bulk_campaigns(user_id, status);

-- جدول قائمة انتظار الرسائل
CREATE TABLE IF NOT EXISTS whatsapp_queue (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    campaign_id INTEGER NOT NULL,
    lead_id INTEGER,
    recipient_number TEXT NOT NULL,
    recipient_name TEXT,
    message_text TEXT,
    status TEXT DEFAULT 'pending', -- pending, sent, failed
    error_message TEXT,
    attempts INTEGER DEFAULT 0,
    processed_at TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (campaign_id) REFERENCES whatsapp_bulk_campaigns(id) ON DELETE CASCADE
);

-- فهارس للأداء
CREATE INDEX IF NOT EXISTS idx_queue_campaign_status ON whatsapp_queue(campaign_id, status);
CREATE INDEX IF NOT EXISTS idx_queue_status ON whatsapp_queue(status);
