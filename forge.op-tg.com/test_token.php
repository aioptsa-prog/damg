<?php
require_once 'config/db.php';

$token = '1b9cb78e90ab2a6768e5765b2b2da3a5';
$token_hash = hash('sha256', $token);

echo "Token: $token\n";
echo "Hash: $token_hash\n\n";

$st = db()->prepare("SELECT * FROM sessions WHERE token_hash=?");
$st->execute([$token_hash]);
$row = $st->fetch();

if ($row) {
    echo "✓ Token found in database!\n";
    echo "User ID: {$row['user_id']}\n";
    echo "Expires: {$row['expires_at']}\n";
    echo "Created: {$row['created_at']}\n";

    $now = date('Y-m-d H:i:s');
    echo "\nCurrent time: $now\n";

    if ($row['expires_at'] > $now) {
        echo "✓ Token is still valid\n";
    } else {
        echo "✗ Token has expired\n";
    }
} else {
    echo "✗ Token NOT found in database\n";
    echo "\nAll sessions:\n";
    $st = db()->query("SELECT user_id, token_hash, expires_at FROM sessions ORDER BY created_at DESC LIMIT 5");
    while ($s = $st->fetch()) {
        echo "- User: {$s['user_id']}, Hash: " . substr($s['token_hash'], 0, 16) . "..., Expires: {$s['expires_at']}\n";
    }
}
