<?php
require_once __DIR__ . '/bootstrap.php';
$pdo = db();
$r = $pdo->query("PRAGMA table_info(leads)");
while($row = $r->fetch(PDO::FETCH_ASSOC)) {
    echo $row['name'] . ' | ' . $row['type'] . "\n";
}
