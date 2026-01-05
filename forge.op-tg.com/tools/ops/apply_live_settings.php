<?php
// Apply recommended live-ready settings idempotently
// Usage: php tools/ops/apply_live_settings.php

require_once __DIR__ . '/../../bootstrap.php';

function S($k,$v){ set_setting($k,(string)$v); }

$plan = [
  // System on, no daily pause
  ['system_global_stop','0','Disable global stop'],
  ['system_pause_enabled','0','Disable daily pause window'],
  // Internal queue enabled
  ['internal_server_enabled','1','Enable internal server'],
  // Worker cadence
  ['worker_pull_interval_sec','30','Worker pull interval (sec)'],
  ['worker_lease_sec','180','Lease duration (sec)'],
  ['worker_report_every_ms','15000','Report cadence (ms)'],
  ['worker_report_first_ms','2000','First report delay (ms)'],
  ['worker_max_pages','5','Max parallel pages'],
  ['workers_online_window_sec','120','Online window (sec)'],
  // Picking policy
  ['job_pick_order','fifo','Job picking order'],
  // Admin UX flags
  ['ui_persist_filters','1','Persist filters in UI'],
  ['workers_admin_page_limit','300','Admin workers page limit'],
  // Worker updates
  ['worker_update_channel','stable','Default worker channel'],
  // Circuit breaker clear
  ['cb_open_workers_json','[]','Clear worker CB list'],
];

$applied = [];
foreach($plan as [$k,$v,$desc]){ S($k,$v); $applied[] = ['key'=>$k,'value'=>(string)$v,'desc'=>$desc]; }

// Summarize important settings
$keys = array_map(fn($x)=>$x[0], $plan);
$summary = [];
foreach($keys as $k){ $summary[$k] = get_setting($k,''); }

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
  'ok'=>true,
  'applied'=>$applied,
  'summary'=>$summary,
  'hint'=>'Adjust worker_pull_interval_sec/worker_max_pages according to capacity; ensure internal_secret is set to a strong value.',
], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
