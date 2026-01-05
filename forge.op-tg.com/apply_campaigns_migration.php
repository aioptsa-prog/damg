<?php
/**
 * Apply migration and create campaigns API
 */
require_once __DIR__ . '/config/db.php';

$pdo = db();

echo "========================================\n";
echo "  Applying User Campaigns Migration\n";
echo "========================================\n\n";

// Create user_campaigns table
$pdo->exec("
    CREATE TABLE IF NOT EXISTS user_campaigns (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        name TEXT NOT NULL,
        description TEXT,
        query TEXT NOT NULL,
        city TEXT NOT NULL,
        ll TEXT,
        radius_km INTEGER DEFAULT 15,
        category_id INTEGER,
        target_count INTEGER DEFAULT 100,
        result_count INTEGER DEFAULT 0,
        status TEXT DEFAULT 'pending',
        internal_job_id INTEGER,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        started_at DATETIME,
        completed_at DATETIME,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )
");
echo "✓ Created user_campaigns table\n";

// Create user_leads table
$pdo->exec("
    CREATE TABLE IF NOT EXISTS user_leads (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        lead_id INTEGER NOT NULL,
        campaign_id INTEGER,
        notes TEXT,
        tags TEXT,
        phone_revealed INTEGER DEFAULT 0,
        email_revealed INTEGER DEFAULT 0,
        contacted_at DATETIME,
        contact_status TEXT DEFAULT 'new',
        added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(user_id, lead_id)
    )
");
echo "✓ Created user_leads table\n";

// Create indexes
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_user_campaigns_user ON user_campaigns(user_id)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_user_campaigns_status ON user_campaigns(status)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_user_leads_user ON user_leads(user_id)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_user_leads_campaign ON user_leads(campaign_id)");
echo "✓ Created indexes\n";

echo "\n========================================\n";
echo "  Migration Complete!\n";
echo "========================================\n";
