<?php
/**
 * Run Integration Auth Bridge Migration
 * Execute: php run_integration_migration.php
 */

require_once __DIR__ . '/bootstrap.php';

$pdo = db();

echo "Running integration auth bridge migration...\n";

// Execute each statement directly
$statements = [
    "INSERT OR IGNORE INTO settings (key, value) VALUES ('integration_shared_secret', '')",
    "INSERT OR IGNORE INTO settings (key, value) VALUES ('integration_auth_bridge', '0')",
    "CREATE TABLE IF NOT EXISTS integration_nonces (
        nonce TEXT PRIMARY KEY,
        issuer TEXT NOT NULL,
        sub TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        expires_at TEXT NOT NULL
    )",
    "CREATE INDEX IF NOT EXISTS idx_integration_nonces_expires ON integration_nonces(expires_at)",
    "CREATE TABLE IF NOT EXISTS integration_sessions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        token TEXT UNIQUE NOT NULL,
        op_target_user_id TEXT NOT NULL,
        forge_role TEXT NOT NULL DEFAULT 'agent',
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        expires_at TEXT NOT NULL,
        last_used_at TEXT,
        metadata TEXT
    )",
    "CREATE INDEX IF NOT EXISTS idx_integration_sessions_token ON integration_sessions(token)",
    "CREATE INDEX IF NOT EXISTS idx_integration_sessions_expires ON integration_sessions(expires_at)",
];

foreach ($statements as $stmt) {
    try {
        $pdo->exec($stmt);
        echo "OK: " . substr(preg_replace('/\s+/', ' ', $stmt), 0, 60) . "...\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'already exists') !== false) {
            echo "SKIP (exists): " . substr($stmt, 0, 40) . "...\n";
        } else {
            echo "ERROR: " . $e->getMessage() . "\n";
        }
    }
}

echo "\nMigration complete!\n";

// Verify tables exist
$tables = ['integration_nonces', 'integration_sessions'];
foreach ($tables as $table) {
    $result = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='$table'")->fetch();
    echo "Table $table: " . ($result ? "EXISTS" : "MISSING") . "\n";
}

// Check settings
$secret = get_setting('integration_shared_secret', 'NOT_SET');
echo "Setting integration_shared_secret: " . ($secret === '' ? 'EMPTY (needs configuration)' : ($secret === 'NOT_SET' ? 'NOT_SET' : 'CONFIGURED')) . "\n";

$flag = get_setting('integration_auth_bridge', 'NOT_SET');
echo "Setting integration_auth_bridge: " . $flag . "\n";
