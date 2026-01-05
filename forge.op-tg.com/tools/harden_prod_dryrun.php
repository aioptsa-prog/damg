<?php
// Dry-run: show production hardening toggles and their current values.
require_once __DIR__ . '/../config/db.php';
$pdo = db();
$keys = [
  'force_https','security_csrf_auto','rate_limit_basic','rate_limit_global_per_min',
  'rate_limit_per_worker_per_min','per_worker_secret_required','workers_online_window_sec'
];
$out = [];
foreach($keys as $k){ $out[$k] = (string)$pdo->query("SELECT value FROM settings WHERE key='".$k."'")->fetchColumn(); }
echo json_encode(['ok'=>true,'settings'=>$out], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),"\n";