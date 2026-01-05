<?php
require 'config/db.php';

echo "Finding the valid token from session ID 22...\n\n";

// Get the valid session
$stmt = db()->query("SELECT * FROM sessions WHERE id = 22");
$session = $stmt->fetch();

if ($session) {
    echo "✓ Found session:\n";
    echo "  ID: {$session['id']}\n";
    echo "  User ID: {$session['user_id']}\n";
    echo "  Token Hash: {$session['token_hash']}\n";
    echo "  Created: {$session['created_at']}\n";
    echo "  Expires: {$session['expires_at']}\n\n";

    // The problem is we have the hash, but not the original token
    // We need to check what token React is actually receiving

    echo "NOTE: We cannot retrieve the original token from the hash.\n";
    echo "The issue is that React is receiving a token that doesn't match.\n\n";

    // Let's check what the login.php returns
    echo "Let's simulate a fresh login to see what token is generated:\n";
    echo "Run this command in browser console after login:\n";
    echo "  localStorage.getItem('lead_iq_auth_token')\n\n";

} else {
    echo "✗ Session 22 not found\n";
}

// Check if the token React received exists
$tokens_to_check = [
    '940a66c458a85239bbfc34584d88114e',  // First attempt
    '6be44b38321862a5befe787c3e989179',  // Second attempt
];

echo "\nChecking if React's tokens exist in database:\n";
foreach ($tokens_to_check as $token) {
    $hash = hash('sha256', $token);
    $stmt = db()->prepare("SELECT id, user_id FROM sessions WHERE token_hash = ?");
    $stmt->execute([$hash]);
    $result = $stmt->fetch();

    if ($result) {
        echo "  ✓ Token $token found (Session {$result['id']}, User {$result['user_id']})\n";
    } else {
        echo "  ✗ Token $token NOT in database\n";
    }
}
