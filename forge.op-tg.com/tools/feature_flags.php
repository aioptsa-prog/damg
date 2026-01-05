<?php
// Simple CLI tool to view/set feature flags without touching UI
// Usage:
//   php tools/feature_flags.php list
//   php tools/feature_flags.php get security_csrf_auto
//   php tools/feature_flags.php set security_csrf_auto 1
// Exit code 0 on success, 1 on error

require_once __DIR__ . '/../config/db.php';

$flags = [
  'security_csrf_auto',
  'rate_limit_basic',
  'seo_meta_enabled',
  'pagination_enabled',
  'caching_layer',
];

function out($s){ fwrite(STDOUT, $s . PHP_EOL); }
function err($s){ fwrite(STDERR, '[ERR] ' . $s . PHP_EOL); }

function set_flag($k,$v){
  $pdo = db();
  $v = ($v === '1') ? '1' : '0';
  $stmt = $pdo->prepare("INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value");
  $stmt->execute([$k,$v]);
}

function get_flag($k){
  $pdo = db();
  $st = $pdo->prepare('SELECT value FROM settings WHERE key=?');
  $st->execute([$k]);
  $r = $st->fetch(PDO::FETCH_ASSOC);
  return $r ? ($r['value'] ?? '') : '';
}

$argv = $_SERVER['argv'] ?? [];
$cmd = $argv[1] ?? '';

if($cmd === 'list'){
  out('Feature flags:');
  foreach($flags as $k){
    $v = get_flag($k);
    out(sprintf('  %-22s = %s', $k, ($v==='1'?'1 (on)':'0 (off)')));
  }
  exit(0);
}

if($cmd === 'get'){
  $k = $argv[2] ?? '';
  if(!$k){ err('missing flag key'); exit(1); }
  $v = get_flag($k);
  if($v === ''){ err('flag not found: '.$k); exit(1); }
  out($v);
  exit(0);
}

if($cmd === 'set'){
  $k = $argv[2] ?? '';
  $v = $argv[3] ?? '';
  if(!$k || ($v!=='0' && $v!=='1')){ err('usage: set <key> <0|1>'); exit(1); }
  set_flag($k,$v);
  out('OK: '.$k.'='.$v);
  exit(0);
}

err('usage: php tools/feature_flags.php <list|get|set> [args...]');
exit(1);
