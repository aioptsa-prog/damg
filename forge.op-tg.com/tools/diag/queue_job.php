<?php
// CLI: Queue a single internal job for testing
// Usage: php tools/diag/queue_job.php "صالون نسائي اختبار" "24.7136,46.6753" 5
if (php_sapi_name() !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }
require_once __DIR__ . '/../../bootstrap.php';

$query = isset($argv[1]) ? trim($argv[1]) : 'صالون نسائي اختبار';
$ll    = isset($argv[2]) ? trim($argv[2])  : '24.713600,46.675300';
$rk    = isset($argv[3]) ? intval($argv[3]) : 5;

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// pick any user id; create one if empty
$uid = null;
try{
  $uid = (int)$pdo->query("SELECT id FROM users LIMIT 1")->fetchColumn();
}catch(Throwable $e){ $uid = 0; }
if(!$uid){
  $now = date('Y-m-d H:i:s');
  $pdo->prepare("INSERT INTO users(mobile,name,role,password_hash,active,created_at,username,is_superadmin) VALUES(?,?,?,?,1,?, ?, 1)")
      ->execute(['590000999', 'Local Admin', 'admin', password_hash('admin', PASSWORD_BCRYPT), $now, 'local-admin']);
  $uid = (int)$pdo->lastInsertId();
}

// insert job
$now = date('Y-m-d H:i:s');
$st = $pdo->prepare("INSERT INTO internal_jobs(requested_by_user_id,role,agent_id,query,ll,radius_km,lang,region,status,created_at,updated_at) VALUES(?,?,?,?,?,?, 'ar','sa','queued', ?, ?)");
$st->execute([$uid, 'agent', null, $query, $ll, $rk, $now, $now]);
$id = (int)$pdo->lastInsertId();
echo "queued job id=$id query=$query ll=$ll radius_km=$rk\n";
exit(0);
?>
