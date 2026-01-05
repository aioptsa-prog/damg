<?php
require_once __DIR__ . '/../../bootstrap.php';
set_setting('internal_server_enabled','1');
$sec = get_setting('internal_secret','');
echo "internal_server_enabled=1\n";
echo "internal_secret=$sec\n";
