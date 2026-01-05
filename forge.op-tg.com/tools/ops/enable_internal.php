<?php
// Enable internal mode and set the secret to 'testsecret'
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../lib/auth.php';
header('Content-Type: text/plain; charset=utf-8');
set_setting('internal_server_enabled','1');
set_setting('internal_secret','testsecret');
echo "OK\n";
