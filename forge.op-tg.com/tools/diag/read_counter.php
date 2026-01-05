<?php
// Usage: php tools/diag/read_counter.php <kind> [<day YYYY-MM-DD>]
require_once __DIR__ . '/../../config/db.php';

$kind = $argv[1] ?? '';
$day  = $argv[2] ?? date('Y-m-d');
if($kind===''){
  fwrite(STDERR, "Usage: php tools/diag/read_counter.php <kind> [<day YYYY-MM-DD>]\n");
  exit(1);
}

$pdo = db();
$st = $pdo->prepare("SELECT count FROM usage_counters WHERE day=? AND kind=? LIMIT 1");
$st->execute([$day, $kind]);
$cnt = (int)($st->fetchColumn() ?: 0);

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['day'=>$day,'kind'=>$kind,'count'=>$cnt], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), "\n";
