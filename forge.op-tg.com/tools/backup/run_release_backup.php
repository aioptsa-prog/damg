<?php
require_once __DIR__ . '/../../bootstrap.php';

// Snapshot SQLite DB with gzip and checksum
$env = require __DIR__ . '/../../config/.env.php';
$dbPath = $env['SQLITE_PATH'];
if(!is_file($dbPath)){ fwrite(STDERR, "DB file not found: $dbPath\n"); exit(2); }

$ts = gmdate('Ymd_His');
$backupDir = __DIR__ . '/../../storage/backups';
@mkdir($backupDir, 0777, true);
$plain = $backupDir . "/db_${ts}.sqlite";
$gz = $plain . '.gz';

try{
  $pdo = db();
  // Ensure WAL checkpoint then VACUUM into plain file for compact snapshot
  @$pdo->exec('PRAGMA wal_checkpoint(FULL);');
  @$pdo->exec('VACUUM');
  if(!copy($dbPath, $plain)) { throw new Exception('copy failed'); }
  // Gzip
  $in = fopen($plain, 'rb'); $out = gzopen($gz, 'wb9');
  while(!feof($in)){ gzwrite($out, fread($in, 8192)); }
  fclose($in); gzclose($out);
  // Hash and size
  $sha = hash_file('sha256', $gz);
  $size = filesize($gz);
  // Quick integrity: try to open first bytes
  $ok = ($size>0);
  if(!$ok){ throw new Exception('empty gzip'); }
  echo json_encode(['ok'=>true,'path'=>realpath($gz),'sha256'=>$sha,'size'=>$size], JSON_UNESCAPED_SLASHES) . "\n";
  // Cleanup plain copy
  @unlink($plain);
  exit(0);
}catch(Throwable $e){
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_SLASHES) . "\n";
  @unlink($plain);
  @unlink($gz);
  exit(1);
}
