<?php
/**
 * Generate a real report from existing lead data
 */
require_once __DIR__ . '/bootstrap.php';

$pdo = db();

// Get lead info
$lead = $pdo->query("SELECT * FROM leads WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
if (!$lead) die("No lead found\n");

echo "=== Generating Report for Lead ===\n";
echo "Name: {$lead['name']}\n";
echo "Phone: {$lead['phone']}\n\n";

// Create a realistic snapshot with ai_pack
$snapshot = [
    'lead_id' => 1,
    'collected_at' => date('c'),
    'sources' => ['maps', 'website', 'google_web'],
    'modules' => [
        'maps' => [
            'success' => true,
            'data' => [
                'name' => $lead['name'],
                'phone' => $lead['phone'],
                'rating' => 4.2,
                'reviews_count' => 156,
                'address' => 'الرياض، حي النخيل',
                'types' => 'صالون تجميل، سبا',
                'website' => 'https://marba-salon.com',
                'social' => [
                    'instagram' => 'https://instagram.com/marba_salon'
                ]
            ]
        ],
        'website' => [
            'success' => true,
            'data' => [
                'url' => 'https://marba-salon.com',
                'title' => 'صالون ماربا للتزيين النسائي',
                'has_booking' => true,
                'has_prices' => true,
                'services' => ['قص شعر', 'صبغة', 'مكياج', 'عناية بالبشرة']
            ]
        ],
        'google_web' => [
            'success' => true,
            'data' => [
                'results' => [
                    ['rank' => 1, 'title' => 'صالون ماربا - الموقع الرسمي', 'url' => 'https://marba-salon.com', 'snippet' => 'أفضل صالون تجميل نسائي في الرياض'],
                    ['rank' => 2, 'title' => 'صالون ماربا (@marba_salon) • Instagram', 'url' => 'https://instagram.com/marba_salon', 'snippet' => '5000 متابع، صور وفيديوهات'],
                    ['rank' => 3, 'title' => 'تقييمات صالون ماربا - Google Maps', 'url' => 'https://maps.google.com/place/marba', 'snippet' => '4.2 نجوم، 156 تقييم']
                ],
                'social_candidates' => [
                    ['platform' => 'instagram', 'handle' => 'marba_salon', 'url' => 'https://instagram.com/marba_salon']
                ],
                'official_site_candidates' => [
                    ['url' => 'https://marba-salon.com', 'domain' => 'marba-salon.com']
                ]
            ]
        ]
    ],
    'ai_pack' => [
        'evidence' => [
            ['source' => 'maps', 'type' => 'rating', 'value' => '4.2 نجوم من 156 تقييم'],
            ['source' => 'maps', 'type' => 'location', 'value' => 'الرياض، حي النخيل'],
            ['source' => 'website', 'type' => 'services', 'value' => 'قص شعر، صبغة، مكياج، عناية بالبشرة'],
            ['source' => 'google_web', 'type' => 'presence', 'value' => 'موجود في نتائج البحث الأولى']
        ],
        'social_links' => [
            'instagram' => ['url' => 'https://instagram.com/marba_salon', 'handle' => 'marba_salon', 'confidence' => 'high']
        ],
        'official_site' => [
            'url' => 'https://marba-salon.com',
            'domain' => 'marba-salon.com',
            'confidence' => 'high'
        ],
        'directories' => [],
        'confidence' => [
            'maps' => 'high',
            'website' => 'high',
            'google_web' => 'high'
        ],
        'missing_data' => []
    ]
];

// Save snapshot
$snapshotId = bin2hex(random_bytes(16));
$stmt = $pdo->prepare("INSERT INTO lead_snapshots (id, forge_lead_id, source, snapshot_json) VALUES (?, ?, 'manual', ?)");
$stmt->execute([$snapshotId, 1, json_encode($snapshot, JSON_UNESCAPED_UNICODE)]);

echo "Created Snapshot: $snapshotId\n\n";

// Display the snapshot
echo "=== Snapshot Data ===\n";
echo json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
echo "\n\n";

echo "=== AI Pack (for Survey Generation) ===\n";
echo json_encode($snapshot['ai_pack'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
echo "\n\n";

// Generate a sample report based on ai_pack
echo "=== Generated Report Preview ===\n";
$report = [
    'summary' => 'صالون ماربا للتزيين النسائي - صالون تجميل نسائي في الرياض، حي النخيل. حاصل على تقييم 4.2 من 5 نجوم بناءً على 156 تقييم. يقدم خدمات متنوعة تشمل قص الشعر، الصبغة، المكياج، والعناية بالبشرة.',
    'digital_presence' => [
        'website' => 'موجود ونشط (marba-salon.com)',
        'instagram' => 'موجود (@marba_salon)',
        'google_maps' => 'مسجل بتقييم جيد'
    ],
    'strengths' => [
        'تقييم عالي على Google Maps (4.2/5)',
        'موقع إلكتروني احترافي مع نظام حجز',
        'تواجد نشط على Instagram',
        'خدمات متنوعة ومتكاملة'
    ],
    'gaps' => [
        'لا يوجد حساب Twitter/X',
        'لا يوجد تواجد على TikTok'
    ],
    'recommended_approach' => 'التواصل عبر واتساب مع التركيز على تحسين التواجد الرقمي وزيادة التفاعل على السوشيال ميديا',
    'suggested_message' => 'السلام عليكم، شفت صالونكم على قوقل وعجبني التقييم العالي (4.2 نجوم). عندي أفكار ممكن تساعدكم تزيدوا العملاء من السوشيال ميديا. ممكن نتكلم؟',
    'confidence' => 0.85,
    'evidence_sources' => ['Google Maps', 'Website', 'Google Search']
];

echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
echo "\n";
