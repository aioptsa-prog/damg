<?php
/**
 * Debug campaigns API
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "Step 1: Loading config\n";
require_once __DIR__ . '/config/db.php';

echo "Step 2: DB connection\n";
$pdo = db();
if ($pdo) {
    echo "DB OK\n";
}

echo "Step 3: Check user_campaigns table\n";
try {
    $result = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='user_campaigns'");
    $table = $result->fetch();
    if ($table) {
        echo "Table user_campaigns exists\n";
    } else {
        echo "Table user_campaigns NOT FOUND!\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "Step 4: Check public_users table\n";
try {
    $result = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='public_users'");
    $table = $result->fetch();
    if ($table) {
        echo "Table public_users exists\n";
    } else {
        echo "Table public_users NOT FOUND!\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "Step 5: Check public_sessions table\n";
try {
    $result = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='public_sessions'");
    $table = $result->fetch();
    if ($table) {
        echo "Table public_sessions exists\n";
    } else {
        echo "Table public_sessions NOT FOUND!\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "Step 6: Loading public_auth\n";
try {
    require_once __DIR__ . '/lib/public_auth.php';
    echo "public_auth.php loaded OK\n";
} catch (Exception $e) {
    echo "Error loading public_auth: " . $e->getMessage() . "\n";
}

echo "\nDone\n";
