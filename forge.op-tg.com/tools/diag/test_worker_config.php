<?php
// CLI: Render worker_config.php for a specific worker id (bypassing HTTP headers)
// Usage: php tools/diag/test_worker_config.php local-wrk-1 [code]
if (php_sapi_name() !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }
$wid = isset($argv[1]) ? trim((string)$argv[1]) : '';
$code = isset($argv[2]) ? trim((string)$argv[2]) : '';
if($wid===''){ fwrite(STDERR, "usage: php tools/diag/test_worker_config.php <worker_id> [code]\n"); exit(2); }
$_GET['worker_id'] = $wid;
if($code!==''){ $_GET['code'] = $code; }
require_once __DIR__ . '/../../api/worker_config.php';
