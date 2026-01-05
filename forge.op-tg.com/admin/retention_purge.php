<?php
require_once __DIR__ . '/../bootstrap.php';
require_role('admin');
header('Content-Type: text/plain; charset=utf-8');

// Allow web-based dry-run via query string; tools script already supports it
require_once __DIR__ . '/../tools/ops/retention_purge.php';
exit;
