<?php
if (php_sapi_name() !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }
require_once __DIR__ . '/../../bootstrap.php';
$table = $argv[1] ?? '';
if($table===''){ fwrite(STDERR, "Usage: php tools/diag/table_info.php <table>\n"); exit(1); }
$pdo = db();
$st = $pdo->query("PRAGMA table_info(".$table.")");
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
foreach($rows as $r){
    echo ($r['cid'] ?? ''),":",($r['name'] ?? '')," ",($r['type'] ?? '')," notnull=",($r['notnull'] ?? '')," dflt=",($r['dflt_value'] ?? '')," pk=",($r['pk'] ?? ''),"\n";
}
