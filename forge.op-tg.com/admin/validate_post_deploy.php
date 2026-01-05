<?php
require_once __DIR__ . '/../bootstrap.php';
require_role('admin');
header('Content-Type: application/json; charset=utf-8');

// Expose validate_post_deploy via admin route
require_once __DIR__ . '/../tools/release/validate_post_deploy.php';
exit;
