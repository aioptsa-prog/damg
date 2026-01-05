<?php
require_once __DIR__ . '/../bootstrap.php';
require_role('admin');
header('Content-Type: application/json; charset=utf-8');

// Prefer configured base; otherwise derive from request
if (!getenv('SYNTH_BASE_URL')) {
  $base = app_base_url();
  if ($base) putenv('SYNTH_BASE_URL=' . $base);
}

// Run the synthetic monitor script (echoes JSON)
require_once __DIR__ . '/../tools/monitor/synthetic.php';
exit;
