<?php
// Run migration script
require_once 'config/db.php';

echo "Running migration: 001_public_platform_schema.sql\n";
echo "=================================================\n\n";

$sql = file_get_contents(__DIR__ . '/migrations/001_public_platform_schema.sql');

try {
    $pdo = db();

    // Execute the migration
    $pdo->exec($sql);

    echo "✓ Migration completed successfully!\n\n";

    // Verify tables were created
    echo "Verifying tables...\n";
    $tables = [
        'public_users',
        'public_sessions',
        'subscription_plans',
        'user_subscriptions',
        'payment_history',
        'usage_tracking',
        'saved_searches',
        'saved_lists',
        'saved_list_items',
        'revealed_contacts',
        'export_history'
    ];

    foreach ($tables as $table) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM $table");
        $count = $stmt->fetchColumn();
        echo "  ✓ $table: $count records\n";
    }

    echo "\n✓ All tables created successfully!\n";

} catch (Exception $e) {
    echo "✗ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
