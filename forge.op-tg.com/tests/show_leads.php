<?php
require_once __DIR__ . '/../bootstrap.php';

echo "=== آخر 15 عميل تم جمعهم ===\n\n";

$leads = db()->query("SELECT id, name, phone, city, rating FROM leads ORDER BY id DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);

foreach($leads as $l) {
    echo "ID: " . $l['id'] . "\n";
    echo "  الاسم: " . $l['name'] . "\n";
    echo "  الهاتف: " . $l['phone'] . "\n";
    echo "  المدينة: " . $l['city'] . "\n";
    echo "  التقييم: " . ($l['rating'] ?? '-') . "\n";
    echo "---\n";
}

echo "\nإجمالي: " . count($leads) . " عميل\n";
