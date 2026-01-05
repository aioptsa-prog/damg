<?php
/**
 * Simple Metrics Dashboard (Admin)
 * Sprint 4D: Internal monitoring page
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/auth.php';

// Require admin login
if (!is_logged_in() || !is_admin()) {
    header('Location: /login.php');
    exit;
}

$pdo = db();

// Fetch metrics
$metrics = [];

// Jobs summary
$stmt = $pdo->query("
    SELECT 
        SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) as queued,
        SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
        SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) as done,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
    FROM internal_jobs
    WHERE created_at > datetime('now', '-24 hours')
");
$metrics['jobs'] = $stmt->fetch(PDO::FETCH_ASSOC);

// Leads today
$stmt = $pdo->query("SELECT COUNT(*) FROM leads WHERE created_at > datetime('now', '-24 hours')");
$metrics['leads_today'] = $stmt->fetchColumn();

// Active workers
$stmt = $pdo->query("SELECT COUNT(DISTINCT worker_id) FROM internal_jobs WHERE status = 'processing'");
$metrics['active_workers'] = $stmt->fetchColumn();

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة المراقبة - Forge</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        // Auto-refresh every 30 seconds
        setTimeout(() => location.reload(), 30000);
    </script>
</head>
<body class="bg-gray-900 text-white min-h-screen p-8">
    <div class="max-w-6xl mx-auto">
        <h1 class="text-3xl font-bold mb-8">📊 لوحة المراقبة</h1>
        
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <!-- Queue Depth -->
            <div class="bg-gray-800 rounded-xl p-6">
                <div class="text-gray-400 text-sm mb-2">في الانتظار</div>
                <div class="text-4xl font-bold text-yellow-400"><?= $metrics['jobs']['queued'] ?? 0 ?></div>
            </div>
            
            <!-- Processing -->
            <div class="bg-gray-800 rounded-xl p-6">
                <div class="text-gray-400 text-sm mb-2">قيد التنفيذ</div>
                <div class="text-4xl font-bold text-blue-400"><?= $metrics['jobs']['processing'] ?? 0 ?></div>
            </div>
            
            <!-- Done -->
            <div class="bg-gray-800 rounded-xl p-6">
                <div class="text-gray-400 text-sm mb-2">مكتمل (24 ساعة)</div>
                <div class="text-4xl font-bold text-green-400"><?= $metrics['jobs']['done'] ?? 0 ?></div>
            </div>
            
            <!-- Failed -->
            <div class="bg-gray-800 rounded-xl p-6">
                <div class="text-gray-400 text-sm mb-2">فشل</div>
                <div class="text-4xl font-bold text-red-400"><?= $metrics['jobs']['failed'] ?? 0 ?></div>
            </div>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <!-- Leads Today -->
            <div class="bg-gray-800 rounded-xl p-6">
                <div class="text-gray-400 text-sm mb-2">عملاء جدد (24 ساعة)</div>
                <div class="text-4xl font-bold text-purple-400"><?= $metrics['leads_today'] ?></div>
            </div>
            
            <!-- Active Workers -->
            <div class="bg-gray-800 rounded-xl p-6">
                <div class="text-gray-400 text-sm mb-2">عمال نشطين</div>
                <div class="text-4xl font-bold text-cyan-400"><?= $metrics['active_workers'] ?></div>
            </div>
            
            <!-- Failure Rate -->
            <?php
            $total = array_sum($metrics['jobs'] ?? []);
            $failRate = $total > 0 ? round(($metrics['jobs']['failed'] ?? 0) / $total * 100, 1) : 0;
            ?>
            <div class="bg-gray-800 rounded-xl p-6">
                <div class="text-gray-400 text-sm mb-2">نسبة الفشل</div>
                <div class="text-4xl font-bold <?= $failRate > 20 ? 'text-red-400' : 'text-green-400' ?>"><?= $failRate ?>%</div>
            </div>
        </div>
        
        <div class="text-gray-500 text-sm">
            آخر تحديث: <?= date('Y-m-d H:i:s') ?> | يتم التحديث تلقائياً كل 30 ثانية
        </div>
    </div>
</body>
</html>
