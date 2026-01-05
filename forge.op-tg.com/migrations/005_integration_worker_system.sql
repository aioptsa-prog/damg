-- Migration: Integration Worker System
-- Phase 6: Modular Worker Enrichment
-- Date: 2026-01-05

-- ============================================
-- Table: integration_jobs
-- Tracks enrichment jobs requested from OP-Target
-- ============================================
CREATE TABLE IF NOT EXISTS integration_jobs (
    id TEXT PRIMARY KEY,
    forge_lead_id INTEGER NOT NULL,
    op_lead_id TEXT NOT NULL,
    requested_by TEXT NOT NULL,
    modules_json TEXT NOT NULL DEFAULT '[]',
    options_json TEXT DEFAULT '{}',
    status TEXT NOT NULL DEFAULT 'queued' CHECK(status IN ('queued','running','success','partial','failed','cancelled')),
    progress INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    started_at TEXT,
    finished_at TEXT,
    last_error TEXT,
    correlation_id TEXT
);

CREATE INDEX IF NOT EXISTS idx_integration_jobs_status ON integration_jobs(status);
CREATE INDEX IF NOT EXISTS idx_integration_jobs_forge_lead ON integration_jobs(forge_lead_id);
CREATE INDEX IF NOT EXISTS idx_integration_jobs_op_lead ON integration_jobs(op_lead_id);
CREATE INDEX IF NOT EXISTS idx_integration_jobs_created ON integration_jobs(created_at);
CREATE INDEX IF NOT EXISTS idx_integration_jobs_requested_by ON integration_jobs(requested_by);

-- ============================================
-- Table: integration_job_runs
-- Tracks individual module runs within a job
-- ============================================
CREATE TABLE IF NOT EXISTS integration_job_runs (
    id TEXT PRIMARY KEY,
    job_id TEXT NOT NULL,
    module TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','running','success','failed','skipped')),
    attempt INTEGER NOT NULL DEFAULT 0,
    started_at TEXT,
    finished_at TEXT,
    error_code TEXT,
    error_message TEXT,
    output_json TEXT,
    FOREIGN KEY (job_id) REFERENCES integration_jobs(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_integration_job_runs_job ON integration_job_runs(job_id);
CREATE INDEX IF NOT EXISTS idx_integration_job_runs_status ON integration_job_runs(status);
CREATE INDEX IF NOT EXISTS idx_integration_job_runs_module ON integration_job_runs(module);

-- ============================================
-- Table: lead_snapshots
-- Stores merged enrichment data for leads
-- ============================================
CREATE TABLE IF NOT EXISTS lead_snapshots (
    id TEXT PRIMARY KEY,
    forge_lead_id INTEGER NOT NULL,
    job_id TEXT,
    source TEXT NOT NULL DEFAULT 'worker',
    snapshot_json TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (job_id) REFERENCES integration_jobs(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_lead_snapshots_forge_lead ON lead_snapshots(forge_lead_id);
CREATE INDEX IF NOT EXISTS idx_lead_snapshots_created ON lead_snapshots(created_at);
CREATE INDEX IF NOT EXISTS idx_lead_snapshots_job ON lead_snapshots(job_id);

-- ============================================
-- Settings for worker integration
-- ============================================
INSERT OR IGNORE INTO settings (key, value) VALUES ('integration_worker_enabled', '0');
INSERT OR IGNORE INTO settings (key, value) VALUES ('integration_worker_concurrency', '1');
INSERT OR IGNORE INTO settings (key, value) VALUES ('integration_worker_max_jobs_per_user_day', '20');
INSERT OR IGNORE INTO settings (key, value) VALUES ('integration_instagram_enabled', '0');

-- ============================================
-- Cleanup: Delete old data (30 days retention)
-- Run this periodically via cron or manual cleanup
-- ============================================
-- DELETE FROM integration_job_runs WHERE finished_at < datetime('now', '-30 days');
-- DELETE FROM integration_jobs WHERE finished_at < datetime('now', '-30 days');
-- DELETE FROM lead_snapshots WHERE id NOT IN (
--   SELECT id FROM (
--     SELECT id, ROW_NUMBER() OVER (PARTITION BY forge_lead_id ORDER BY created_at DESC) as rn
--     FROM lead_snapshots
--   ) WHERE rn <= 3
-- ) AND created_at < datetime('now', '-30 days');
