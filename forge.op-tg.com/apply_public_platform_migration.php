<?php
/**
 * Apply Public Platform Database Schema Migration
 * This script applies the public platform schema (subscription plans, users, etc.)
 */

require_once __DIR__ . '/config/db.php';

echo "============================================\n";
echo "Public Platform Migration Script\n";
echo "============================================\n\n";

$migration_file = __DIR__ . '/migrations/001_public_platform_schema.sql';

if (!file_exists($migration_file)) {
    die("ERROR: Migration file not found: $migration_file\n");
}

echo "Reading migration file...\n";
$sql = file_get_contents($migration_file);

if ($sql === false) {
    die("ERROR: Could not read migration file\n");
}

echo "Connecting to database...\n";
try {
    $pdo = db();  // Use existing db() function
    $pdo->exec('PRAGMA foreign_keys = ON');

    echo "Applying migration...\n\n";

    // Split by semicolons and execute each statement
    $statements = explode(';', $sql);
    $executed = 0;
    $errors = 0;

    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (empty($statement) || strpos($statement, '--') === 0) {
            continue;
        }

        try {
            $pdo->exec($statement);
            $executed++;

            // Show progress for important statements
            if (stripos($statement, 'CREATE TABLE') !== false) {
                preg_match('/CREATE TABLE IF NOT EXISTS (\w+)/i', $statement, $matches);
                if ($matches) {
                    echo "✓ Created table: {$matches[1]}\n";
                }
            } elseif (stripos($statement, 'INSERT') !== false) {
                echo "✓ Inserted seed data\n";
            }

        } catch (Exception $e) {
            $errors++;
            echo "✗ Error: " . $e->getMessage() . "\n";
            echo "  Statement: " . substr($statement, 0, 100) . "...\n";
        }
    }

    echo "\n============================================\n";
    echo "Migration Complete!\n";
    echo "Executed: $executed statements\n";
    echo "Errors: $errors\n";
    echo "============================================\n\n";

    // Verify tables were created
    echo "Verifying created tables:\n";
    $result = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND (name LIKE 'public_%' OR name LIKE 'subscription_%' OR name LIKE '%_history' OR name LIKE 'saved_%' OR name = 'usage_tracking' OR name = 'revealed_contacts' OR name = 'export_history') ORDER BY name");

    $tables = $result->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        echo "  ✓ $table\n";
    }

    echo "\nVerifying subscription plans:\n";
    $plans = $pdo->query("SELECT id, name, slug, price_monthly, price_yearly FROM subscription_plans ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

    if (count($plans) > 0) {
        foreach ($plans as $plan) {
            echo "  ✓ [{$plan['id']}] {$plan['name']} ({$plan['slug']}) - {$plan['price_monthly']} SAR/month\n";
        }
    } else {
        echo "  ⚠ No subscription plans found!\n";
    }

    echo "\n✅ Migration applied successfully!\n\n";

} catch (Exception $e) {
    die("ERROR: " . $e->getMessage() . "\n");
}
