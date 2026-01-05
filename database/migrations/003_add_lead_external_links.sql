-- Migration: Add Lead External Links Table
-- Date: 2026-01-04
-- Phase: 2 - Lead Linking
-- Purpose: Store mappings between OP-Target leads and external system leads (forge)

-- Create lead_external_links table
CREATE TABLE IF NOT EXISTS lead_external_links (
    id VARCHAR(50) PRIMARY KEY DEFAULT gen_random_uuid()::text,
    
    -- OP-Target lead reference
    op_target_lead_id VARCHAR(50) NOT NULL REFERENCES leads(id) ON DELETE CASCADE,
    
    -- External system info
    external_system VARCHAR(50) NOT NULL DEFAULT 'forge',
    external_lead_id VARCHAR(100) NOT NULL,
    
    -- Linking metadata
    linked_by_user_id VARCHAR(50) REFERENCES users(id),
    linked_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    
    -- Cached external data (minimal, for display only)
    external_phone VARCHAR(50),
    external_name VARCHAR(255),
    external_city VARCHAR(100),
    
    -- Status and sync
    link_status VARCHAR(20) DEFAULT 'active',
    last_synced_at TIMESTAMP WITH TIME ZONE,
    sync_error TEXT,
    
    -- Audit
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    
    -- Constraints
    CONSTRAINT uq_op_target_lead_external UNIQUE (op_target_lead_id, external_system),
    CONSTRAINT uq_external_lead UNIQUE (external_system, external_lead_id),
    CONSTRAINT chk_link_status CHECK (link_status IN ('active', 'broken', 'unlinked'))
);

-- Indexes for common queries
CREATE INDEX IF NOT EXISTS idx_lead_external_links_op_target 
ON lead_external_links(op_target_lead_id);

CREATE INDEX IF NOT EXISTS idx_lead_external_links_external 
ON lead_external_links(external_system, external_lead_id);

CREATE INDEX IF NOT EXISTS idx_lead_external_links_phone 
ON lead_external_links(external_phone);

-- Comments
COMMENT ON TABLE lead_external_links IS 'Maps OP-Target leads to external system leads (forge, etc.)';
COMMENT ON COLUMN lead_external_links.external_system IS 'External system identifier (forge, etc.)';
COMMENT ON COLUMN lead_external_links.external_lead_id IS 'Lead ID in the external system';
COMMENT ON COLUMN lead_external_links.link_status IS 'active=valid link, broken=external lead deleted, unlinked=manually removed';
