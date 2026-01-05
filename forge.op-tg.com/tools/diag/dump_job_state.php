<?php
require_once __DIR__ . '/../../bootstrap.php';
$pdo = db();
$id = isset($argv[1]) ? (int)$argv[1] : 0;
if($id>0){
  $st = $pdo->prepare("SELECT id,status,worker_id,updated_at,finished_at FROM internal_jobs WHERE id=?");
  $st->execute([$id]);
  $r = $st->fetch(PDO::FETCH_ASSOC);
  if($r){ echo json_encode($r, JSON_UNESCAPED_UNICODE),"\n"; } else { echo "{}\n"; }
} else {
  $s = $pdo->query("SELECT id,status,worker_id,updated_at,finished_at FROM internal_jobs ORDER BY id DESC LIMIT 5");
  while($r = $s->fetch(PDO::FETCH_ASSOC)){
    echo $r['id'],'|',$r['status'],'|',($r['worker_id']?:''),'|',$r['updated_at'],'|',($r['finished_at']?:''),"\n";
  }
}
