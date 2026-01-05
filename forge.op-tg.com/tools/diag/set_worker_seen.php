<?php
if (php_sapi_name() !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }
require_once __DIR__ . '/../../bootstrap.php';
$worker = $argv[1] ?? '';
$ts = $argv[2] ?? date('Y-m-d H:i:s');
if($worker===''){ fwrite(STDERR, "Usage: php tools/diag/set_worker_seen.php <worker_id> [timestamp]\n"); exit(1); }
$pdo = db();
$st = $pdo->prepare("UPDATE internal_workers SET last_seen=?, status='processing' WHERE worker_id=?");
$st->execute([$ts, $worker]);
if($st->rowCount()===0){
    $ins = $pdo->prepare("INSERT INTO internal_workers(worker_id,last_seen,status) VALUES(?,?,?)");
    $ins->execute([$worker,$ts,'processing']);
}
echo "OK updated $worker to $ts\n";
