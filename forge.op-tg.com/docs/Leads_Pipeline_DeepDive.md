# Leads Pipeline Deep Dive

## Flow
Fetch UI → orchestrate_fetch → providers paging/grid → results → normalize → fingerprint/dedup/idempotency → insert/update → Leads Vault.

## Normalization
- Phone to E.164; trim names; geo precision to 6 decimals.

## Dedup/Idempotency
- Merge by phone + fuzzy name + geo proximity; idempotency_keys to avoid duplicates; hmac_replay to block replays.

## Indexes (SQL)
```sql
CREATE INDEX IF NOT EXISTS idx_leads_phone ON leads(phone);
CREATE INDEX IF NOT EXISTS idx_jobs_status_prio ON internal_jobs(status, priority DESC);
CREATE INDEX IF NOT EXISTS idx_idem_key ON idempotency_keys(key);
```

## Edge Cases
- No-phone results (preview-only), provider paging throttling, dense areas grid step tuning.

Evidence: `lib/providers.php`, `api/report_results.php`.
