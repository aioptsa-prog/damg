<?php
require 'config/db.php';

$token = '940a66c458a85239bbfc34584d88114e';
$token_hash = hash('sha256', $token);

echo "Looking for token hash: $token_hash\n\n";

// Check if this token exists
$st = db()->prepare("SELECT * FROM sessions WHERE token_hash=?");
$st->execute([$token_hash]);
$row = $st->fetch();

if ($row) {
    echo "✓ Token found!\n";
    var_dump($row);
} else {
    echo "✗ Token NOT found\n\n";

    echo "All sessions (last 5):\n";
    $st2 = db()->query("SELECT * FROM sessions ORDER BY created_at DESC LIMIT 5");
    while ($s = $st2->fetch()) {
        $expired = ($s['expires_at'] < date('Y-m-d H:i:s')) ? " [EXPIRED]" : " [VALID]";
        echo "\nSession ID: {$s['id']}\n";
        echo "  User: {$s['user_id']}\n";
        echo "  Token Hash: " . substr($s['token_hash'], 0, 20) . "...\n";
        echo "  Created: {$s['created_at']}\n";
        echo "  Expires: {$s['expires_at']}{$expired}\n";
    }
}
