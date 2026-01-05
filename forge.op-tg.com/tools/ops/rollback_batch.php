<?php
// CLI tool: rollback a Google Places batch by batch_id (delete from places table)
if (php_sapi_name() !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }
require_once __DIR__ . '/../../bootstrap.php';

function rb_parse_args($argv){
  $out = ['batch'=>null,'yes'=>false,'dry'=>false];
  for($i=1;$i<count($argv);$i++){
    $a=$argv[$i];
    if($a==='-b'||$a==='--batch'){ $out['batch'] = $argv[++$i] ?? null; continue; }
    if($a==='-y'||$a==='--yes'){ $out['yes'] = true; continue; }
    if($a==='--dry-run'){ $out['dry'] = true; continue; }
  }
  return $out;
}

try{
  $args = rb_parse_args($argv);
  if(!$args['batch']) throw new InvalidArgumentException('missing --batch <batch_id>');
  $pdo = db();
  $stc = $pdo->prepare("SELECT COUNT(*) c FROM places WHERE batch_id=?");
  $stc->execute([$args['batch']]);
  $count = (int)($stc->fetch()['c'] ?? 0);
  if($args['dry']){ echo json_encode(['ok'=>true,'dry'=>true,'batch'=>$args['batch'],'count'=>$count], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit(0); }
  if(!$args['yes']){ fwrite(STDERR, "About to delete $count rows from places where batch_id='{$args['batch']}'. Re-run with -y to confirm.\n"); exit(3); }
  $st = $pdo->prepare("DELETE FROM places WHERE batch_id=?");
  $st->execute([$args['batch']]);
  echo json_encode(['ok'=>true,'deleted'=>$st->rowCount(),'batch'=>$args['batch']], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit(0);
}catch(Throwable $e){
  fwrite(STDERR, $e->getMessage()."\n");
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
  exit(2);
}
