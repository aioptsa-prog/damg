<?php
require_once __DIR__ . '/config/db.php';

$cols = db()->query('PRAGMA table_info(leads)')->fetchAll();

echo "Leads table columns:\n";
echo "===================\n\n";

foreach ($cols as $c) {
    echo "{$c['name']} ({$c['type']})\n";
}
