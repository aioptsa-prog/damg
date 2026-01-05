-- Migration: 000_create_schema.sql
-- Date: 2026-01-03
-- Purpose: Create initial database schema for OP Target Sales Hub

-- ============================================
-- Teams Table
-- ============================================
CREATE TABLE IF NOT EXISTS teams (
    id VARCHAR(50) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    manager_user_id VARCHAR(50)
);

-- ============================================
-- Users Table
-- ============================================
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
);

-- Add foreign key for teams.manager_user_id after users table exists
ALTER TABLE teams ADD CONSTRAINT fk_teams_manager 
    FOREIGN KEY (manager_user_id) REFERENCES users(id) ON DELETE SET NULL;

-- ============================================
-- Leads Table
-- ============================================
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
    enrichment_signals JSONB
);

-- ============================================
-- Reports Table
-- ============================================
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
);

-- ============================================
-- Tasks Table
-- ============================================
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
);

-- ============================================
-- Activities Table
-- ============================================
CREATE TABLE IF NOT EXISTS activities (
    id VARCHAR(50) PRIMARY KEY,
    lead_id VARCHAR(50) REFERENCES leads(id) ON DELETE CASCADE,
    user_id VARCHAR(50) REFERENCES users(id) ON DELETE SET NULL,
    type VARCHAR(50),
    payload JSONB,
    created_at TIMESTAMP DEFAULT NOW()
);

-- ============================================
-- Audit Logs Table
-- ============================================
CREATE TABLE IF NOT EXISTS audit_logs (
    id VARCHAR(50) PRIMARY KEY,
    actor_user_id VARCHAR(50),
    action VARCHAR(100),
    entity_type VARCHAR(50),
    entity_id VARCHAR(100),
    before JSONB,
    after JSONB,
    created_at TIMESTAMP DEFAULT NOW()
);

-- ============================================
-- Usage Logs Table
-- ============================================
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
);

-- ============================================
-- Settings Table
-- ============================================
CREATE TABLE IF NOT EXISTS settings (
    key VARCHAR(100) PRIMARY KEY,
    value JSONB,
    updated_at TIMESTAMP DEFAULT NOW()
);

-- ============================================
-- Indexes for Performance
-- ============================================

-- Users indexes
CREATE INDEX IF NOT EXISTS idx_users_email ON users(email);
CREATE INDEX IF NOT EXISTS idx_users_team_id ON users(team_id);
CREATE INDEX IF NOT EXISTS idx_users_role ON users(role);

-- Leads indexes
CREATE INDEX IF NOT EXISTS idx_leads_owner ON leads(owner_user_id);
CREATE INDEX IF NOT EXISTS idx_leads_team ON leads(team_id);
CREATE INDEX IF NOT EXISTS idx_leads_status ON leads(status);
CREATE INDEX IF NOT EXISTS idx_leads_created ON leads(created_at DESC);

-- Reports indexes
CREATE INDEX IF NOT EXISTS idx_reports_lead ON reports(lead_id);
CREATE INDEX IF NOT EXISTS idx_reports_version ON reports(lead_id, version_number DESC);

-- Activities indexes
CREATE INDEX IF NOT EXISTS idx_activities_lead ON activities(lead_id);
CREATE INDEX IF NOT EXISTS idx_activities_user ON activities(user_id);
CREATE INDEX IF NOT EXISTS idx_activities_created ON activities(created_at DESC);

-- Tasks indexes
CREATE INDEX IF NOT EXISTS idx_tasks_lead ON tasks(lead_id);
CREATE INDEX IF NOT EXISTS idx_tasks_assigned ON tasks(assigned_to_user_id);
CREATE INDEX IF NOT EXISTS idx_tasks_status ON tasks(status);

-- Audit logs indexes
CREATE INDEX IF NOT EXISTS idx_audit_logs_created ON audit_logs(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_audit_logs_actor ON audit_logs(actor_user_id);
CREATE INDEX IF NOT EXISTS idx_audit_logs_entity ON audit_logs(entity_type, entity_id);

-- Usage logs indexes
CREATE INDEX IF NOT EXISTS idx_usage_logs_created ON usage_logs(created_at DESC);
