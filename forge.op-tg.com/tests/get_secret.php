<?php
require_once __DIR__ . '/../bootstrap.php';
$s = db()->query("SELECT value FROM settings WHERE key='internal_secret'")->fetchColumn();
echo $s ?: 'NOT_SET';
