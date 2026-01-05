<?php
/**
 * Simple DB Connection Test
 * Tests if database is accessible and has tables
 */

require_once __DIR__ . '/bootstrap.php';

try {
    $pdo = db();
    echo "✅ Database connection successful!\n\n";

    // Test tables
    $tables = ['users', 'leads', 'categories', 'internal_jobs'];
    foreach ($tables as $table) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM $table");
        $count = $stmt->fetchColumn();
        echo "✅ Table '$table': $count rows\n";
    }

    // Test a user exists
    $stmt = $pdo->query("SELECT mobile, role FROM users LIMIT 1");
    $user = $stmt->fetch();
    if ($user) {
        echo "\n✅ Sample user found: {$user['mobile']} ({$user['role']})\n";
    }

    echo "\n✅ All database tests passed!\n";

} catch (Throwable $e) {
    echo "❌ Database error: " . $e->getMessage() . "\n";
    exit(1);
}
