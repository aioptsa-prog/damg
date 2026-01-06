<?php
/**
 * Lead Research API
 * POST /v1/api/leads/research.php
 * 
 * يبحث عن شركة ويجمع كل المعلومات المتاحة
 */

// زيادة الـ timeout لأن البحث يستغرق وقتاً
set_time_limit(300);
ini_set('max_execution_time', 300);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || empty($input['companyName'])) {
    http_response_code(400);
    echo json_encode(['error' => 'companyName is required']);
    exit;
}

$companyName = $input['companyName'];
$city = $input['city'] ?? '';
$activity = $input['activity'] ?? '';

$startTime = microtime(true);

$result = [
    'input' => [
        'companyName' => $companyName,
        'city' => $city,
        'activity' => $activity
    ],
    'discovered' => [
        'website' => null,
        'socialMedia' => [],
        'maps' => null
    ],
    'extracted' => [
        'website' => null,
        'maps' => null
    ],
    'summary' => [
        'totalConfidence' => 0,
        'sourcesFound' => [],
        'duration' => 0,
        'errors' => []
    ]
];

// استدعاء Lead Enricher
$enricherPath = __DIR__ . '/../../../lib/lead_enricher';
$leadData = [
    'name' => $companyName,
    'city' => $city,
    'category' => $activity
];

// كتابة البيانات لملف مؤقت لتجنب مشاكل الـ escaping
$tempFile = tempnam(sys_get_temp_dir(), 'lead_');
file_put_contents($tempFile, json_encode($leadData, JSON_UNESCAPED_UNICODE));

// تشغيل CLI مع قراءة من الملف
$command = 'cd ' . escapeshellarg($enricherPath) . ' && node cli.js "$(cat ' . escapeshellarg($tempFile) . ')" 2>&1';

// استخدام --file لتمرير الملف مباشرة
if (PHP_OS_FAMILY === 'Windows') {
    $command = 'cd /d "' . $enricherPath . '" && node cli.js --file "' . $tempFile . '" 2>&1';
} else {
    $command = 'cd ' . escapeshellarg($enricherPath) . ' && node cli.js --file ' . escapeshellarg($tempFile) . ' 2>&1';
}

$output = shell_exec($command);
@unlink($tempFile);

// محاولة parse النتيجة
$enricherResult = null;
if ($output) {
    $lines = explode("\n", $output);
    foreach ($lines as $line) {
        $decoded = json_decode($line, true);
        if ($decoded && isset($decoded['ok'])) {
            $enricherResult = $decoded;
            break;
        }
    }
}

if ($enricherResult && $enricherResult['ok']) {
    $enriched = $enricherResult['enriched'] ?? [];
    
    // Maps
    if (!empty($enriched['maps'])) {
        $maps = $enriched['maps'];
        $result['discovered']['maps'] = [
            'url' => 'https://maps.google.com/?q=' . urlencode($maps['address'] ?? $companyName),
            'type' => 'maps',
            'confidence' => $maps['confidence'] ?? 0.5,
            'source' => 'lead_enricher'
        ];
        $result['extracted']['maps'] = [
            'name' => $maps['name'] ?? '',
            'address' => $maps['address'] ?? '',
            'phone' => $maps['phone'] ?? null,
            'website' => $maps['website'] ?? null,
            'rating' => $maps['rating'] ?? null,
            'reviewCount' => $maps['reviewCount'] ?? null,
            'coordinates' => $maps['coordinates'] ?? null
        ];
        $result['summary']['sourcesFound'][] = 'google_maps';
    }
    
    // Website
    if (!empty($enriched['website'])) {
        $result['discovered']['website'] = [
            'url' => $enriched['website']['url'],
            'type' => 'website',
            'confidence' => $enriched['website']['confidence'] ?? 0.5,
            'source' => 'lead_enricher'
        ];
        $result['summary']['sourcesFound'][] = 'website';
    }
    
    // Social Media
    if (!empty($enriched['socialMedia'])) {
        foreach ($enriched['socialMedia'] as $platform => $data) {
            if ($data && !empty($data['url'])) {
                $result['discovered']['socialMedia'][] = [
                    'url' => $data['url'],
                    'type' => $platform,
                    'confidence' => $data['confidence'] ?? 0.5,
                    'source' => 'lead_enricher'
                ];
                $result['summary']['sourcesFound'][] = $platform;
            }
        }
    }
    
    // حساب الثقة الإجمالية
    $totalScore = 0;
    $weights = 0;
    
    if ($result['discovered']['website']) {
        $totalScore += $result['discovered']['website']['confidence'] * 0.3;
        $weights += 0.3;
    }
    if ($result['discovered']['maps']) {
        $totalScore += $result['discovered']['maps']['confidence'] * 0.35;
        $weights += 0.35;
    }
    if (count($result['discovered']['socialMedia']) > 0) {
        $avgSocial = array_sum(array_column($result['discovered']['socialMedia'], 'confidence')) / count($result['discovered']['socialMedia']);
        $totalScore += $avgSocial * 0.2;
        $weights += 0.2;
    }
    
    $result['summary']['totalConfidence'] = $weights > 0 ? round($totalScore / $weights, 2) : 0;
} else {
    $result['summary']['errors'][] = 'Lead Enricher failed: ' . ($output ?? 'No output');
}

$result['summary']['duration'] = round((microtime(true) - $startTime) * 1000);

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
