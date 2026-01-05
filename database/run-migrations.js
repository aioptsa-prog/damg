/**
 * Database Migration Runner
 * Executes SQL migrations against Neon PostgreSQL
 * 
 * Features:
 * - Idempotent: Safe to run multiple times
 * - Tracked: Records executed migrations in _migrations table
 * - Uses DATABASE_URL_UNPOOLED for DDL operations
 */

import pg from 'pg';
const { Pool } = pg;

// STRICT: Migrations MUST use UNPOOLED connection (DDL requires direct connection)
const DATABASE_URL = process.env.DATABASE_URL_UNPOOLED;
if (!DATABASE_URL) {
  console.error('❌ DATABASE_URL_UNPOOLED is required for migrations.');
  console.error('   Pooled connections (DATABASE_URL) are NOT supported for DDL operations.');
  console.error('   Get the unpooled URL from Neon Dashboard → Connection Details → Direct Connection');
  process.exit(1);
}

// Validate it's actually unpooled (no -pooler in hostname)
if (DATABASE_URL.includes('-pooler')) {
  console.error('❌ DATABASE_URL_UNPOOLED contains "-pooler" - this is a pooled connection!');
  console.error('   Use the direct connection URL without "-pooler" in the hostname.');
  process.exit(1);
}

const pool = new Pool({
  connectionString: DATABASE_URL,
  ssl: { rejectUnauthorized: false }
});

