<?php
/**
 * Test WhatsApp API and add template directly
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config/db.php';

echo "=== WhatsApp Tables Test ===\n\n";

$pdo = db();

// Check if tables exist
$tables = ['whatsapp_settings', 'whatsapp_templates', 'whatsapp_logs'];
foreach ($tables as $table) {
    $result = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='$table'")->fetch();
    if ($result) {
        echo "✅ Table '$table' exists\n";
    } else {
        echo "❌ Table '$table' MISSING - Creating...\n";

        if ($table === 'whatsapp_settings') {
            $pdo->exec("
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
                )
            ");
        } elseif ($table === 'whatsapp_templates') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS whatsapp_templates (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL,
                    name TEXT NOT NULL,
                    content_type TEXT DEFAULT 'text',
                    message_text TEXT,
                    media_url TEXT,
                    is_default INTEGER DEFAULT 0,
                    is_active INTEGER DEFAULT 1,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
                )
            ");
        } elseif ($table === 'whatsapp_logs') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS whatsapp_logs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL,
                    lead_id INTEGER,
                    template_id INTEGER,
                    recipient_number TEXT NOT NULL,
                    recipient_name TEXT,
                    message_text TEXT,
                    content_type TEXT DEFAULT 'text',
                    status TEXT DEFAULT 'pending',
                    api_response TEXT,
                    error_message TEXT,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP
                )
            ");
        }
        echo "   Created '$table'\n";
    }
}

echo "\n=== Adding Test Template ===\n";

// Get first public user
$user = $pdo->query("SELECT * FROM public_users LIMIT 1")->fetch();
if (!$user) {
    echo "❌ No public users found!\n";
    exit;
}
echo "Using user: {$user['name']} (ID: {$user['id']})\n";

// Check existing templates
$templates = $pdo->query("SELECT * FROM whatsapp_templates WHERE user_id = {$user['id']} OR user_id = 0")->fetchAll();
echo "Existing templates: " . count($templates) . "\n";

// Add the requested template
$templateName = "عروض بداية العام";
$templateText = "مرحبا {{name}}\nعروض بداية العام عندنا ما تتفوت\nتواصل معنا الان واحصل علي العرض";

$stmt = $pdo->prepare("INSERT INTO whatsapp_templates (user_id, name, content_type, message_text, is_default) VALUES (?, ?, 'text', ?, 1)");
$stmt->execute([$user['id'], $templateName, $templateText]);
$newId = $pdo->lastInsertId();

echo "\n✅ Template added successfully!\n";
echo "   ID: $newId\n";
echo "   Name: $templateName\n";
echo "   Text: " . substr($templateText, 0, 50) . "...\n";

// List all templates now
echo "\n=== All Templates ===\n";
$templates = $pdo->query("SELECT * FROM whatsapp_templates")->fetchAll(PDO::FETCH_ASSOC);
foreach ($templates as $t) {
    echo "- [{$t['id']}] {$t['name']} (user: {$t['user_id']})\n";
}

echo "\n✅ Done!\n";
