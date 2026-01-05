<?php
// CLI: Set a single setting key=value
// Usage: php tools/diag/set_setting.php force_https 0
if (php_sapi_name() !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }
require_once __DIR__ . '/../../bootstrap.php';

$key = isset($argv[1]) ? (string)$argv[1] : '';
$val = isset($argv[2]) ? (string)$argv[2] : '';
if($key===''){ fwrite(STDERR, "usage: php set_setting.php <key> <value>\n"); exit(2); }
$pdo = db();
$pdo->prepare('INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value')
    ->execute([$key,$val]);
echo "ok: $key=$val\n";
exit(0);
?>
