<?php
/**
 * Direct Test of Login API Endpoint
 */

$url = 'http://localhost:8000/v1/api/auth/login.php';

$data = [
    'mobile' => '590000000',
    'password' => 'Forge@2025!',
    'remember' => false
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: application/json'
]);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_VERBOSE, true);

echo "=== Testing Login API ===\n";
echo "URL: $url\n";
echo "Data: " . json_encode($data, JSON_PRETTY_PRINT) . "\n\n";

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$headers = substr($response, 0, $headerSize);
$body = substr($response, $headerSize);

if (curl_errno($ch)) {
    echo "cURL Error: " . curl_error($ch) . "\n";
} else {
    echo "HTTP Code: $httpCode\n";
    echo "\n=== Response Headers ===\n";
    echo $headers . "\n";
    echo "\n=== Response Body ===\n";
    echo $body . "\n";

    $json = json_decode($body, true);
    if ($json) {
        echo "\n=== Parsed JSON ===\n";
        print_r($json);
    }
}

curl_close($ch);
