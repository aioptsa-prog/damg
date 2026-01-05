<?php
/**
 * Enable Integration Feature Flags
 * Sprint 2.3: تفعيل Feature Flags للتكامل
 * 
 * Usage: php ops/enable_integration_flags.php [--dry-run]
 */

require_once __DIR__ . '/../bootstrap.php';

$dryRun = in_array('--dry-run', $argv ?? []);

echo "=== Integration Feature Flags Setup ===\n";
echo $dryRun ? "[DRY RUN MODE]\n\n" : "\n";

$pdo = db();

// Define flags to enable
$flags = [
    'integration_auth_bridge' => '1',      // Auth bridge between OP-Target and Forge
    'integration_survey_from_lead' => '1', // Generate surveys from leads
    'integration_send_from_report' => '1', // Send WhatsApp from reports
    'integration_unified_lead_view' => '1', // Unified lead view
    'integration_worker_enabled' => '1',   // Worker integration
    'integration_instagram_enabled' => '0', // Instagram (keep disabled for now)
];

echo "Flags to configure:\n";
foreach ($flags as $key => $value) {
    $status = $value === '1' ? '✅ ENABLED' : '❌ DISABLED';
    echo "  - $key: $status\n";
}
echo "\n";

if ($dryRun) {
    echo "[DRY RUN] No changes made.\n";
    exit(0);
}

// Upsert each flag
$stmt = $pdo->prepare("
    INSERT INTO settings (key, value) 
    VALUES (?, ?) 
    ON CONFLICT(key) DO UPDATE SET value = excluded.value
");

$updated = 0;
foreach ($flags as $key => $value) {
    try {
        $stmt->execute([$key, $value]);
        $updated++;
        echo "✓ Set $key = $value\n";
    } catch (Exception $e) {
        echo "✗ Failed to set $key: " . $e->getMessage() . "\n";
    }
}

echo "\n=== Summary ===\n";
echo "Updated $updated flags.\n";

// Verify
echo "\n=== Verification ===\n";
require_once __DIR__ . '/../lib/flags.php';
$all = integration_flags_all();
foreach ($all as $name => $enabled) {
    $status = $enabled ? '✅' : '❌';
    echo "$status $name\n";
}

echo "\nDone.\n";
