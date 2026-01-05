/**
 * Cleanup Script - Delete all old reports, tasks, and activities
 * This ensures the database is clean and no malformed data causes crashes
 */

const pg = require('pg');
require('dotenv').config();

const { Pool } = pg;

const pool = new Pool({
  connectionString: process.env.DATABASE_URL_UNPOOLED || process.env.DATABASE_URL,
  ssl: { rejectUnauthorized: false }
});

async function cleanup() {
  const client = await pool.connect();
  
  try {
    console.log('🧹 Starting database cleanup...\n');
    
    // Start transaction
    await client.query('BEGIN');
    
    // 1. Delete all tasks
    const tasksResult = await client.query('DELETE FROM tasks RETURNING id');
    console.log(`✅ Deleted ${tasksResult.rowCount} tasks`);
    
    // 2. Delete all activities
    const activitiesResult = await client.query('DELETE FROM activities RETURNING id');
    console.log(`✅ Deleted ${activitiesResult.rowCount} activities`);
    
    // 3. Delete all reports
    const reportsResult = await client.query('DELETE FROM reports RETURNING id');
    console.log(`✅ Deleted ${reportsResult.rowCount} reports`);
    
    // 4. Delete all leads
    const leadsResult = await client.query('DELETE FROM leads RETURNING id');
    console.log(`✅ Deleted ${leadsResult.rowCount} leads`);
    
    // 5. Delete all usage logs
    const usageResult = await client.query('DELETE FROM usage_logs RETURNING id');
    console.log(`✅ Deleted ${usageResult.rowCount} usage logs`);
    
    // 6. Delete all audit logs (except user creation)
    const auditResult = await client.query("DELETE FROM audit_logs WHERE action != 'user_created' RETURNING id");
    console.log(`✅ Deleted ${auditResult.rowCount} audit logs`);
    
    // Commit transaction
    await client.query('COMMIT');
    
    console.log('\n🎉 Database cleanup completed successfully!');
    console.log('The system is now clean and ready for fresh data.');
    
  } catch (error) {
    await client.query('ROLLBACK');
    console.error('❌ Cleanup failed:', error.message);
    throw error;
  } finally {
    client.release();
    await pool.end();
  }
}

cleanup().catch(console.error);
