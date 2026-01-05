<?php
require_once __DIR__ . '/v1/api/whatsapp/bootstrap.php';

echo "=== Test formatSaudiPhone ===\n\n";

$tests = [
    '500888167',      // يبدأ بـ 5 بدون كود
    '533066597',      // يبدأ بـ 5 بدون كود
    '0512345678',     // يبدأ بـ 05
    '966550575096',   // يبدأ بـ 966
    '+966550575096',  // بـ + بالفعل
    '00966512345678', // يبدأ بـ 00
];

foreach ($tests as $phone) {
    $result = formatSaudiPhone($phone);
    echo "$phone => $result\n";
}

echo "\n=== Done ===\n";
