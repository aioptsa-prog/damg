<?php
/**
 * Create a real search job for testing
 */

require_once __DIR__ . '/../bootstrap.php';

$pdo = db();

// إنشاء job بحث حقيقي
$query = 'مطاعم برجر الرياض';
$ll = '24.7136,46.6753'; // الرياض

$stmt = $pdo->prepare("
    INSERT INTO internal_jobs (query, ll, status, target_count, created_at, updated_at, queued_at, requested_by_user_id, role, radius_km, lang, region)
    VALUES (?, ?, 'queued', 10, datetime('now'), datetime('now'), datetime('now'), 1, 'admin', 15, 'ar', 'sa')
");
$stmt->execute([$query, $ll]);
$jobId = $pdo->lastInsertId();

echo "✓ Created search job ID: $jobId\n";
echo "  Query: $query\n";
echo "  Location: $ll (الرياض)\n";
echo "  Target: 10 leads\n";

// عرض حالة الـ job
$stmt = $pdo->prepare('SELECT * FROM internal_jobs WHERE id = ?');
$stmt->execute([$jobId]);
$job = $stmt->fetch(PDO::FETCH_ASSOC);
echo "\n  Status: " . $job['status'] . "\n";
echo "\nJob created successfully! Worker can now pull this job.\n";
