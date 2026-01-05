# Architecture

```
Browser(Admin/Agent) ──HTTPS──> Apache+PHP (mod_rewrite/.htaccess)
      │                          ├─ Admin UI (/admin/*) [Session+CSRF]
      │                          ├─ Public API (/api/health.php)
      │                          └─ Worker API (/api/*) [HMAC+Replay]
      │
      └────────────────────────────── Leads Vault (views/export)

Jobs Orchestration
Admin/Agent Fetch → orchestrate_fetch (providers/grid) → create internal_jobs
Internal Workers → /api/pull_job.php → execute → /api/report_results.php
Report pipeline → normalize → fingerprint/dedup/idempotency → leads(+indexes)

Storage (SQLite)
- leads, assignments, leads_fingerprints, idempotency_keys, hmac_replay,
  internal_jobs, internal_workers, dead_letter_jobs, rate_limit, settings
```

Security Layers
- HTTPS/HSTS, CSP nonce (Phase-1), CSRF (UI), RateLimit, HMAC+Replay, deny /tools

Evidence
- `.htaccess` (deny tools, maintenance)
- `admin/dashboard.php` (wrappers)
- `lib/providers.php` (grid/exhaustive)
- `api/pull_job.php`, `api/report_results.php` (HMAC + idempotency)
