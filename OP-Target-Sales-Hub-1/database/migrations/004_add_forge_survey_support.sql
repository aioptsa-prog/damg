-- Migration: Add Forge Survey Support to Reports Table
-- Date: 2026-01-04
-- Phase: 3 - Survey Generation from Forge Lead
-- Purpose: Extend reports table to support forge-sourced surveys

-- Add columns for forge integration
ALTER TABLE reports ADD COLUMN IF NOT EXISTS source VARCHAR(20) DEFAULT 'local';
ALTER TABLE reports ADD COLUMN IF NOT EXISTS external_lead_id VARCHAR(100);
ALTER TABLE reports ADD COLUMN IF NOT EXISTS external_system VARCHAR(50);
ALTER TABLE reports ADD COLUMN IF NOT EXISTS suggested_message TEXT;
ALTER TABLE reports ADD COLUMN IF NOT EXISTS forge_snapshot JSONB;
ALTER TABLE reports ADD COLUMN IF NOT EXISTS ttl_expires_at TIMESTAMP;

-- Add constraint for source
-- Note: PostgreSQL doesn't support ADD CONSTRAINT IF NOT EXISTS, so we use DO block
DO $$ 
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'chk_reports_source'
    ) THEN
        ALTER TABLE reports ADD CONSTRAINT chk_reports_source 
        CHECK (source IN ('local', 'forge', 'integration'));
    END IF;
END $$;

-- Index for forge lookups
CREATE INDEX IF NOT EXISTS idx_reports_external_lead 
ON reports(external_system, external_lead_id);

-- Index for TTL cleanup
CREATE INDEX IF NOT EXISTS idx_reports_ttl 
ON reports(ttl_expires_at) WHERE ttl_expires_at IS NOT NULL;

-- Index for source filtering
CREATE INDEX IF NOT EXISTS idx_reports_source 
ON reports(source);

-- Comments
COMMENT ON COLUMN reports.source IS 'Report source: local (OP-Target lead), forge (forge lead), integration';
COMMENT ON COLUMN reports.external_lead_id IS 'Lead ID in external system (forge)';
COMMENT ON COLUMN reports.external_system IS 'External system name (forge)';
COMMENT ON COLUMN reports.suggested_message IS 'AI-generated suggested WhatsApp message';
COMMENT ON COLUMN reports.forge_snapshot IS 'Cached forge lead data at report generation time';
COMMENT ON COLUMN reports.ttl_expires_at IS 'When this report expires for idempotency (null = never)';
