<?php
/**
 * Run Bulk Messaging Migration - Fixed Version
 */
require_once __DIR__ . '/config/db.php';

echo "Running bulk messaging migration...\n";

$pdo = db();

// Create tables directly
try {
    // Create campaigns table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whatsapp_bulk_campaigns (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            template_id INTEGER,
            name TEXT,
            message_text TEXT,
            status TEXT DEFAULT 'pending',
            total_count INTEGER DEFAULT 0,
            sent_count INTEGER DEFAULT 0,
            failed_count INTEGER DEFAULT 0,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
            completed_at TEXT
        )
    ");
    echo "✅ Created whatsapp_bulk_campaigns\n";

    // Create queue table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whatsapp_queue (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            campaign_id INTEGER NOT NULL,
            lead_id INTEGER,
            recipient_number TEXT NOT NULL,
            recipient_name TEXT,
            message_text TEXT,
            status TEXT DEFAULT 'pending',
            error_message TEXT,
            attempts INTEGER DEFAULT 0,
            processed_at TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )
    ");
    echo "✅ Created whatsapp_queue\n";

    // Create indexes
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_campaigns_user_status ON whatsapp_bulk_campaigns(user_id, status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_queue_campaign_status ON whatsapp_queue(campaign_id, status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_queue_status ON whatsapp_queue(status)");
    echo "✅ Created indexes\n";

} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

// Verify tables
$tables = ['whatsapp_bulk_campaigns', 'whatsapp_queue'];
echo "\n=== Verification ===\n";
foreach ($tables as $table) {
    $exists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='$table'")->fetch();
    echo ($exists ? "✅" : "❌") . " Table '$table'\n";
}

echo "\nDone!\n";
