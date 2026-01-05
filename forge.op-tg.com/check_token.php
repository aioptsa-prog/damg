<?php
/**
 * Check if the user's token is valid
 */
require_once __DIR__ . '/config/db.php';

// Token from browser (first 20 chars shown)
// The full token starts with: b235374f4e6b9c7f0a73
$partialToken = 'b235374f4e6b9c7f0a73';

echo "=== Token Validation Check ===\n\n";

$pdo = db();

// Get all sessions sorted by expiry
$sessions = $pdo->query("
    SELECT s.id, s.user_id, s.token_hash, s.expires_at, s.created_at, u.name as user_name
    FROM sessions s 
    JOIN users u ON s.user_id = u.id 
    ORDER BY s.expires_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

echo "All sessions:\n";
foreach ($sessions as $s) {
    $expired = strtotime($s['expires_at']) < time() ? '❌ EXPIRED' : '✅ VALID';
    echo "  [{$s['id']}] User: {$s['user_name']} - Expires: {$s['expires_at']} - $expired\n";
}

echo "\n\nNote: If all sessions show expired, user needs to log out and log in again.\n";

// Count valid sessions
$valid = $pdo->query("SELECT COUNT(*) FROM sessions WHERE expires_at > datetime('now')")->fetchColumn();
echo "\nValid sessions count: $valid\n";

echo "\n=== Done ===\n";
