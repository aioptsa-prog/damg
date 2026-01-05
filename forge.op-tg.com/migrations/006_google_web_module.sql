-- Migration: Google Web Module
-- Phase 7: Google Web Search with SerpAPI + Chromium Fallback
-- Date: 2026-01-05

-- ============================================
-- Table: google_web_cache
-- Caches SerpAPI/Chromium results for 24 hours
-- ============================================
CREATE TABLE IF NOT EXISTS google_web_cache (
    id TEXT PRIMARY KEY,
    query_hash TEXT NOT NULL UNIQUE,
    query TEXT NOT NULL,
    provider TEXT NOT NULL DEFAULT 'serpapi',
    results_json TEXT NOT NULL DEFAULT '[]',
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    expires_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_google_web_cache_hash ON google_web_cache(query_hash);
CREATE INDEX IF NOT EXISTS idx_google_web_cache_expires ON google_web_cache(expires_at);

-- ============================================
-- Table: google_web_usage
-- Tracks daily usage for rate limiting
-- ============================================
CREATE TABLE IF NOT EXISTS google_web_usage (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    date TEXT NOT NULL,
    provider TEXT NOT NULL,
    count INTEGER NOT NULL DEFAULT 0,
    UNIQUE(date, provider)
);

CREATE INDEX IF NOT EXISTS idx_google_web_usage_date ON google_web_usage(date);

-- ============================================
-- Settings for Google Web module
-- ============================================
-- SERPAPI_KEY stored in env, not DB for security
INSERT OR IGNORE INTO settings (key, value) VALUES ('google_web_enabled', '1');
INSERT OR IGNORE INTO settings (key, value) VALUES ('google_web_fallback_enabled', '0');
INSERT OR IGNORE INTO settings (key, value) VALUES ('google_web_max_per_day', '100');
INSERT OR IGNORE INTO settings (key, value) VALUES ('google_web_fallback_max_per_day', '10');
INSERT OR IGNORE INTO settings (key, value) VALUES ('google_web_cache_hours', '24');
INSERT OR IGNORE INTO settings (key, value) VALUES ('google_web_max_results', '10');

-- ============================================
-- Update allowed modules list
-- ============================================
INSERT OR IGNORE INTO settings (key, value) VALUES ('integration_allowed_modules', 'maps,website,google_web');
