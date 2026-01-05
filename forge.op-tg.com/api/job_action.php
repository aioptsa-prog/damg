<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/system.php';

header('Content-Type: application/json; charset=utf-8');
$u = current_user();
if(!$u || $u['role']!=='admin'){ http_response_code(403); echo json_encode(['ok'=>false,'error'=>'forbidden']); exit; }

// Accept JSON or form-encoded
$raw = file_get_contents('php://input');
$data = [];
if(isset($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'],'application/json')!==false){
  $data = json_decode($raw, true) ?: [];
} else { $data = $_POST ?: []; }

$csrf = (string)($data['csrf'] ?? '');
if(!csrf_verify($csrf)){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>'bad_csrf']); exit; }

$jobId = (int)($data['job_id'] ?? 0);
$action = trim((string)($data['action'] ?? ''));
if($jobId<=0 || $action===''){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>'missing_params']); exit; }

$pdo = db();
$sel = $pdo->prepare("SELECT * FROM internal_jobs WHERE id=? LIMIT 1");
$sel->execute([$jobId]);
$job = $sel->fetch(PDO::FETCH_ASSOC);
if(!$job){ http_response_code(404); echo json_encode(['ok'=>false,'error'=>'not_found']); exit; }

try{
  if($action==='force_requeue'){
    // Requeue regardless of current worker state (admin override)
    $upd = $pdo->prepare("UPDATE internal_jobs SET status='queued', worker_id=NULL, lease_expires_at=NULL, attempt_id=NULL, last_error=NULL, updated_at=datetime('now') WHERE id=?");
    $upd->execute([$jobId]);
    // Audit attempt
    try{
      $pdo->prepare("INSERT INTO job_attempts(job_id,worker_id,started_at,finished_at,success,log_excerpt,attempt_id) VALUES(?,?,?,?,0,?,?)")
          ->execute([$jobId, $job['worker_id'] ?? null, date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), 'manual_requeue', $job['attempt_id'] ?? null]);
    }catch(Throwable $e){}
    echo json_encode(['ok'=>true,'action'=>'force_requeue']); exit;
  }
  if($action==='cancel'){
    // Mark as done with cancel reason if not already done
    if(($job['status'] ?? '')!=='done'){
      $upd = $pdo->prepare("UPDATE internal_jobs SET status='done', done_reason='cancelled_admin', finished_at=datetime('now'), lease_expires_at=NULL, updated_at=datetime('now') WHERE id=?");
      $upd->execute([$jobId]);
    }
    // Audit attempt
    try{
      $pdo->prepare("INSERT INTO job_attempts(job_id,worker_id,started_at,finished_at,success,log_excerpt,attempt_id) VALUES(?,?,?,?,0,?,?)")
          ->execute([$jobId, $job['worker_id'] ?? null, date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), 'cancelled_admin', $job['attempt_id'] ?? null]);
    }catch(Throwable $e){}
    echo json_encode(['ok'=>true,'action'=>'cancel']); exit;
  }
  http_response_code(400); echo json_encode(['ok'=>false,'error'=>'unknown_action']);
}catch(Throwable $e){ http_response_code(500); echo json_encode(['ok'=>false,'error'=>'exception','message'=>$e->getMessage()]); }
exit;
?>
