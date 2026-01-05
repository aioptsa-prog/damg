# Nexus Worker / Washeej — v1.0 Final (Global Build)

Date: 2025-10-19

## Highlights
- Lead pipeline hardened: HMAC + Replay + Idempotency + Dedup with acceptance PASS.
- HTTPS enforced with redirect; per-worker secret required.
- Monitoring improved: synthetic shows redirect target and emits alert on failure.
- Retention safer: prefers finished_at/started_at for job_attempts; retention log added.
- Admin UX: status bar (today counters, job snapshot, workers online). Pagination flag available.

## Changes since RC
- tools/ops/retention_purge.php: safer TTL + retention log
- tools/monitor/synthetic.php: alert_events on failure
- admin/leads.php: status bar chips

## Final readiness
- Preflight: PASS
- Synthetic: Configure HTTPS base URL in production → expect 200/OK
- Validator: GO metrics (workers_online, dlq_last15, ingested_last15)
- Acceptance: PASS across happy path, replay, idempotency, duplicate, UI visibility

## First 48h operational checklist
- Set worker_base_url or SYNTH_BASE_URL to your HTTPS origin.
- Run canary 10% → 50% → 100% with validator and synthetic.
- Enable pagination_enabled=1 for large tables; consider export_max_rows=5000.
- After UI script checks, set csp_phase1_enforced=1 to drop 'unsafe-inline' on admin.
- Ensure all internal_workers have secrets; per_worker_secret_required=1 enforced.
 - Monitor DLQ and synthetic alerts; check retention.log and cleanup.log outputs.
 - Validate acceptance suite over HTTPS once TLS is in place.
