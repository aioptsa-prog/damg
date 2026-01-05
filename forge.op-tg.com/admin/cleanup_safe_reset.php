<?php
require_once __DIR__ . '/../bootstrap.php';
require_role('admin');
header('Content-Type: application/json; charset=utf-8');

// Expose cleanup_safe_reset via admin route
require_once __DIR__ . '/../tools/ops/cleanup_safe_reset.php';
exit;
