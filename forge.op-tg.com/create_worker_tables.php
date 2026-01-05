<?php
require_once __DIR__ . '/bootstrap.php';

$pdo = db();

echo "Creating integration tables...\n";

// Create integration_jobs
$pdo->exec("
CREATE TABLE IF NOT EXISTS integration_jobs (
    id TEXT PRIMARY KEY,
    forge_lead_id INTEGER NOT NULL,
    op_lead_id TEXT NOT NULL,
    requested_by TEXT NOT NULL,
    modules_json TEXT NOT NULL DEFAULT '[]',
    options_json TEXT DEFAULT '{}',
    status TEXT NOT NULL DEFAULT 'queued',
    progress INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    started_at TEXT,
    finished_at TEXT,
    last_error TEXT,
    correlation_id TEXT
)
");
echo "- integration_jobs: OK\n";

// Create integration_job_runs
$pdo->exec("
CREATE TABLE IF NOT EXISTS integration_job_runs (
    id TEXT PRIMARY KEY,
    job_id TEXT NOT NULL,
    module TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    attempt INTEGER NOT NULL DEFAULT 0,
    started_at TEXT,
    finished_at TEXT,
    error_code TEXT,
    error_message TEXT,
    output_json TEXT
)
");
echo "- integration_job_runs: OK\n";

// Create lead_snapshots
$pdo->exec("
CREATE TABLE IF NOT EXISTS lead_snapshots (
    id TEXT PRIMARY KEY,
    forge_lead_id INTEGER NOT NULL,
    job_id TEXT,
    source TEXT NOT NULL DEFAULT 'worker',
    snapshot_json TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
)
");
echo "- lead_snapshots: OK\n";

// Create indexes
$indexes = [
    "CREATE INDEX IF NOT EXISTS idx_integration_jobs_status ON integration_jobs(status)",
    "CREATE INDEX IF NOT EXISTS idx_integration_jobs_forge_lead ON integration_jobs(forge_lead_id)",
    "CREATE INDEX IF NOT EXISTS idx_integration_job_runs_job ON integration_job_runs(job_id)",
    "CREATE INDEX IF NOT EXISTS idx_lead_snapshots_forge_lead ON lead_snapshots(forge_lead_id)",
];

foreach ($indexes as $idx) {
    try {
        $pdo->exec($idx);
    } catch (Exception $e) {}
}
echo "- Indexes: OK\n";

// Add settings
$pdo->exec("INSERT OR IGNORE INTO settings (key, value) VALUES ('integration_worker_enabled', '1')");
$pdo->exec("INSERT OR IGNORE INTO settings (key, value) VALUES ('integration_worker_concurrency', '1')");
$pdo->exec("INSERT OR IGNORE INTO settings (key, value) VALUES ('integration_worker_max_jobs_per_user_day', '20')");
echo "- Settings: OK\n";

// Verify
$tables = ['integration_jobs', 'integration_job_runs', 'lead_snapshots'];
echo "\nVerification:\n";
foreach ($tables as $t) {
    $r = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='$t'")->fetch();
    echo "  $t: " . ($r ? "EXISTS" : "MISSING") . "\n";
}

echo "\nDone!\n";
