<?php
/**
 * Get Token and Test Search API via HTTP
 */

require_once __DIR__ . '/config/db.php';

echo "Getting valid token from database...\n\n";

$pdo = db();

// Get a valid session token
$stmt = $pdo->query("
    SELECT ps.token_hash, ps.user_id, pu.email, pu.name
    FROM public_sessions ps 
    JOIN public_users pu ON ps.user_id = pu.id 
    WHERE ps.expires_at > datetime('now')
    AND pu.status = 'active'
    LIMIT 1
");
$session = $stmt->fetch();

if (!$session) {
    echo "No valid sessions found. Creating a test session...\n";

    // Get or create a user
    $stmt = $pdo->query("SELECT id FROM public_users WHERE status = 'active' LIMIT 1");
    $user = $stmt->fetch();

    if (!$user) {
        echo "Creating test user...\n";
        $stmt = $pdo->prepare("INSERT INTO public_users (email, password_hash, name, status, email_verified, created_at) 
                               VALUES (?, ?, ?, 'active', 1, datetime('now'))");
        $stmt->execute(['testuser@test.com', password_hash('test1234', PASSWORD_DEFAULT), 'Test User']);
        $userId = $pdo->lastInsertId();
    } else {
        $userId = $user['id'];
    }

    // Create new token
    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $expiresAt = date('Y-m-d H:i:s', time() + 86400 * 30);

    $stmt = $pdo->prepare("INSERT INTO public_sessions (user_id, token_hash, expires_at, device_info) 
                           VALUES (?, ?, ?, 'Test Script')");
    $stmt->execute([$userId, $tokenHash, $expiresAt]);

    echo "Created new token: $rawToken\n\n";

} else {
    echo "Found existing session for: {$session['name']} ({$session['email']})\n";
    echo "Token hash stored: {$session['token_hash']}\n\n";

    // We need to create a new token since we only have the hash
    echo "Creating new token for testing...\n";
    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $expiresAt = date('Y-m-d H:i:s', time() + 86400 * 30);

    $stmt = $pdo->prepare("INSERT INTO public_sessions (user_id, token_hash, expires_at, device_info) 
                           VALUES (?, ?, ?, 'Test Script')");
    $stmt->execute([$session['user_id'], $tokenHash, $expiresAt]);

    $userId = $session['user_id'];
}

echo "Token for API testing: $rawToken\n\n";

// Count leads
$stmt = $pdo->query("SELECT COUNT(*) FROM leads");
$totalLeads = $stmt->fetchColumn();
echo "Total leads in database: $totalLeads\n\n";

// Get sample cities
echo "Top cities:\n";
$stmt = $pdo->query("SELECT city, COUNT(*) as cnt FROM leads WHERE city IS NOT NULL AND city != '' GROUP BY city ORDER BY cnt DESC LIMIT 5");
while ($row = $stmt->fetch()) {
    echo "  - {$row['city']}: {$row['cnt']}\n";
}

// Get sample categories
echo "\nTop categories:\n";
$stmt = $pdo->query("SELECT c.name, COUNT(l.id) as cnt 
                     FROM categories c 
                     LEFT JOIN leads l ON l.category_id = c.id 
                     WHERE c.is_active = 1
                     GROUP BY c.id 
                     ORDER BY cnt DESC 
                     LIMIT 5");
while ($row = $stmt->fetch()) {
    echo "  - {$row['name']}: {$row['cnt']} leads\n";
}

echo "\n===========================================\n";
echo "To test the search API, use this curl command:\n";
echo "===========================================\n\n";

echo 'curl -s -H "Authorization: Bearer ' . $rawToken . '" "http://localhost:8080/v1/api/public/leads/search.php?page=1&limit=5" | jq .' . "\n\n";

echo "Or test with city filter:\n";
echo 'curl -s -H "Authorization: Bearer ' . $rawToken . '" "http://localhost:8080/v1/api/public/leads/search.php?city=الرياض&limit=5" | jq .' . "\n";

// Now let's test the API directly using PHP
echo "\n===========================================\n";
echo "Testing API directly...\n";
echo "===========================================\n\n";

$url = "http://localhost:8080/v1/api/public/leads/search.php?page=1&limit=5";
$opts = [
    'http' => [
        'method' => 'GET',
        'header' => "Authorization: Bearer $rawToken\r\n"
    ]
];
$context = stream_context_create($opts);
$response = @file_get_contents($url, false, $context);

if ($response === false) {
    echo "Failed to connect to API. Is the server running at localhost:8080?\n";
} else {
    echo "API Response:\n";
    $data = json_decode($response, true);
    if ($data) {
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } else {
        echo $response;
    }
}
echo "\n";
