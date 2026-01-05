<?php
/**
 * Simple Authorization Header Test
 * This file tests if Authorization header is being forwarded to PHP
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

// Check all possible ways Authorization header might be available
$authHeader = null;
$source = 'NOT_FOUND';

if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
    $source = '$_SERVER[\'HTTP_AUTHORIZATION\']';
} elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    $source = '$_SERVER[\'REDIRECT_HTTP_AUTHORIZATION\']';
} elseif (function_exists('apache_request_headers')) {
    $headers = apache_request_headers();
    if (isset($headers['Authorization'])) {
        $authHeader = $headers['Authorization'];
        $source = 'apache_request_headers()[\'Authorization\']';
    }
}

$response = [
    'ok' => true,
    'auth_header_found' => ($authHeader !== null),
    'auth_header_value' => $authHeader,
    'source' => $source,
    'all_headers' => [],
    'server_vars' => []
];

// Include all HTTP_ headers
foreach ($_SERVER as $key => $value) {
    if (strpos($key, 'HTTP_') === 0) {
        $response['all_headers'][$key] = $value;
    }
    if (strpos($key, 'AUTH') !== false || strpos($key, 'REDIRECT') !== false) {
        $response['server_vars'][$key] = $value;
    }
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
