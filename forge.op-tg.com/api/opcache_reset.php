<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/auth.php';

// Only allow when maintenance.flag exists and INTERNAL_SECRET matches.
$flag = __DIR__ . '/../maintenance.flag';
// Safe header accessor for built-in PHP server compatibility
$secretHeader = (function($name){ $key='HTTP_'.strtoupper(str_replace('-','_',$name)); return $_SERVER[$key]??null; })('X-Internal-Secret') ?? '';
$internalSecret = get_setting('internal_secret','');
if (!is_file($flag) || !$secretHeader || !hash_equals($internalSecret, $secretHeader)) {
  http_response_code(403); echo json_encode(['ok'=>false,'error'=>'forbidden']); exit;
}

$supported = function_exists('opcache_reset');
$ok = false;
if ($supported) { $ok = (bool)@opcache_reset(); }
echo json_encode(['ok'=>$ok,'supported'=>$supported,'time'=>gmdate('c')]);
exit;
