<?php
require_once __DIR__ . '/../../bootstrap.php';
$pdo = db();
$id = isset($argv[1]) ? (int)$argv[1] : 0;
if($id<=0){ fwrite(STDERR, "Usage: php mark_job_done.php <job_id>\n"); exit(2); }
$st = $pdo->prepare("UPDATE internal_jobs SET status='done', done_reason='succeeded', finished_at=datetime('now'), updated_at=datetime('now') WHERE id=?");
$st->execute([$id]);
echo "OK\n";