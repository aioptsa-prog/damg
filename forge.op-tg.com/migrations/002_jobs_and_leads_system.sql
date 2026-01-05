-- =====================================================
-- Jobs System for Google Maps Scraping
-- =====================================================

CREATE TABLE IF NOT EXISTS jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    query TEXT NOT NULL,
    location TEXT NOT NULL,  -- City or coordinates
    radius_km INTEGER DEFAULT 10,
    category_id INTEGER,
    
    status TEXT DEFAULT 'pending' CHECK(status IN ('pending', 'running', 'completed', 'failed')),
    progress INTEGER DEFAULT 0,  -- 0-100
    
    target_count INTEGER DEFAULT 100,
    found_count INTEGER DEFAULT 0,
    saved_count INTEGER DEFAULT 0,
    
    started_at DATETIME,
    completed_at DATETIME,
    error_message TEXT,
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES public_users(id),
    FOREIGN KEY (category_id) REFERENCES categories(id)
);

CREATE INDEX IF NOT EXISTS idx_jobs_status ON jobs(status);
CREATE INDEX IF NOT EXISTS idx_jobs_user ON jobs(user_id);
CREATE INDEX IF NOT EXISTS idx_jobs_created ON jobs(created_at);

-- =====================================================
-- Leads columns addition (if table already exists)
-- =====================================================

-- Add job_id if it doesn't exist
-- SQLite doesn't have IF NOT EXISTS for columns, so this will fail silently if it exists

-- Note: We'll handle this in PHP instead to be idempotent
