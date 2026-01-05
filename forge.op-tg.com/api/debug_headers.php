<?php
header('Content-Type: application/json; charset=utf-8');
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if(!in_array($ip, ['127.0.0.1','::1'])){ http_response_code(403); echo json_encode(['error'=>'local_only']); exit; }
echo json_encode(function_exists('getallheaders')? getallheaders() : [], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
