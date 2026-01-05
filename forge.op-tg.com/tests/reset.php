<?php
// DEV-ONLY: Resets core tables and key settings to a known baseline for tests
require_once __DIR__ . '/../bootstrap.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

$tables = [
  'assignments', 'leads', 'internal_jobs', 'internal_workers',
  'category_rules','category_keywords','categories', 'usage_counters', 'place_cache', 'search_tiles'
];
foreach($tables as $t){ try{ $pdo->exec("DELETE FROM $t"); }catch(Throwable $e){} }

// Reset a subset of settings
$defaults = [
  'system_global_stop'=>'0',
  'system_pause_enabled'=>'0',
  'system_pause_start'=>'23:59',
  'system_pause_end'=>'09:00',
  'classify_enabled'=>'1',
  'classify_threshold'=>'1.0',
  'job_pick_order'=>'fifo',
  'rr_last_agent_id'=>'0',
  'export_max_rows'=>'50000',
  'internal_server_enabled'=>'1',
  'internal_secret'=>'testsecret',
];
foreach($defaults as $k=>$v){ set_setting($k,$v); }

echo "DB reset done\n";
