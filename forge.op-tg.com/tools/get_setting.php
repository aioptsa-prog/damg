<?php
// CLI tool: get a setting value by key from the settings table
// Usage: php tools/get_setting.php <key>
require_once __DIR__ . '/../config/db.php';
if (php_sapi_name() !== 'cli') { fwrite(STDERR, "[ERR] CLI only\n"); exit(1); }
$k = $argv[1] ?? '';
if ($k === '') { fwrite(STDERR, "Usage: php tools/get_setting.php <key>\n"); exit(1); }
try{
  $pdo = db();
  $stmt = $pdo->prepare("SELECT value FROM settings WHERE key=? LIMIT 1");
  $stmt->execute([$k]);
  $val = $stmt->fetchColumn();
  if($val === false){ fwrite(STDOUT, "NOT_FOUND\n"); exit(2); }
  fwrite(STDOUT, (string)$val . "\n");
  exit(0);
}catch(Throwable $e){ fwrite(STDERR, "[ERR] ".$e->getMessage()."\n"); exit(1); }
