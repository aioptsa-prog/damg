# Jobs Lifecycle — Lease, Progress, Backoff, Attempts

This document explains how internal jobs move through states, how leases work, and how retries/backoff are applied.

## States

- queued: Ready to be claimed by a worker
- processing: Claimed by a worker; visibility controlled by a lease (lease_expires_at)
- done: Completed successfully (done_reason worker_done/target_reached/no_more_results)
- failed: Terminal failure after exhausting attempts

Key columns:
- attempts: number of claim attempts (increments on claim)
- lease_expires_at: visibility timeout; if expired, job becomes claimable again
- last_cursor, progress_count, result_count, last_progress_at: progress telemetry
- next_retry_at: not before time for re-claim after failure
- last_error: last error message when failure path is used
- max_attempts: cap per job (defaults to MAX_ATTEMPTS_DEFAULT)

## Lease and visibility timeout

- When a worker pulls a job, the server sets `status='processing'`, increments `attempts`, and sets `lease_expires_at = now + lease_sec`.
- While the lease is active, other workers avoid the job.
- If the worker stops reporting or the lease expires, the job becomes eligible again.
- Workers extend the lease by reporting progress (`extend_lease_sec`).

## Progress reporting

Workers should send periodic reports to `/api/report_results.php`:
- Payload: `{ job_id, items, cursor, done, extend_lease_sec }`
- The server:
  - Upserts lead items (ignoring duplicates)
  - Updates `progress_count`, `result_count`, `last_cursor`, `last_progress_at`
  - Extends the lease (`lease_expires_at`)
  - When `done=true` or `target_count` reached, marks job `done` and clears lease

Best practices:
- Report at a steady cadence (e.g., every 10–15s or N items)
- Include `cursor` to help resume gracefully if reclaimed
- Avoid sending duplicate phones (client already deduplicates)

## Failure and backoff

When a worker cannot proceed, it may POST an error instead of `done`:
- POST body includes: `error` (string) and optional `log` (text excerpt)
- The server computes backoff:
  - Base: BACKOFF_BASE_SEC (default 30s)
  - Exponential: `delay = min(BACKOFF_MAX_SEC, base * 2^attempts) + jitter`
  - Jitter: up to 20% of delay (random)
- If the next attempt would exceed `max_attempts`, the job is marked `failed`; otherwise it is re-queued with `next_retry_at`.

Example (base=30, cap=3600):
- attempts=0 → 30s + jitter
- attempts=1 → 60s + jitter
- attempts=2 → 120s + jitter
- attempts=3 → 240s + jitter
- attempts=4 → 480s + jitter
- attempts>=7 → capped at 3600s + jitter

## Attempts log

The `job_attempts` table records each attempt outcome:
- Columns: id, job_id, worker_id, started_at, finished_at, success (0/1), log_excerpt
- Success rows inserted when `done`/`target_reached` is set
- Failure rows inserted when `error` is posted

Use this for debugging and performance tracking.

## ASCII flow

```
Worker                           Server
  |                                |
  | GET /api/pull_job (lease_sec)  |  select queued or expired lease
  |------------------------------->|  set processing, attempts++, lease_expires_at
  |                                |
  |   …scrape…                     |
  |                                |
  | POST /api/report_results       |  upsert items, update progress, extend lease
  | (items/cursor/extend_lease)    |<-------------------------------------------
  |                                |
  | POST /api/report_results done  |  mark done (finished_at, done_reason)
  |------------------------------->|
  |                                |
  | POST /api/report_results error |  compute backoff, set next_retry_at or fail
  | (error/log)                    |<-------------------------------------------
  |                                |
  | GET /api/pull_job              |  when next_retry_at <= now or lease expired → claim again
  |------------------------------->|
```

## Tuning knobs

- LEASE_SEC_DEFAULT: default lease when pulling
- BACKOFF_BASE_SEC: exponential backoff base (seconds)
- BACKOFF_MAX_SEC: cap on backoff (seconds)
- MAX_ATTEMPTS_DEFAULT: cap if job.max_attempts is null

These are available in the settings table and enforced by the API.
