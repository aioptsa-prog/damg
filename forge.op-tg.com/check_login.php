<?php
require_once __DIR__ . '/bootstrap.php';

$pdo = db();
$mobile = '590000000';
$password = 'Forge@2025!';

$user = $pdo->prepare("SELECT id, mobile, name, role, password_hash, is_superadmin FROM users WHERE mobile = ?");
$user->execute([$mobile]);
$row = $user->fetch();

if (!$row) {
    echo "User not found!\n";
    exit(1);
}

echo "User found:\n";
echo "  ID: " . $row['id'] . "\n";
echo "  Mobile: " . $row['mobile'] . "\n";
echo "  Name: " . $row['name'] . "\n";
echo "  Role: " . $row['role'] . "\n";
echo "  Is Superadmin: " . $row['is_superadmin'] . "\n";
echo "  Hash: " . substr($row['password_hash'], 0, 30) . "...\n";
echo "\n";

$verify = password_verify($password, $row['password_hash']);
echo "Password '$password' verification: " . ($verify ? "SUCCESS" : "FAILED") . "\n";
