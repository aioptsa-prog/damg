<?php
// tools/ops/deploy_apply_settings.php
// Apply production-safe baseline settings. Run via: php tools/ops/deploy_apply_settings.php

require_once __DIR__ . '/../../bootstrap.php';

// Parse CLI flags of the form --key=value (case-insensitive)
$cli = [];
foreach (($argv ?? []) as $arg) {
  if (strpos($arg, '--') === 0 && strpos($arg, '=') !== false) {
    [$k,$v] = explode('=', substr($arg, 2), 2);
    $cli[strtolower(trim($k))] = trim($v);
  }
}

function cli_or_env_flag(array $cli, string $name, string $default): string {
  $lname = strtolower($name);
  if (isset($cli[$lname])) return (string)(int)$cli[$lname];
  $envName = strtoupper($name);
  $ge = getenv($envName);
  if ($ge !== false) return (string)(int)$ge;
  return $default;
}

function apply_setting($k,$v){
  try{ set_setting($k, (string)$v); echo "set $k=$v\n"; }catch(Throwable $e){ echo "failed $k: ".$e->getMessage()."\n"; }
}

// Flags (1=enable)
$flags = [
  'force_https' => cli_or_env_flag($cli, 'force_https', '1'),
  'security_csrf_auto' => cli_or_env_flag($cli, 'security_csrf_auto', '1'),
  'rate_limit_basic' => cli_or_env_flag($cli, 'rate_limit_basic', '1'),
  'per_worker_secret_required' => cli_or_env_flag($cli, 'per_worker_secret_required', '1'),
];
foreach($flags as $k=>$v){ apply_setting($k,$v); }

// Rate limits
apply_setting('rate_limit_global_per_min', $cli['rate_limit_global_per_min'] ?? (getenv('RATE_LIMIT_GLOBAL_PER_MIN') ?: '600'));
apply_setting('workers_online_window_sec', getenv('WORKERS_ONLINE_WINDOW_SEC') ?: '90');

// Internal secret: ensure present; if rotating requested, set internal_secret_next
$cur = get_setting('internal_secret','');
if($cur===''){
  $new = bin2hex(random_bytes(32));
  apply_setting('internal_secret', $new);
}
if(($n = getenv('INTERNAL_SECRET_NEXT'))){ apply_setting('internal_secret_next', $n); }

echo "Done.\n";
?>
