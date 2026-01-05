<?php
// CLI: Rotate internal_secret safely.
// Usage:
//  - Announce: php tools/rotate_internal_secret.php [--grace-min=10]
//  - Commit:   php tools/rotate_internal_secret.php --commit
//  - Abort:    php tools/rotate_internal_secret.php --abort
// Strategy:
// 1) Announce: generate new secret S2; save into settings as 'internal_secret_next'.
//    Server (lib/security.php) accepts HMAC with S1 or S2 during grace.
// 2) Update workers to use S2.
// 3) Commit: move S2 -> internal_secret and clear internal_secret_next.
//    (Workers already on S2 continue normally.)
require_once __DIR__ . '/../config/db.php';
if (php_sapi_name() !== 'cli') { fwrite(STDERR, "[ERR] CLI only\n"); exit(1); }
$args = implode(' ', array_slice($argv,1));
$doCommit = (strpos($args, '--commit') !== false);
$doAbort  = (strpos($args, '--abort')  !== false);
$graceMin = 10;
foreach($argv as $a){ if(preg_match('/^--grace-min=(\d+)$/',$a,$m)){ $graceMin=max(1,min(120,(int)$m[1])); } }
try{
  $pdo = db();
  if($doAbort){
    $pdo->prepare("DELETE FROM settings WHERE key='internal_secret_next'")->execute();
    fwrite(STDOUT, json_encode(['ok'=>true,'step'=>'abort','message'=>'internal_secret_next cleared'], JSON_UNESCAPED_SLASHES)."\n");
    exit(0);
  }
  if($doCommit){
    $next = $pdo->query("SELECT value FROM settings WHERE key='internal_secret_next'")->fetchColumn();
    if(!$next){ fwrite(STDERR, "[ERR] No internal_secret_next found. Run announce first.\n"); exit(2); }
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO settings(key,value) VALUES('internal_secret',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value")->execute([$next]);
    $pdo->prepare("DELETE FROM settings WHERE key='internal_secret_next'")->execute();
    $pdo->commit();
    fwrite(STDOUT, json_encode(['ok'=>true,'step'=>'commit'], JSON_UNESCAPED_SLASHES)."\n");
    exit(0);
  }
  // Announce
  $cur = $pdo->query("SELECT value FROM settings WHERE key='internal_secret'")->fetchColumn();
  $next = bin2hex(random_bytes(32));
  $pdo->prepare("INSERT INTO settings(key,value) VALUES('internal_secret_next',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value")
      ->execute([$next]);
  fwrite(STDOUT, json_encode(['ok'=>true,'step'=>'announce','grace_min'=>$graceMin,'internal_secret_next'=>$next], JSON_UNESCAPED_SLASHES)."\n");
  fwrite(STDOUT, "Next step after workers updated: php tools/rotate_internal_secret.php --commit\n");
}catch(Throwable $e){ fwrite(STDERR, "[ERR] ".$e->getMessage()."\n"); exit(1);} 