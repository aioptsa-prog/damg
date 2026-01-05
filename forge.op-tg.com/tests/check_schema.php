<?php
require_once __DIR__ . '/../bootstrap.php';
$cols = db()->query('PRAGMA table_info(internal_jobs)')->fetchAll(PDO::FETCH_ASSOC);
foreach($cols as $c) {
    echo $c['name'] . ' | ' . $c['type'] . ' | notnull:' . $c['notnull'] . ' | default:' . ($c['dflt_value'] ?? 'NULL') . PHP_EOL;
}
