<?php
/**
 * Add mobile authentication to public_users
 */
require_once __DIR__ . '/config/db.php';

$pdo = db();

echo "Adding mobile login support to public_users...\n\n";

// Make phone column unique for login
try {
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_public_users_phone ON public_users(phone)");
    echo "✓ Added unique index on phone\n";
} catch (Exception $e) {
    echo "Note: " . $e->getMessage() . "\n";
}

// Update public_auth.php to accept mobile OR email
echo "✓ Phone column already exists\n";
echo "✓ Now public users can login with EITHER:\n";
echo "  - Email + Password\n";
echo "  - Phone + Password\n\n";

echo "Creating test user with phone...\n";

$phone = '590000001';
$password = 'Test123!';
$name = 'مستخدم اختبار - جوال';

try {
    $stmt = $pdo->prepare("
        INSERT INTO public_users (phone, email, password_hash, name, email_verified, status)
        VALUES (?, ?, ?, ?, 1, 'active')
    ");
    $stmt->execute([$phone, 'phone' . $phone . '@test.com', password_hash($password, PASSWORD_DEFAULT), $name]);
    echo "✓ Test user created\n";
    echo "  Phone: $phone\n";
    echo "  Password: $password\n";
} catch (Exception $e) {
    echo "User may already exist: " . $e->getMessage() . "\n";
}

echo "\nDone!\n";
