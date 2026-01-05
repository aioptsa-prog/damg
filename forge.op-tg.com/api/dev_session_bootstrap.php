<?php
// Local-only helper to bootstrap an admin session and return a CSRF token
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/csrf.php';
header('Content-Type: application/json; charset=utf-8');

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if(!in_array($ip, ['127.0.0.1','::1'])){ http_response_code(403); echo json_encode(['error'=>'local_only']); exit; }

if(session_status()===PHP_SESSION_NONE) session_start();

// Pick first active admin
$pdo = db();
$adminId = (int)$pdo->query("SELECT id FROM users WHERE role='admin' AND active=1 ORDER BY id LIMIT 1")->fetchColumn();
if(!$adminId){ http_response_code(500); echo json_encode(['error'=>'no_admin_user']); exit; }

$_SESSION['uid'] = $adminId;
$csrf = csrf_token();

echo json_encode(['ok'=>true,'user_id'=>$adminId,'csrf'=>$csrf]);
