# Database ERD and Access Map

Source of truth: `config/db.php` migrations.

Key tables:
- users(id, mobile, name, role, password_hash, active, username?, is_superadmin?)
- sessions(id, user_id, token_hash, expires_at, created_at)
- leads(id, phone, phone_norm?, name, city, country, created_at, created_by_user_id, rating?, website?, email?, gmap_types?, source_url?, social?, category_id?, lat?, lon?, geo_*?)
- assignments(id, lead_id UNIQUE, agent_id, status, assigned_at)
- settings(key PRIMARY KEY, value)
- washeej_logs(...)
- place_cache(...)
- search_tiles(...)
- usage_counters(day, kind, count)
- internal_jobs(... many columns incl. status, attempts, lease_expires_at, next_retry_at, attempt_id)
- internal_workers(worker_id UNIQUE, last_seen, info JSON, host?, version?, status?, active_job_id?, secret?, rotating_to?, rotated_at?, rate_limit_per_min?)
- job_attempts(job_id, worker_id, started_at, finished_at, success, log_excerpt)
- idempotency_keys(job_id, ikey)
- leads_fingerprints(lead_id, fingerprint)
- dead_letter_jobs(job_id, worker_id, reason, payload)
- auth_attempts, rate_limit, audit_logs, alert_events
- hmac_replay(worker_id, ts, body_sha, method, path)

Indexes (representative):
- idx_internal_jobs_status_lease(status, lease_expires_at)
- idx_internal_jobs_status_retry(status, next_retry_at)
- idx_internal_jobs_queue(status, queued_at, priority)
- idx_internal_jobs_worker_updated(worker_id, updated_at)
- idx_internal_workers_last_seen(last_seen)
- idx_leads_phone_norm(phone_norm)
- idx_leads_geo_city/geo_district/geo_region
- idx_job_attempts_job(job_id)
- idx_dlq_created(created_at)
- idx_rate_limit_window(window_start)
- idx_alert_events_kind_time(kind, created_at)

Hot read/write paths:
- pull_job/report_results: internal_jobs, internal_workers, job_attempts, idempotency_keys, dead_letter_jobs
- heartbeat/worker_metrics: internal_workers, internal_jobs (lease renew)
- ingest: leads (+ assignments), leads_fingerprints

Index proposals:
- internal_jobs(status, priority DESC, queued_at) already covered by idx_internal_jobs_queue; consider adding (status, priority, next_retry_at) if retries become hot.
- idempotency_keys(job_id, ikey) UNIQUE exists; OK.
- leads(phone_norm) index exists; consider composite (phone_norm, city) if queries use both.
- internal_workers(worker_id) is UNIQUE via table schema; add explicit index if needed for foreign joins (not critical).

Retention guidance (draft):
- hmac_replay: keep 3-7 days; purge by ts.
- job_attempts: keep 30 days; summarize to usage_counters.
- dead_letter_jobs: keep 90 days; export and purge older.
- rate_limit/auth_attempts: keep 7-14 days.
- logs in storage/logs: rotate weekly, keep 4 weeks.
