<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/auth.php';

header('Content-Type: application/json; charset=utf-8');

try {
  $u = current_user();
  if(!$u || ($u['role'] ?? '') !== 'admin'){
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'forbidden']);
    exit;
  }

  $raw = file_get_contents('php://input') ?: '';
  $in = json_decode($raw, true);
  if(!is_array($in)) $in = [];

  $csrf = (string)($in['csrf'] ?? ($_GET['csrf'] ?? ''));
  if(!csrf_verify($csrf)){
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'bad_csrf']);
    exit;
  }

  $event = trim((string)($in['event'] ?? ''));
  // Whitelist allowed events to avoid arbitrary counter pollution
  $allowed = ['ml_pin_add','ml_pin_remove','ml_geocode_ok','ml_geocode_fail'];
  if($event === '' || !in_array($event, $allowed, true)){
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'bad_event']);
    exit;
  }

  // Increment usage_counters best-effort
  $pdo = db();
  $day = date('Y-m-d');
  try{
    $stmt = $pdo->prepare("INSERT INTO usage_counters(day,kind,count) VALUES(?,?,1) ON CONFLICT(day,kind) DO UPDATE SET count=count+1");
    $stmt->execute([$day, $event]);
  }catch(Throwable $e){ /* ignore */ }

  echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'exception']);
}
