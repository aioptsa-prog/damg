<?php
/**
 * Lead Enrichment API
 * POST /v1/api/leads/enrich.php
 * 
 * يستقبل بيانات عميل ويُثريها من مصادر متعددة
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../../../bootstrap.php';
require_once __DIR__ . '/../../../lib/api_auth.php';

// التحقق من الصلاحيات
$user = require_api_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// قراءة البيانات
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

// التحقق من المدخلات
$leadId = $input['leadId'] ?? null;
$lead = $input['lead'] ?? null;

if (!$leadId && !$lead) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Either leadId or lead object is required']);
    exit;
}

// جلب بيانات العميل من قاعدة البيانات إذا تم تمرير leadId
if ($leadId) {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id, name, phone, city, category_id FROM leads WHERE id = ?");
    $stmt->execute([$leadId]);
    $dbLead = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$dbLead) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Lead not found']);
        exit;
    }
    
    $lead = [
        'name' => $dbLead['name'],
        'phone' => $dbLead['phone'],
        'city' => $dbLead['city'],
        'category' => $dbLead['category_id'] ? getCategoryName($dbLead['category_id']) : null
    ];
}

// التحقق من الاسم
if (empty($lead['name'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Lead name is required']);
    exit;
}

// تشغيل Lead Enricher
$enricherPath = __DIR__ . '/../../../lib/lead_enricher';
$leadJsonFile = tempnam(sys_get_temp_dir(), 'lead_');
file_put_contents($leadJsonFile, json_encode($lead, JSON_UNESCAPED_UNICODE));

// تشغيل Node.js CLI script
$command = "cd " . escapeshellarg($enricherPath) . " && node cli.js " . escapeshellarg(file_get_contents($leadJsonFile)) . " 2>&1";
$output = shell_exec($command);
unlink($leadJsonFile);

// محاولة parse النتيجة
$result = null;
$lines = explode("\n", $output);
foreach ($lines as $line) {
    $decoded = json_decode($line, true);
    if ($decoded && isset($decoded['enriched'])) {
        $result = $decoded;
        break;
    }
}

if (!$result) {
    // إرجاع خطأ مع الـ output للتشخيص
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Enrichment failed',
        'debug' => $output
    ]);
    exit;
}

// تحديث العميل في قاعدة البيانات إذا تم تمرير leadId
if ($leadId && $result['enriched']) {
    updateLeadWithEnrichedData($leadId, $result['enriched']);
}

// إرجاع النتيجة
echo json_encode([
    'ok' => true,
    'lead' => $lead,
    'enriched' => $result['enriched'],
    'summary' => $result['summary']
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

/**
 * جلب اسم التصنيف
 */
function getCategoryName($categoryId) {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT name FROM categories WHERE id = ?");
    $stmt->execute([$categoryId]);
    return $stmt->fetchColumn() ?: null;
}

/**
 * تحديث العميل بالبيانات المُثراة
 */
function updateLeadWithEnrichedData($leadId, $enriched) {
    $pdo = db();
    $updates = [];
    $params = [];

    // Website
    if (!empty($enriched['website']['url'])) {
        $updates[] = "website = ?";
        $params[] = $enriched['website']['url'];
    }

    // Social Media (تخزين كـ JSON)
    if (!empty($enriched['socialMedia'])) {
        $updates[] = "social = ?";
        $params[] = json_encode($enriched['socialMedia'], JSON_UNESCAPED_UNICODE);
    }

    // Maps data
    if (!empty($enriched['maps'])) {
        if (!empty($enriched['maps']['address'])) {
            $updates[] = "address = ?";
            $params[] = $enriched['maps']['address'];
        }
        if (!empty($enriched['maps']['rating'])) {
            $updates[] = "rating = ?";
            $params[] = $enriched['maps']['rating'];
        }
        if (!empty($enriched['maps']['coordinates'])) {
            $updates[] = "lat = ?, lng = ?";
            $params[] = $enriched['maps']['coordinates']['lat'];
            $params[] = $enriched['maps']['coordinates']['lng'];
        }
    }

    if (count($updates) > 0) {
        $params[] = $leadId;
        $sql = "UPDATE leads SET " . implode(", ", $updates) . ", updated_at = datetime('now') WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }
}