async function runMigrations() {
  const client = await pool.connect();
  
  try {
    console.log('🔌 Connected to database (UNPOOLED ✓)');
    
    // Create migrations tracking table
    await client.query(`
      CREATE TABLE IF NOT EXISTS _migrations (
        id SERIAL PRIMARY KEY,
        name VARCHAR(255) UNIQUE NOT NULL,
        executed_at TIMESTAMP DEFAULT NOW()
      )
    `);
    
    // Check existing tables
    console.log('\n📊 Checking existing tables...');
    const tablesResult = await client.query(`
      SELECT table_name 
      FROM information_schema.tables 
      WHERE table_schema = 'public' 
      ORDER BY table_name
    `);
    const existingTables = tablesResult.rows.map(r => r.table_name);
    console.log('Tables found:', existingTables.join(', ') || 'None');

    // If no tables exist, create full schema
    if (existingTables.length === 0) {
      console.log('\n🏗️ Creating database schema...');
      
      // Teams Table
      await client.query(`
        CREATE TABLE IF NOT EXISTS teams (
          id VARCHAR(50) PRIMARY KEY,
          name VARCHAR(255) NOT NULL,
          manager_user_id VARCHAR(50)
        )
      `);
      console.log('  ✅ teams');

      // Users Table
      await client.query(`
        CREATE TABLE IF NOT EXISTS users (
          id VARCHAR(50) PRIMARY KEY,
          name VARCHAR(255) NOT NULL,
          email VARCHAR(255) UNIQUE NOT NULL,
          password_hash VARCHAR(255),
          role VARCHAR(20) DEFAULT 'SALES_REP' CHECK (role IN ('SUPER_ADMIN', 'MANAGER', 'SALES_REP')),
          team_id VARCHAR(50) REFERENCES teams(id) ON DELETE SET NULL,
          avatar TEXT,
          is_active BOOLEAN DEFAULT true,
          must_change_password BOOLEAN DEFAULT false,
          created_at TIMESTAMP DEFAULT NOW()
        )
      `);
      console.log('  ✅ users');

      // Leads Table
      await client.query(`
        CREATE TABLE IF NOT EXISTS leads (
          id VARCHAR(50) PRIMARY KEY,
          company_name VARCHAR(255) NOT NULL,
          activity TEXT,
          city VARCHAR(100),
          size VARCHAR(50),
          website TEXT,
          notes TEXT,
          sector JSONB,
          status VARCHAR(20) DEFAULT 'NEW' CHECK (status IN ('NEW', 'CONTACTED', 'FOLLOW_UP', 'INTERESTED', 'WON', 'LOST')),
          owner_user_id VARCHAR(50) REFERENCES users(id) ON DELETE SET NULL,
          team_id VARCHAR(50) REFERENCES teams(id) ON DELETE SET NULL,
          created_at TIMESTAMP DEFAULT NOW(),
          last_activity_at TIMESTAMP,
          created_by VARCHAR(50),
          phone VARCHAR(50),
          custom_fields JSONB,
          attachments JSONB,
          decision_maker_name VARCHAR(255),
          decision_maker_role VARCHAR(255),
          contact_email VARCHAR(255),
          budget_range VARCHAR(50),
          goal_primary TEXT,
          timeline VARCHAR(100),
          transcript TEXT,
          enrichment_signals JSONB,
          instagram VARCHAR(255),
          twitter VARCHAR(255),
          linkedin VARCHAR(255),
          facebook VARCHAR(255),
          maps VARCHAR(255),
          tiktok VARCHAR(255),
          snapchat VARCHAR(255),
          youtube VARCHAR(255),
          whatsapp VARCHAR(255)
        )
      `);
      console.log('  ✅ leads');

      // Reports Table
      await client.query(`
        CREATE TABLE IF NOT EXISTS reports (
          id VARCHAR(50) PRIMARY KEY,
          lead_id VARCHAR(50) REFERENCES leads(id) ON DELETE CASCADE,
          version_number INTEGER,
          provider VARCHAR(20),
          model VARCHAR(100),
          prompt_version VARCHAR(50),
          output JSONB,
          change_log TEXT,
          usage JSONB,
          created_at TIMESTAMP DEFAULT NOW()
        )
      `);
      console.log('  ✅ reports');

      // Tasks Table
      await client.query(`
        CREATE TABLE IF NOT EXISTS tasks (
          id VARCHAR(50) PRIMARY KEY,
          lead_id VARCHAR(50) REFERENCES leads(id) ON DELETE CASCADE,
          assigned_to_user_id VARCHAR(50) REFERENCES users(id) ON DELETE SET NULL,
          day_number INTEGER,
          channel VARCHAR(20) CHECK (channel IN ('call', 'whatsapp', 'email')),
          goal TEXT,
          action TEXT,
          status VARCHAR(20) DEFAULT 'OPEN' CHECK (status IN ('OPEN', 'DONE', 'SKIPPED')),
          due_date TIMESTAMP
        )
      `);
      console.log('  ✅ tasks');

      // Activities Table
      await client.query(`
        CREATE TABLE IF NOT EXISTS activities (
          id VARCHAR(50) PRIMARY KEY,
          lead_id VARCHAR(50) REFERENCES leads(id) ON DELETE CASCADE,
          user_id VARCHAR(50) REFERENCES users(id) ON DELETE SET NULL,
          type VARCHAR(50),
          payload JSONB,
          created_at TIMESTAMP DEFAULT NOW()
        )
      `);
      console.log('  ✅ activities');

      // Audit Logs Table
      await client.query(`
        CREATE TABLE IF NOT EXISTS audit_logs (
          id VARCHAR(50) PRIMARY KEY,
          actor_user_id VARCHAR(50),
          action VARCHAR(100),
          entity_type VARCHAR(50),
          entity_id VARCHAR(100),
          before JSONB,
          after JSONB,
          created_at TIMESTAMP DEFAULT NOW()
        )
      `);
      console.log('  ✅ audit_logs');

      // Usage Logs Table
      await client.query(`
        CREATE TABLE IF NOT EXISTS usage_logs (
          id VARCHAR(50) PRIMARY KEY,
          model VARCHAR(100),
          provider VARCHAR(20),
          latency_ms INTEGER,
          input_tokens INTEGER,
          output_tokens INTEGER,
          cost DECIMAL(10, 6),
          status VARCHAR(20),
          error TEXT,
          created_at TIMESTAMP DEFAULT NOW()
        )
      `);
      console.log('  ✅ usage_logs');

      // Settings Table
      await client.query(`
        CREATE TABLE IF NOT EXISTS settings (
          key VARCHAR(100) PRIMARY KEY,
          value JSONB,
          updated_at TIMESTAMP DEFAULT NOW()
        )
      `);
      console.log('  ✅ settings');
    }

    // Create Indexes
    console.log('\n🚀 Creating indexes...');
    
    const indexQueries = [
      'CREATE INDEX IF NOT EXISTS idx_users_email ON users(email)',
      'CREATE INDEX IF NOT EXISTS idx_users_team_id ON users(team_id)',
      'CREATE INDEX IF NOT EXISTS idx_users_role ON users(role)',
      'CREATE INDEX IF NOT EXISTS idx_leads_owner ON leads(owner_user_id)',
      'CREATE INDEX IF NOT EXISTS idx_leads_team ON leads(team_id)',
      'CREATE INDEX IF NOT EXISTS idx_leads_status ON leads(status)',
      'CREATE INDEX IF NOT EXISTS idx_leads_created ON leads(created_at DESC)',
      'CREATE INDEX IF NOT EXISTS idx_reports_lead ON reports(lead_id)',
      'CREATE INDEX IF NOT EXISTS idx_reports_version ON reports(lead_id, version_number DESC)',
      'CREATE INDEX IF NOT EXISTS idx_activities_lead ON activities(lead_id)',
      'CREATE INDEX IF NOT EXISTS idx_activities_user ON activities(user_id)',
      'CREATE INDEX IF NOT EXISTS idx_activities_created ON activities(created_at DESC)',
      'CREATE INDEX IF NOT EXISTS idx_tasks_lead ON tasks(lead_id)',
      'CREATE INDEX IF NOT EXISTS idx_tasks_assigned ON tasks(assigned_to_user_id)',
      'CREATE INDEX IF NOT EXISTS idx_tasks_status ON tasks(status)',
      'CREATE INDEX IF NOT EXISTS idx_audit_logs_created ON audit_logs(created_at DESC)',
      'CREATE INDEX IF NOT EXISTS idx_audit_logs_actor ON audit_logs(actor_user_id)',
      'CREATE INDEX IF NOT EXISTS idx_usage_logs_created ON usage_logs(created_at DESC)',
    ];

    for (const query of indexQueries) {
      await client.query(query);
      const indexName = query.match(/idx_\w+/)?.[0] || 'unknown';
      console.log(`  ✅ ${indexName}`);
    }

    // Record migration as executed
    await client.query(`
      INSERT INTO _migrations (name) VALUES ('001_initial_schema')
      ON CONFLICT (name) DO NOTHING
    `);

    // Final verification
    console.log('\n📊 Final verification...');
    const finalTables = await client.query(`
      SELECT table_name FROM information_schema.tables 
      WHERE table_schema = 'public' ORDER BY table_name
    `);
    console.log('Tables:', finalTables.rows.map(r => r.table_name).join(', '));
    
    const finalIndexes = await client.query(`
      SELECT COUNT(*) as count FROM pg_indexes WHERE schemaname = 'public'
    `);
    console.log(`Indexes: ${finalIndexes.rows[0].count}`);

    // Show executed migrations
    const migrations = await client.query('SELECT name, executed_at FROM _migrations ORDER BY id');
    console.log('\nExecuted migrations:');
    migrations.rows.forEach(m => console.log(`  ✓ ${m.name} (${m.executed_at.toISOString()})`));

    console.log('\n✅ Database setup completed successfully!');
    
  } catch (err) {
    console.error('❌ Migration failed:', err.message);
    throw err;
  } finally {
    client.release();
    await pool.end();
  }
}

runMigrations().catch(err => {
  console.error(err);
  process.exit(1);
});
