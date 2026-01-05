#!/usr/bin/env node
/**
 * Production Bootstrap Script
 * Creates initial SUPER_ADMIN user directly in database
 * 
 * Usage:
 *   Set environment variables:
 *     DATABASE_URL_UNPOOLED - Neon direct connection string
 *     ADMIN_EMAIL - Admin email (default: admin@optarget.sa)
 *     ADMIN_PASSWORD - Admin password (required)
 * 
 *   Run:
 *     node scripts/bootstrap-admin.js
 * 
 * This script is idempotent - safe to run multiple times.
 */

import pg from 'pg';
import bcrypt from 'bcrypt';
import crypto from 'crypto';

const { Pool } = pg;

const BCRYPT_ROUNDS = 10;

async function bootstrap() {
  // Validate environment - prefer UNPOOLED but accept regular DATABASE_URL
  const databaseUrl = process.env.DATABASE_URL_UNPOOLED || process.env.DATABASE_URL;
  const adminEmail = process.env.ADMIN_EMAIL || 'admin@optarget.sa';
  const adminPassword = process.env.ADMIN_PASSWORD;

  if (!databaseUrl) {
    console.error('❌ DATABASE_URL_UNPOOLED or DATABASE_URL is required');
    process.exit(1);
  }

  if (!adminPassword) {
    console.error('❌ ADMIN_PASSWORD is required');
    process.exit(1);
  }

  console.log('🔄 Connecting to database...');
  
  const pool = new Pool({
    connectionString: databaseUrl,
    ssl: { rejectUnauthorized: false }
  });

  try {
    // Check if SUPER_ADMIN exists
    const existing = await pool.query(
      "SELECT id, email FROM users WHERE role = 'SUPER_ADMIN' LIMIT 1"
    );

    if (existing.rows.length > 0) {
      console.log(`✅ SUPER_ADMIN already exists: ${existing.rows[0].email}`);
      console.log('   No action needed.');
      return;
    }

    // Hash password
    console.log('🔐 Hashing password...');
    const passwordHash = await bcrypt.hash(adminPassword, BCRYPT_ROUNDS);

    // Create admin
    const adminId = crypto.randomUUID();
    console.log(`📝 Creating admin: ${adminEmail}`);
    
    await pool.query(
      `INSERT INTO users (id, name, email, password_hash, role, is_active, must_change_password, created_at) 
       VALUES ($1, $2, $3, $4, $5, $6, $7, NOW())`,
      [adminId, 'مسؤول النظام', adminEmail, passwordHash, 'SUPER_ADMIN', true, true]
    );

    // Log audit
    await pool.query(
      'INSERT INTO audit_logs (id, actor_user_id, action, entity_type, entity_id, created_at) VALUES ($1, $2, $3, $4, $5, NOW())',
      [crypto.randomUUID(), 'bootstrap-script', 'ADMIN_CREATED', 'USER', adminId]
    );

    console.log('✅ Admin created successfully!');
    console.log(`   Email: ${adminEmail}`);
    console.log('   Note: User must change password on first login.');

  } catch (error) {
    console.error('❌ Bootstrap failed:', error.message);
    process.exit(1);
  } finally {
    await pool.end();
  }
}

bootstrap();
