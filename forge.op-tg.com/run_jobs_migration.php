<?php
/**
 * Run Jobs and Leads Migration
 */

require_once __DIR__ . '/config/db.php';

try {
    $pdo = db();

    echo "Running migration: 002_jobs_and_leads_system.sql\n";
    echo "=================================================\n\n";

    $sql = file_get_contents(__DIR__ . '/migrations/002_jobs_and_leads_system.sql');

    // Remove comments
    $sql = preg_replace('/--[^\n]*\n/', '', $sql);

    // Execute
    $pdo->exec($sql);

    echo "✓ Migration completed successfully!\n\n";

    // Add job_id column to leads if it doesn't exist
    echo "Adding job_id column to leads table...\n";
    try {
        $pdo->exec("ALTER TABLE leads ADD COLUMN job_id INTEGER REFERENCES jobs(id)");
        echo "  ✓ job_id column added\n";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'duplicate column name') !== false) {
            echo "  ✓ job_id column already exists\n";
        } else {
            throw $e;
        }
    }

    // Add indexes
    echo "Creating indexes...\n";
    try {
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_leads_job ON leads(job_id)");
        echo "  ✓ idx_leads_job created\n";
    } catch (Exception $e) {
        echo "  ! Index creation skipped: " . $e->getMessage() . "\n";
    }

    // Verify tables
    echo "\nVerifying tables...\n";

    $tables = ['jobs', 'leads'];
    foreach ($tables as $table) {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM $table");
        $count = $stmt->fetchColumn();
        echo "  ✓ $table: $count records\n";
    }

    echo "\n✓ All tables verified!\n";

} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}
