<?php
// CLI tool: set any setting key/value in the settings table
// Usage: php tools/set_setting.php <key> <value>
require_once __DIR__ . '/../config/db.php';

if (php_sapi_name() !== 'cli') { fwrite(STDERR, "[ERR] CLI only\n"); exit(1); }
$k = $argv[1] ?? '';
$v = $argv[2] ?? null;
if ($k === '' || $v === null) {
  fwrite(STDERR, "Usage: php tools/set_setting.php <key> <value>\n");
  exit(1);
}
try{
  $pdo = db();
  $stmt = $pdo->prepare("INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value");
  $stmt->execute([$k, $v]);
  fwrite(STDOUT, "OK: $k=$v\n");
  exit(0);
}catch(Throwable $e){ fwrite(STDERR, "[ERR] ".$e->getMessage()."\n"); exit(1); }
