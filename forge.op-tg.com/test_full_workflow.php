<?php
/**
 * Test Full Workflow: Lead -> Snapshot -> Survey Ready
 */

require_once __DIR__ . '/bootstrap.php';

$pdo = db();

echo "=== Testing Full Workflow ===\n\n";

// 1. Check leads
echo "1. Checking leads...\n";
$leads = $pdo->query("SELECT id, name, phone, city FROM leads LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
echo "   Found " . count($leads) . " leads\n";
if (count($leads) > 0) {
    foreach ($leads as $l) {
        echo "   - ID: {$l['id']}, Name: {$l['name']}, City: {$l['city']}\n";
    }
}

// 2. Check integration jobs
echo "\n2. Checking integration jobs...\n";
$jobs = $pdo->query("SELECT id, forge_lead_id, status, modules_json, created_at FROM integration_jobs ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
echo "   Found " . count($jobs) . " jobs\n";
foreach ($jobs as $j) {
    echo "   - Job: {$j['id']}, Lead: {$j['forge_lead_id']}, Status: {$j['status']}, Modules: {$j['modules_json']}\n";
}

// 3. Check snapshots
echo "\n3. Checking snapshots...\n";
$snapshots = $pdo->query("SELECT id, forge_lead_id, source, created_at, LENGTH(snapshot_json) as size FROM lead_snapshots ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
echo "   Found " . count($snapshots) . " snapshots\n";
foreach ($snapshots as $s) {
    echo "   - Snapshot: {$s['id']}, Lead: {$s['forge_lead_id']}, Source: {$s['source']}, Size: {$s['size']} bytes\n";
}

// 4. Get a lead with snapshot for testing
echo "\n4. Finding lead with snapshot...\n";
$leadWithSnapshot = $pdo->query("
    SELECT l.id, l.name, l.phone, l.city, ls.id as snapshot_id, ls.snapshot_json
    FROM leads l
    JOIN lead_snapshots ls ON l.id = ls.forge_lead_id
    ORDER BY ls.created_at DESC
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

if ($leadWithSnapshot) {
    echo "   Found: Lead ID {$leadWithSnapshot['id']} - {$leadWithSnapshot['name']}\n";
    $snapshot = json_decode($leadWithSnapshot['snapshot_json'], true);
    echo "   Snapshot sources: " . implode(', ', $snapshot['sources'] ?? ['none']) . "\n";
    
    // Check if ai_pack exists
    if (isset($snapshot['ai_pack'])) {
        echo "   AI Pack: EXISTS\n";
        echo "   - Evidence count: " . count($snapshot['ai_pack']['evidence'] ?? []) . "\n";
        echo "   - Social links: " . implode(', ', array_keys($snapshot['ai_pack']['social_links'] ?? [])) . "\n";
    } else {
        echo "   AI Pack: NOT FOUND\n";
    }
} else {
    echo "   No lead with snapshot found!\n";
    
    // Create a test lead and job
    echo "\n5. Creating test lead and job...\n";
    
    // Get first lead
    $firstLead = $pdo->query("SELECT id, name, city FROM leads LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($firstLead) {
        echo "   Using lead: {$firstLead['id']} - {$firstLead['name']}\n";
        
        // Create integration job
        $jobId = bin2hex(random_bytes(16));
        $stmt = $pdo->prepare("
            INSERT INTO integration_jobs (id, forge_lead_id, op_lead_id, requested_by, modules_json, status)
            VALUES (?, ?, 'test-op-lead', 'test', ?, 'queued')
        ");
        $stmt->execute([$jobId, $firstLead['id'], json_encode(['maps', 'google_web'])]);
        echo "   Created job: $jobId\n";
        echo "   Worker should pick this up and create snapshot\n";
    }
}

// 5. Check settings
echo "\n5. Checking AI settings...\n";
$internal = get_setting('internal_secret', '');
echo "   Internal secret: " . (strlen($internal) > 0 ? "SET (" . strlen($internal) . " chars)" : "NOT SET") . "\n";

$workerEnabled = get_setting('integration_worker_enabled', '0');
echo "   Worker enabled: $workerEnabled\n";

$googleWebEnabled = get_setting('google_web_enabled', '0');
echo "   Google Web enabled: $googleWebEnabled\n";

echo "\n=== Workflow Test Complete ===\n";
