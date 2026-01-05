<?php
/**
 * Create test public user
 */
require_once __DIR__ . '/config/db.php';

$pdo = db();

echo "Creating test public user...\n\n";

$email = 'test@example.com';
$password = 'Test123!';
$name = 'مستخدم تجريبي';

// Check if exists
$stmt = $pdo->prepare("SELECT id FROM public_users WHERE email = ?");
$stmt->execute([$email]);
if ($stmt->fetch()) {
    echo "User already exists!\n";
    $stmt = $pdo->prepare("SELECT id FROM public_users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    $userId = $user['id'];
} else {
    // Create user
    $stmt = $pdo->prepare("
        INSERT INTO public_users (email, password_hash, name, email_verified, status)
        VALUES (?, ?, ?, 1, 'active')
    ");
    $stmt->execute([$email, password_hash($password, PASSWORD_DEFAULT), $name]);
    $userId = $pdo->lastInsertId();
    echo "User created! ID: $userId\n";
}

// Create session token
$token = bin2hex(random_bytes(32));
$token_hash = hash('sha256', $token);
$expires = date('Y-m-d H:i:s', time() + 86400 * 30); // 30 days

$stmt = $pdo->prepare("
    INSERT INTO public_sessions (user_id, token_hash, expires_at, device_info)
    VALUES (?, ?, ?, 'CLI Test')
");
$stmt->execute([$userId, $token_hash, $expires]);

echo "\n========================================\n";
echo "  Test User Created!\n";
echo "========================================\n\n";
echo "Email: $email\n";
echo "Password: $password\n";
echo "Token: $token\n";
echo "\nTest with:\n";
echo "curl -H \"Authorization: Bearer $token\" http://localhost:8080/v1/api/campaigns/index.php\n";
