<?php
require_once __DIR__ . '/../bootstrap.php';
require_role('admin');
header('Content-Type: text/plain; charset=utf-8');

// Run preflight checks via admin wrapper (tools path is blocked by .htaccess)
require_once __DIR__ . '/../tools/release/preflight.php';
exit;
