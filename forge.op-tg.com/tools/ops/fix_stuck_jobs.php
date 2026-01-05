<?php
// CLI: reset stuck jobs (status=processing but lease expired) back to queued
if (php_sapi_name() !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }
require_once __DIR__ . '/../../bootstrap.php';
$pdo = db();

$now = date('Y-m-d H:i:s');
$st = $pdo->query("SELECT id FROM internal_jobs WHERE status='processing' AND lease_expires_at IS NOT NULL AND lease_expires_at < datetime('now') LIMIT 200");
$rows = $st ? $st->fetchAll() : [];
$count = 0;
foreach($rows as $r){
  $id = (int)$r['id'];
  $ok = $pdo->prepare("UPDATE internal_jobs SET status='queued', next_retry_at=NULL, lease_expires_at=NULL, updated_at=? WHERE id=?")->execute([$now, $id]);
  if($ok){ $count++; }
}
$out = ['ok'=>true,'reset'=>$count,'ts'=>gmdate('c')];
echo json_encode($out, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";
