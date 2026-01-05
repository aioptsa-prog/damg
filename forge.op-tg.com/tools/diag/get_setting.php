<?php
// CLI: Get setting value
// Usage: php tools/diag/get_setting.php internal_secret
if (php_sapi_name() !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }
require_once __DIR__ . '/../../bootstrap.php';
$key = isset($argv[1]) ? (string)$argv[1] : '';
if($key===''){ fwrite(STDERR, "usage: php get_setting.php <key>\n"); exit(2); }
$pdo = db();
$st = $pdo->prepare('SELECT value FROM settings WHERE key=?');
$st->execute([$key]);
$val = $st->fetchColumn();
if($val===false){ echo "(null)\n"; } else { echo $val, "\n"; }
exit(0);
?>
