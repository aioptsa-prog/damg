# Production Readiness Report — Workers & Ingestion (2025-10-12)

This report summarizes the current state, gaps, and go/no-go criteria for running multiple Windows workers against the internal server.

## Summary
- Ready for a managed pilot (≤ 5 workers) with current SQLite backend and monitoring.
- Not yet ready for broad rollout until key gaps are closed: end-to-end update pipeline (admin UI + staged rollout + rollback), per-worker secrets, circuit breaker + DLQ, basic alerting.

## Current Capabilities
- Atomic job claim with leases and attempt_id; backoff + jitter + next_retry_at on failures; idempotency in report_results.
- Ingestion metrics: added/duplicates, 24h dup ratio, and 7-day trend, visible in Admin Monitor.
- Worker package: self-contained installer/portable (bundled Node + Playwright Chromium + node_modules); local dashboard at 127.0.0.1:4499.
- Central config API: /api/worker_config.php with base_url, intervals, headless, chrome exe/args, and admin remote commands.

## Gaps to close before large-scale rollout
1) Updates pipeline (Admin UI + staged rollout + rollback + signed artifacts + smoke test sandbox)
2) Security: per-worker secret + rotation + rate limiting per worker; audit logs for admin actions
3) Reliability: circuit breaker (per worker/provider), dead-letter queue + UI, lease renewal for long tasks
4) Monitoring/Alerting: heartbeat failure, errors/retries surge, DLQ growth, stuck jobs; daily digest
5) Data hygiene: retention policy, PII masking in logs; optional cross-day dedup policy
6) Scale plan: thresholds to trigger PostgreSQL migration; controlled worker pull intervals and quotas

## Pilot Go/No-Go
- Go for pilot when: per-worker secrets enabled for pilot cohort; canary update path validated on one worker with health + rollback; basic alerting active.
- No-Go for broad rollout until items 1–4 above are implemented and tested.

## Next Steps
- Build Admin: Worker Updates page (upload → self-test → select cohort/channel → canary → monitor → rollout → rollback).
- Implement per-worker secrets and minimal rotation with grace window; add audit logs for uploads/rollouts.
- Implement circuit breaker + DLQ with small UI in admin.
- Wire heartbeat/error alerting (email/Teams/Slack) with simple thresholds.
