<?php
require_once __DIR__ . '/../../bootstrap.php';
$pdo = db();
$cols = $pdo->query("PRAGMA table_info(leads)")->fetchAll(PDO::FETCH_ASSOC);
foreach($cols as $c){ echo $c['cid'].": ".$c['name']."\n"; }
