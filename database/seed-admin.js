/**
 * Seed Admin User
 * Creates initial SUPER_ADMIN user directly in database
 */

import pg from 'pg';
import bcrypt from 'bcrypt';
import crypto from 'crypto';

const { Pool } = pg;

const DATABASE_URL = process.env.DATABASE_URL;
const ADMIN_EMAIL = process.env.ADMIN_EMAIL;
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD;

if (!DATABASE_URL || !ADMIN_EMAIL || !ADMIN_PASSWORD) {
  console.error('❌ Required environment variables: DATABASE_URL, ADMIN_EMAIL, ADMIN_PASSWORD');
  process.exit(1);
}
const BCRYPT_ROUNDS = 10;

const pool = new Pool({
  connectionString: DATABASE_URL,
  ssl: { rejectUnauthorized: false }
});

async function seedAdmin() {
  const client = await pool.connect();
  
  try {
    console.log('🔌 Connected to database');
    
    // Check if SUPER_ADMIN already exists
    const existingAdmin = await client.query(
      "SELECT id, email FROM users WHERE role = 'SUPER_ADMIN' LIMIT 1"
    );
    
    if (existingAdmin.rows.length > 0) {
      console.log(`⚠️ SUPER_ADMIN already exists: ${existingAdmin.rows[0].email}`);
      console.log('Skipping seed.');
      return;
    }

    // Generate user ID
    const userId = `u_${crypto.randomUUID().split('-')[0]}`;
    
    // Hash password
    console.log('🔐 Hashing password...');
    const passwordHash = await bcrypt.hash(ADMIN_PASSWORD, BCRYPT_ROUNDS);
    
    // Insert admin user
    console.log('👤 Creating SUPER_ADMIN user...');
    await client.query(`
      INSERT INTO users (id, name, email, password_hash, role, is_active, must_change_password, created_at)
      VALUES ($1, $2, $3, $4, 'SUPER_ADMIN', true, true, NOW())
    `, [userId, 'مدير النظام', ADMIN_EMAIL, passwordHash]);
    
    // Log the action
    const logId = `log_${crypto.randomUUID().split('-')[0]}`;
    await client.query(`
      INSERT INTO audit_logs (id, actor_user_id, action, entity_type, entity_id, after, created_at)
      VALUES ($1, $2, 'ADMIN_SEEDED', 'USER', $3, $4, NOW())
    `, [logId, userId, userId, JSON.stringify({ email: ADMIN_EMAIL, role: 'SUPER_ADMIN' })]);
    
    console.log('\n✅ SUPER_ADMIN created successfully!');
    console.log(`📧 Email: ${ADMIN_EMAIL}`);
    console.log(`🆔 User ID: ${userId}`);
    console.log('⚠️ User must change password on first login.');
    
  } catch (err) {
    console.error('❌ Seed failed:', err.message);
    throw err;
  } finally {
    client.release();
    await pool.end();
  }
}

seedAdmin().catch(err => {
  console.error(err);
  process.exit(1);
});
