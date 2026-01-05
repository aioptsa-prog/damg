<?php
// CLI helper: Set a settings key to the JSON content from stdin or a file path
// Usage:
//   echo '{"a":1}' | php tools/diag/set_json_setting.php worker_config_overrides_json
//   php tools/diag/set_json_setting.php worker_name_overrides_json path/to/file.json
if (php_sapi_name() !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }
require_once __DIR__ . '/../../bootstrap.php';

$key = isset($argv[1]) ? (string)$argv[1] : '';
$src = isset($argv[2]) ? (string)$argv[2] : '';
if($key===''){ fwrite(STDERR, "usage: php set_json_setting.php <key> [file.json]\n"); exit(2); }

$raw = '';
if($src!==''){
  $raw = @file_get_contents($src);
  if($raw===false){ fwrite(STDERR, "cannot read file: $src\n"); exit(3); }
} else {
  // Read from STDIN
  $raw = stream_get_contents(STDIN);
}
$raw = (string)$raw;
$trim = trim($raw);
if($trim===''){ fwrite(STDERR, "empty JSON\n"); exit(4); }
$j = json_decode($trim, true);
if(!is_array($j) && !is_object($j)){
  fwrite(STDERR, "invalid JSON\n");
  exit(5);
}
$pdo = db();
$pdo->prepare('INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value')
    ->execute([$key, $trim]);
echo "ok: $key set (".strlen($trim)." bytes)\n";
