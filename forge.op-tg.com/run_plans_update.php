<?php
/**
 * تنفيذ تحديث الباقات الواقعية
 * Run: php run_plans_update.php
 */

require_once __DIR__ . '/config/db.php';

echo "===========================================\n";
echo "   تحديث الباقات لتتوافق مع الميزات الفعلية\n";
echo "===========================================\n\n";

try {
    $pdo = db();

    // قراءة الـ migration
    $sql = file_get_contents(__DIR__ . '/migrations/004_update_realistic_plans.sql');

    // تنفيذ كل جملة على حدة
    $statements = explode(';', $sql);

    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (empty($statement) || strpos($statement, '--') === 0)
            continue;

        // تخطي التعليقات
        $lines = explode("\n", $statement);
        $cleanLines = array_filter($lines, function ($line) {
            return strpos(trim($line), '--') !== 0;
        });
        $cleanStatement = trim(implode("\n", $cleanLines));

        if (!empty($cleanStatement)) {
            try {
                $pdo->exec($cleanStatement);
            } catch (PDOException $e) {
                // تجاهل أخطاء معينة
                if (strpos($e->getMessage(), 'UNIQUE constraint') === false) {
                    echo "⚠️  تحذير: " . $e->getMessage() . "\n";
                }
            }
        }
    }

    echo "✅ تم تحديث الباقات بنجاح!\n\n";

    // عرض الباقات الجديدة
    echo "الباقات الحالية:\n";
    echo str_repeat("-", 60) . "\n";

    $stmt = $pdo->query("SELECT * FROM subscription_plans ORDER BY sort_order");
    $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($plans as $plan) {
        echo "\n📦 {$plan['name']} ({$plan['slug']})\n";
        echo "   السعر: {$plan['price_monthly']} ر.س/شهر | {$plan['price_yearly']} ر.س/سنة\n";
        echo "   كشف هاتف: " . ($plan['credits_phone'] == 0 ? 'غير محدود' : $plan['credits_phone']) . "\n";

        $features = json_decode($plan['features'], true);
        if ($features) {
            echo "   الميزات:\n";
            foreach ($features as $f) {
                echo "     • {$f}\n";
            }
        }
    }

    echo "\n" . str_repeat("=", 60) . "\n";
    echo "تم التحديث بنجاح! ✅\n";

} catch (Exception $e) {
    echo "❌ خطأ: " . $e->getMessage() . "\n";
    exit(1);
}
