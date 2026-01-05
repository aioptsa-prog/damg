<?php
/**
 * Phase 7: Google Web Module Migration
 */

require_once __DIR__ . '/bootstrap.php';

$pdo = db();

echo "Running Phase 7 migration (Google Web Module)...\n";
echo "=================================================\n\n";

// Create google_web_cache table
$pdo->exec("
CREATE TABLE IF NOT EXISTS google_web_cache (
    id TEXT PRIMARY KEY,
    query_hash TEXT NOT NULL UNIQUE,
    query TEXT NOT NULL,
    provider TEXT NOT NULL DEFAULT 'serpapi',
    results_json TEXT NOT NULL DEFAULT '[]',
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    expires_at TEXT NOT NULL
)
");
echo "- google_web_cache: OK\n";

// Create google_web_usage table
$pdo->exec("
CREATE TABLE IF NOT EXISTS google_web_usage (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    date TEXT NOT NULL,
    provider TEXT NOT NULL,
    count INTEGER NOT NULL DEFAULT 0,
    UNIQUE(date, provider)
)
");
echo "- google_web_usage: OK\n";

// Create indexes
$indexes = [
    "CREATE INDEX IF NOT EXISTS idx_google_web_cache_hash ON google_web_cache(query_hash)",
    "CREATE INDEX IF NOT EXISTS idx_google_web_cache_expires ON google_web_cache(expires_at)",
    "CREATE INDEX IF NOT EXISTS idx_google_web_usage_date ON google_web_usage(date)",
];
foreach ($indexes as $idx) {
    try { $pdo->exec($idx); } catch (Exception $e) {}
}
echo "- Indexes: OK\n";

// Add settings
$settings = [
    ['google_web_enabled', '1'],
    ['google_web_fallback_enabled', '0'],
    ['google_web_max_per_day', '100'],
    ['google_web_fallback_max_per_day', '10'],
    ['google_web_cache_hours', '24'],
    ['google_web_max_results', '10'],
    ['integration_allowed_modules', 'maps,website,google_web'],
];

foreach ($settings as [$key, $value]) {
    $stmt = $pdo->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)");
    $stmt->execute([$key, $value]);
}
echo "- Settings: OK\n";

// Verify
echo "\nVerification:\n";
$tables = ['google_web_cache', 'google_web_usage'];
foreach ($tables as $t) {
    $r = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='$t'")->fetch();
    echo "  $t: " . ($r ? "EXISTS" : "MISSING") . "\n";
}

echo "\nSettings:\n";
foreach (['google_web_enabled', 'google_web_fallback_enabled', 'google_web_max_per_day'] as $key) {
    echo "  $key: " . get_setting($key, 'NOT_SET') . "\n";
}

echo "\nPhase 7 migration complete!\n";
