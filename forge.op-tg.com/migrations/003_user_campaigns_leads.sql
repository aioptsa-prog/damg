-- =====================================================
-- User Campaigns & Leads Association
-- Version: 1.1
-- Date: 2025-12-26
-- Description: Links user searches/campaigns to their leads
-- =====================================================

-- =====================================================
-- USER CAMPAIGNS (Search Jobs created by public users)
-- =====================================================

CREATE TABLE IF NOT EXISTS user_campaigns (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    
    -- Campaign Details
    name TEXT NOT NULL,
    description TEXT,
    query TEXT NOT NULL,          -- Search query (e.g., "مطعم")
    city TEXT NOT NULL,           -- City name
    ll TEXT,                      -- Lat,Lng coordinates
    radius_km INTEGER DEFAULT 15,
    category_id INTEGER,
    
    -- Target & Progress
    target_count INTEGER DEFAULT 100,
    result_count INTEGER DEFAULT 0,
    
    -- Status
    status TEXT DEFAULT 'pending' CHECK(status IN ('pending', 'processing', 'completed', 'failed', 'cancelled')),
    
    -- Link to internal job
    internal_job_id INTEGER,
    
    -- Timestamps
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME,
    completed_at DATETIME,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES public_users(id) ON DELETE CASCADE,
    FOREIGN KEY (internal_job_id) REFERENCES internal_jobs(id)
);

CREATE INDEX IF NOT EXISTS idx_user_campaigns_user ON user_campaigns(user_id);
CREATE INDEX IF NOT EXISTS idx_user_campaigns_status ON user_campaigns(status);
CREATE INDEX IF NOT EXISTS idx_user_campaigns_job ON user_campaigns(internal_job_id);

-- =====================================================
-- USER LEADS (Leads owned by public users)
-- =====================================================

CREATE TABLE IF NOT EXISTS user_leads (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    lead_id INTEGER NOT NULL,
    campaign_id INTEGER,
    
    -- User-specific notes
    notes TEXT,
    tags TEXT,  -- JSON array of tags
    
    -- User interaction tracking
    phone_revealed INTEGER DEFAULT 0,
    email_revealed INTEGER DEFAULT 0,
    contacted_at DATETIME,
    contact_status TEXT CHECK(contact_status IN ('new', 'contacted', 'interested', 'not_interested', 'converted', 'lost')),
    
    -- Timestamps
    added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES public_users(id) ON DELETE CASCADE,
    FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
    FOREIGN KEY (campaign_id) REFERENCES user_campaigns(id),
    UNIQUE(user_id, lead_id)  -- Each user can have each lead only once
);

CREATE INDEX IF NOT EXISTS idx_user_leads_user ON user_leads(user_id);
CREATE INDEX IF NOT EXISTS idx_user_leads_lead ON user_leads(lead_id);
CREATE INDEX IF NOT EXISTS idx_user_leads_campaign ON user_leads(campaign_id);
CREATE INDEX IF NOT EXISTS idx_user_leads_status ON user_leads(contact_status);

-- =====================================================
-- END OF MIGRATION
-- =====================================================
