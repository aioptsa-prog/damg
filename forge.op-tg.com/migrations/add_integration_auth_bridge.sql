-- Migration: Add Integration Auth Bridge Support
-- Date: 2026-01-04
-- Phase: 1 - Auth Bridge

-- Add integration_shared_secret to settings (admin-only access)
INSERT OR IGNORE INTO settings (key, value) VALUES 
  ('integration_shared_secret', '');

-- Create integration_nonces table for replay attack prevention
CREATE TABLE IF NOT EXISTS integration_nonces (
    nonce TEXT PRIMARY KEY,
    issuer TEXT NOT NULL,
    sub TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    expires_at TEXT NOT NULL
);

-- Index for cleanup of expired nonces
CREATE INDEX IF NOT EXISTS idx_integration_nonces_expires 
ON integration_nonces(expires_at);

-- Create integration_sessions table for integration tokens
CREATE TABLE IF NOT EXISTS integration_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    token TEXT UNIQUE NOT NULL,
    op_target_user_id TEXT NOT NULL,
    forge_role TEXT NOT NULL DEFAULT 'agent',
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    expires_at TEXT NOT NULL,
    last_used_at TEXT,
    metadata TEXT
);

-- Index for token lookup
CREATE INDEX IF NOT EXISTS idx_integration_sessions_token 
ON integration_sessions(token);

-- Index for cleanup of expired sessions
CREATE INDEX IF NOT EXISTS idx_integration_sessions_expires 
ON integration_sessions(expires_at);
