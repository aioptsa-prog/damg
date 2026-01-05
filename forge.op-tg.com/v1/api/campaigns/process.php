<?php
/**
 * Campaign Job Processor
 * يُستدعى بشكل دوري لمعالجة jobs معلقة
 * GET /v1/api/campaigns/process.php
 * Security: CORS Allowlist
 */

require_once __DIR__ . '/../../../lib/cors.php';
handle_cors(['GET', 'OPTIONS']);
header('Content-Type: application/json');

require_once __DIR__ . '/../bootstrap_api.php';
require_once __DIR__ . '/../../../lib/auth.php';

// Require authentication
$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'UNAUTHORIZED']);
    exit;
}

try {
    $pdo = db();

    // التحقق من وجود الجداول المطلوبة
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='internal_jobs'")->fetchColumn();
    if (!$tables) {
        echo json_encode([
            'ok' => true,
            'processed' => 0,
            'message' => 'لا توجد jobs - الجدول غير موجود'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // جلب job معلقة للمستخدم الحالي
    $stmt = $pdo->prepare("
        SELECT j.*, c.id as campaign_id, c.name as campaign_name
        FROM internal_jobs j
        INNER JOIN user_campaigns c ON c.internal_job_id = j.id
        WHERE c.user_id = ?
          AND j.status IN ('queued', 'processing')
        ORDER BY j.queued_at ASC
        LIMIT 1
    ");
    $stmt->execute([$user['id']]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$job) {
        echo json_encode([
            'ok' => true,
            'processed' => 0,
            'message' => 'لا توجد jobs معلقة'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // تحديث حالة الـ job إلى processing
    if ($job['status'] === 'queued') {
        $pdo->prepare("
            UPDATE internal_jobs 
            SET status = 'processing', claimed_at = datetime('now'), updated_at = datetime('now')
            WHERE id = ?
        ")->execute([$job['id']]);

        $pdo->prepare("
            UPDATE user_campaigns 
            SET status = 'processing', started_at = datetime('now')
            WHERE id = ?
        ")->execute([$job['campaign_id']]);
    }

    // محاكاة جلب بيانات (في التطبيق الحقيقي هنا يتم استدعاء Google Maps API)
    $query = $job['query'];
    $ll = $job['ll'];
    $target = (int) ($job['target_count'] ?? 100);
    $current = (int) ($job['result_count'] ?? 0);

    // إنشاء leads وهمية للاختبار (في الإنتاج: استدعاء API خارجي)
    $mockLeads = [];
    $batchSize = min(5, $target - $current); // 5 leads كل استدعاء

    if ($batchSize > 0) {
        for ($i = 0; $i < $batchSize; $i++) {
            $mockLeads[] = [
                'name' => $query . ' - نتيجة ' . ($current + $i + 1),
                'phone' => '05' . str_pad(rand(10000000, 99999999), 8, '0', STR_PAD_LEFT),
                'city' => explode(',', $ll)[0] < 25 ? 'الرياض' : 'جدة',
                'rating' => round(3 + (rand(0, 20) / 10), 1),
                'source' => 'campaign_worker'
            ];
        }

        // حفظ الـ leads
        $savedCount = 0;
        foreach ($mockLeads as $lead) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO leads (name, phone, city, rating, source, job_id, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))
                ");
                $stmt->execute([
                    $lead['name'],
                    $lead['phone'],
                    $lead['city'],
                    $lead['rating'],
                    $lead['source'],
                    $job['id']
                ]);
                $savedCount++;
            } catch (Exception $e) {
                // تجاهل الأخطاء المكررة
                if (strpos($e->getMessage(), 'UNIQUE') === false) {
                    throw $e;
                }
            }
        }

        // تحديث الـ progress
        $newCount = $current + $savedCount;
        $progress = min(100, round(($newCount / $target) * 100));

        $pdo->prepare("
            UPDATE internal_jobs 
            SET result_count = ?, progress_count = ?, updated_at = datetime('now')
            WHERE id = ?
        ")->execute([$newCount, $progress, $job['id']]);

        $pdo->prepare("
            UPDATE user_campaigns 
            SET result_count = ?, progress_percent = ?
            WHERE id = ?
        ")->execute([$newCount, $progress, $job['campaign_id']]);

        // التحقق من الاكتمال
        if ($newCount >= $target) {
            $pdo->prepare("
                UPDATE internal_jobs 
                SET status = 'done', finished_at = datetime('now'), done_reason = 'target_reached'
                WHERE id = ?
            ")->execute([$job['id']]);

            $pdo->prepare("
                UPDATE user_campaigns 
                SET status = 'completed', completed_at = datetime('now'), progress_percent = 100
                WHERE id = ?
            ")->execute([$job['campaign_id']]);
        }

        echo json_encode([
            'ok' => true,
            'processed' => $savedCount,
            'total' => $newCount,
            'target' => $target,
            'progress' => $progress,
            'status' => $newCount >= $target ? 'completed' : 'processing',
            'campaign_id' => (int) $job['campaign_id'],
            'campaign_name' => $job['campaign_name']
        ], JSON_UNESCAPED_UNICODE);

    } else {
        // الـ target تم الوصول إليه
        echo json_encode([
            'ok' => true,
            'processed' => 0,
            'total' => $current,
            'target' => $target,
            'progress' => 100,
            'status' => 'completed',
            'message' => 'اكتملت الحملة'
        ], JSON_UNESCAPED_UNICODE);
    }

} catch (Exception $e) {
    error_log("Campaign Processor Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
