<?php
/**
 * عرض حي لعملية البحث
 */

require_once __DIR__ . '/config/db.php';

echo "╔═══════════════════════════════════════════════════════════╗\n";
echo "║           تجربة عملية البحث عن العملاء المحتملين          ║\n";
echo "╚═══════════════════════════════════════════════════════════╝\n\n";

$pdo = db();

// إنشاء token صالح
$stmt = $pdo->query("
    SELECT user_id FROM public_sessions ps 
    JOIN public_users pu ON ps.user_id = pu.id 
    WHERE ps.expires_at > datetime('now') AND pu.status = 'active' 
    LIMIT 1
");
$session = $stmt->fetch();
$token = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $token);
$stmt = $pdo->prepare("INSERT INTO public_sessions (user_id, token_hash, expires_at, device_info) VALUES (?, ?, datetime('now', '+1 hour'), 'Demo')");
$stmt->execute([$session['user_id'], $tokenHash]);

$baseUrl = "http://localhost:8080/v1/api/public/leads/search.php";

function search($url, $token)
{
    $response = @file_get_contents($url, false, stream_context_create([
        'http' => ['header' => "Authorization: Bearer $token", 'timeout' => 10]
    ]));
    return json_decode($response, true);
}

// 1. البحث الأساسي
echo "📋 بحث أساسي (بدون فلاتر)\n";
echo str_repeat("─", 55) . "\n";
$data = search($baseUrl . "?page=1&limit=5", $token);

if ($data && $data['ok']) {
    echo "✅ إجمالي النتائج: " . $data['pagination']['total'] . " عميل محتمل\n";
    echo "📄 الصفحة: " . $data['pagination']['page'] . " من " . $data['pagination']['pages'] . "\n\n";

    echo "أول 5 نتائج:\n";
    foreach ($data['leads'] as $i => $lead) {
        echo "  " . ($i + 1) . ". " . $lead['name'] . "\n";
        echo "     📍 " . ($lead['city'] ?: 'غير محدد') . " | ";
        echo "🏷️ " . ($lead['category']['name'] ?? 'غير مصنف') . "\n";
    }
}

// 2. البحث بالنص
echo "\n\n🔍 بحث نصي: \"مطعم\"\n";
echo str_repeat("─", 55) . "\n";
$data = search($baseUrl . "?search=" . urlencode('مطعم') . "&limit=5", $token);
if ($data && $data['ok']) {
    echo "✅ نتائج البحث: " . $data['pagination']['total'] . " نتيجة\n\n";
    foreach ($data['leads'] as $i => $lead) {
        echo "  " . ($i + 1) . ". " . $lead['name'] . "\n";
    }
}

// 3. البحث بالمدينة
echo "\n\n🏙️ بحث بالمدينة: \"المدينة\"\n";
echo str_repeat("─", 55) . "\n";
$data = search($baseUrl . "?city=" . urlencode('المدينة') . "&limit=5", $token);
if ($data && $data['ok']) {
    echo "✅ نتائج البحث: " . $data['pagination']['total'] . " نتيجة\n\n";
    foreach ($data['leads'] as $i => $lead) {
        echo "  " . ($i + 1) . ". " . $lead['name'] . "\n";
        echo "     📍 " . ($lead['city'] ?: 'غير محدد') . "\n";
    }
}

// 4. عرض بيانات lead كاملة
echo "\n\n📊 تفاصيل عميل محتمل (مثال)\n";
echo str_repeat("─", 55) . "\n";
$data = search($baseUrl . "?limit=1", $token);
if ($data && $data['ok'] && !empty($data['leads'])) {
    $lead = $data['leads'][0];
    echo "الاسم: " . $lead['name'] . "\n";
    echo "المدينة: " . ($lead['city'] ?: 'غير محدد') . "\n";
    echo "الدولة: " . ($lead['country'] ?: 'غير محدد') . "\n";
    echo "التصنيف: " . ($lead['category']['name'] ?? 'غير مصنف') . "\n";
    echo "التقييم: " . ($lead['rating'] ?? 'N/A') . "\n";
    echo "الهاتف متاح للكشف: " . ($lead['phone_available'] ? 'نعم ✅' : 'لا ❌') . "\n";
    echo "البريد متاح للكشف: " . ($lead['email_available'] ? 'نعم ✅' : 'لا ❌') . "\n";
    echo "الاشتراك الحالي: " . $data['subscription']['name'] . "\n";
}

echo "\n╔═══════════════════════════════════════════════════════════╗\n";
echo "║              ✅ عملية البحث تمت بنجاح                     ║\n";
echo "╚═══════════════════════════════════════════════════════════╝\n";
