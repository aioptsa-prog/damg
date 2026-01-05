<?php
require_once __DIR__ . '/../bootstrap.php';
require_role('admin');
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../tools/monitor/synthetic_alert.php';
exit;
