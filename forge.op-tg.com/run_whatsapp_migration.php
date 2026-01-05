<?php
/**
 * Run WhatsApp Tables Migration
 */

require_once __DIR__ . '/config/db.php';

try {
    $pdo = db();
    $sql = file_get_contents(__DIR__ . '/migrations/002_whatsapp_tables.sql');

    // Execute each statement separately
    $statements = array_filter(array_map('trim', explode(';', $sql)));

    foreach ($statements as $statement) {
        if (!empty($statement)) {
            $pdo->exec($statement);
        }
    }

    echo "✅ WhatsApp tables migration completed successfully!\n";

    // Verify tables were created
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'whatsapp%'")->fetchAll(PDO::FETCH_COLUMN);
    echo "Created tables: " . implode(', ', $tables) . "\n";

} catch (Exception $e) {
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
