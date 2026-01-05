<?php
require_once __DIR__ . '/../bootstrap.php';
$cols = db()->query('PRAGMA table_info(google_web_cache)')->fetchAll(PDO::FETCH_ASSOC);
foreach($cols as $c) {
    echo $c['name'] . PHP_EOL;
}
