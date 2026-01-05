<?php
/**
 * Integration Cleanup Script
 * Phase 6: Cleanup old jobs, runs, snapshots, nonces, tokens
 * 
 * Run via cron: 0 3 * * * php /path/to/cleanup_integration.php
 * Or manually: php cleanup_integration.php
 */

require_once __DIR__ . '/../bootstrap.php';

$pdo = db();
$dryRun = in_array('--dry-run', $argv ?? []);

echo "Integration Cleanup Script\n";
echo "==========================\n";
echo $dryRun ? "[DRY RUN MODE]\n\n" : "\n";

$stats = [
    'jobs' => 0,
    'job_runs' => 0,
    'snapshots' => 0,
    'nonces' => 0,
    'sessions' => 0,
];

// === 1. Delete old job_runs (30 days) ===
echo "1. Cleaning old job_runs (>30 days)...\n";
$stmt = $pdo->query("SELECT COUNT(*) as cnt FROM integration_job_runs WHERE finished_at < datetime('now', '-30 days')");
$count = (int) $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
echo "   Found: $count records\n";

if (!$dryRun && $count > 0) {
    $pdo->exec("DELETE FROM integration_job_runs WHERE finished_at < datetime('now', '-30 days')");
    $stats['job_runs'] = $count;
}

// === 2. Delete old jobs (30 days) ===
echo "2. Cleaning old jobs (>30 days)...\n";
$stmt = $pdo->query("SELECT COUNT(*) as cnt FROM integration_jobs WHERE finished_at < datetime('now', '-30 days')");
$count = (int) $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
echo "   Found: $count records\n";

if (!$dryRun && $count > 0) {
    $pdo->exec("DELETE FROM integration_jobs WHERE finished_at < datetime('now', '-30 days')");
    $stats['jobs'] = $count;
}

// === 3. Keep only last 3 snapshots per lead ===
echo "3. Cleaning old snapshots (keep last 3 per lead)...\n";
$stmt = $pdo->query("
    SELECT COUNT(*) as cnt FROM lead_snapshots 
    WHERE id NOT IN (
        SELECT id FROM (
            SELECT id, ROW_NUMBER() OVER (PARTITION BY forge_lead_id ORDER BY created_at DESC) as rn
            FROM lead_snapshots
        ) WHERE rn <= 3
    )
");
$count = (int) $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
echo "   Found: $count records\n";

if (!$dryRun && $count > 0) {
    $pdo->exec("
        DELETE FROM lead_snapshots 
        WHERE id NOT IN (
            SELECT id FROM (
                SELECT id, ROW_NUMBER() OVER (PARTITION BY forge_lead_id ORDER BY created_at DESC) as rn
                FROM lead_snapshots
            ) WHERE rn <= 3
        )
    ");
    $stats['snapshots'] = $count;
}

// === 4. Delete expired nonces ===
echo "4. Cleaning expired nonces...\n";
$stmt = $pdo->query("SELECT COUNT(*) as cnt FROM integration_nonces WHERE expires_at < datetime('now')");
$count = (int) $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
echo "   Found: $count records\n";

if (!$dryRun && $count > 0) {
    $pdo->exec("DELETE FROM integration_nonces WHERE expires_at < datetime('now')");
    $stats['nonces'] = $count;
}

// === 5. Delete expired sessions ===
echo "5. Cleaning expired sessions...\n";
$stmt = $pdo->query("SELECT COUNT(*) as cnt FROM integration_sessions WHERE expires_at < datetime('now')");
$count = (int) $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
echo "   Found: $count records\n";

if (!$dryRun && $count > 0) {
    $pdo->exec("DELETE FROM integration_sessions WHERE expires_at < datetime('now')");
    $stats['sessions'] = $count;
}

// === Summary ===
echo "\n==========================\n";
echo "Cleanup Summary:\n";
echo "  Jobs deleted: {$stats['jobs']}\n";
echo "  Job runs deleted: {$stats['job_runs']}\n";
echo "  Snapshots deleted: {$stats['snapshots']}\n";
echo "  Nonces deleted: {$stats['nonces']}\n";
echo "  Sessions deleted: {$stats['sessions']}\n";
echo "==========================\n";

if ($dryRun) {
    echo "\n[DRY RUN] No changes made. Run without --dry-run to apply.\n";
}

echo "Done!\n";
