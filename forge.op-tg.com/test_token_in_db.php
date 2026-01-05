<?php
require 'config/db.php';

$token = '940a66c458a85239bbfc34584d88114e';
$token_hash = hash('sha256', $token);

echo "Looking for token: $token\n";
echo "Hash: $token_hash\n\n";

// Check if this token exists
$st = db()->prepare("SELECT * FROM sessions WHERE token_hash=?");
$st->execute([$token_hash]);
$row = $st->fetch();

if ($row) {
    echo "✓ Token found!\n";
    print_r($row);
} else {
    echo "✗ Token NOT found in sessions table\n\n";

    echo "Recent sessions:\n";
    $st = db()->query("SELECT id, user_id, SUBSTRING(token_hash, 1, 16) as token_preview, device_info, created_at, expires_at FROM sessions ORDER BY created_at DESC LIMIT 5");
    while ($s = $st->fetch()) {
        $expired = ($s['expires_at'] < date('Y-m-d H:i:s')) ? " [EXPIRED]" : "";
        echo "- ID:{$s['id']}, User:{$s['user_id']}, Token:{$s['token_preview']}..., Created:{$s['created_at']}, Expires:{$s['expires_at']}{$expired}\n";
    }
}
