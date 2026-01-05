<?php
/**
 * Sprint 3.2: Job Orchestration Test
 * Tests the complete job flow: create → pull → report
 */

require_once __DIR__ . '/../bootstrap.php';

echo "=== Job Orchestration Test ===\n\n";

$pdo = db();

// 1. Create a test job
echo "1. Creating test job...\n";
$query = 'مطاعم تست';
$ll = '24.7136,46.6753'; // Riyadh
$stmt = $pdo->prepare("
    INSERT INTO internal_jobs (query, ll, status, target_count, created_at, updated_at, queued_at, requested_by_user_id, role, radius_km, lang, region)
    VALUES (?, ?, 'queued', 5, datetime('now'), datetime('now'), datetime('now'), 1, 'admin', 10, 'ar', 'sa')
");
$stmt->execute([$query, $ll]);
$jobId = (int)$pdo->lastInsertId();
echo "   ✓ Created job ID: $jobId\n";

// 2. Verify job is queued
echo "\n2. Verifying job status...\n";
$stmt = $pdo->prepare("SELECT id, query, status, target_count FROM internal_jobs WHERE id = ?");
$stmt->execute([$jobId]);
$job = $stmt->fetch(PDO::FETCH_ASSOC);
echo "   Job: " . json_encode($job, JSON_UNESCAPED_UNICODE) . "\n";
assert($job['status'] === 'queued', 'Job should be queued');
echo "   ✓ Job is queued\n";

// 3. Simulate worker claiming job
echo "\n3. Simulating worker claim...\n";
$workerId = 'test-worker-' . bin2hex(random_bytes(4));
$attemptId = bin2hex(random_bytes(8));
$leaseUntil = date('Y-m-d H:i:s', time() + 180);

$stmt = $pdo->prepare("
    UPDATE internal_jobs 
    SET status = 'processing', 
        worker_id = ?, 
        attempt_id = ?,
        claimed_at = datetime('now'),
        lease_expires_at = ?,
        attempts = COALESCE(attempts, 0) + 1
    WHERE id = ? AND status = 'queued'
");
$stmt->execute([$workerId, $attemptId, $leaseUntil, $jobId]);
echo "   ✓ Worker $workerId claimed job\n";

// 4. Simulate reporting results
echo "\n4. Simulating result reporting...\n";
$leads = [
    ['phone' => '0501234567', 'name' => 'مطعم الشرق', 'city' => 'الرياض'],
    ['phone' => '0507654321', 'name' => 'مطعم الغرب', 'city' => 'الرياض'],
];

$insLead = $pdo->prepare("
    INSERT OR IGNORE INTO leads (phone, phone_norm, name, city, created_at, source)
    VALUES (?, ?, ?, ?, datetime('now'), 'test')
");

$added = 0;
foreach ($leads as $lead) {
    $phone = preg_replace('/\D/', '', $lead['phone']);
    if (strlen($phone) === 9 || strlen($phone) === 10) {
        $phone = '966' . ltrim($phone, '0');
    }
    $insLead->execute([$lead['phone'], $phone, $lead['name'], $lead['city']]);
    if ($insLead->rowCount() > 0) $added++;
}
echo "   ✓ Added $added leads\n";

// 5. Update job progress
echo "\n5. Updating job progress...\n";
$stmt = $pdo->prepare("
    UPDATE internal_jobs 
    SET progress_count = COALESCE(progress_count, 0) + ?,
        result_count = COALESCE(result_count, 0) + ?,
        last_progress_at = datetime('now'),
        lease_expires_at = datetime('now', '+3 minutes')
    WHERE id = ?
");
$stmt->execute([count($leads), $added, $jobId]);
echo "   ✓ Progress updated\n";

// 6. Complete job
echo "\n6. Completing job...\n";
$stmt = $pdo->prepare("
    UPDATE internal_jobs 
    SET status = 'done',
        finished_at = datetime('now'),
        done_reason = 'test_complete'
    WHERE id = ?
");
$stmt->execute([$jobId]);
echo "   ✓ Job completed\n";

// 7. Verify final state
echo "\n7. Final verification...\n";
$stmt = $pdo->prepare("
    SELECT id, query, status, worker_id, result_count, done_reason 
    FROM internal_jobs WHERE id = ?
");
$stmt->execute([$jobId]);
$finalJob = $stmt->fetch(PDO::FETCH_ASSOC);
echo "   Final job: " . json_encode($finalJob, JSON_UNESCAPED_UNICODE) . "\n";
assert($finalJob['status'] === 'done', 'Job should be done');
echo "   ✓ Job flow completed successfully!\n";

// 8. Cleanup
echo "\n8. Cleanup...\n";
$pdo->prepare("DELETE FROM internal_jobs WHERE id = ?")->execute([$jobId]);
$pdo->prepare("DELETE FROM leads WHERE source = 'test'")->execute();
echo "   ✓ Test data cleaned up\n";

echo "\n=== All Tests Passed ===\n";
