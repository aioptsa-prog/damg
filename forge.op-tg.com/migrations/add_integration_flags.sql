-- Migration: Add Integration Feature Flags
-- Date: 2026-01-04
-- Purpose: Add feature flags for gradual integration rollout

-- Insert integration flags into settings table (if not exists)
INSERT OR IGNORE INTO settings (key, value) VALUES 
  ('integration_auth_bridge', '0'),
  ('integration_survey_from_lead', '0'),
  ('integration_send_from_report', '0'),
  ('integration_unified_lead_view', '0');
