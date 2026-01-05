# Google Places Pipeline (Lean Mode)

This pipeline pulls basic business listings from Google Places API and stores them in the `places` table. It is designed to be conservative (Arabic-first), page-by-page, and idempotent. No UI or endpoint URLs changed.

Key points:
- Inputs: `keywords[]` or `types[]`, `center {lat,lng}`, `radius_km`, `language`, `region`, `max_results`, `rps`, `page_delay_ms`.
- Behavior: queries TextSearch or NearbySearch; per-page DB transaction; upsert by `place_id`; dedup fallback by name prefix if `place_id` is missing; attaches `batch_id` to all inserted/updated rows.
- Defaults: language `ar`, region `SA`, rps `5`, page delay `250ms`, radius `1km`, max_results `50`.
- Rollback: delete by `batch_id`.

## Schema
- `db/migrations/20251002_places_pipeline.php` creates table `places` and adds `internal_jobs.job_type` and `internal_jobs.payload_json` columns.

## Running

Option A — enqueue a sample job:
- tools/ops/enqueue_sample.php --type places_api_search --payload '{"keywords":["مطاعم"],"center":{"lat":24.7136,"lng":46.6753},"radius_km":1,"max_results":30}'
- Worker will pull and run as usual; results land in `places` and leads are populated via normal flow if applicable.

Option B — run via CLI directly (no worker):
- tools/ops/run_places_job.php --payload '{"keywords":["مطاعم"],"center":{"lat":24.7136,"lng":46.6753},"radius_km":1,"max_results":30}'
- Or against an existing job id (created earlier): tools/ops/run_places_job.php --job 123

Both commands output a JSON block with stats including `batch_id`.

## Rollback
- tools/ops/rollback_batch.php --batch <batch_id> --dry-run  # preview rows
- tools/ops/rollback_batch.php --batch <batch_id> -y          # delete rows with that batch_id

## Notes
- This is a lean integration that only uses TextSearch/NearbySearch; it does not call Place Details. Phone/website are not filled unless present in the search payloads.
- The handler enforces short sleeps and retries on transient API errors; tune via settings: `GOOGLE_RATE_LIMIT_RPS`, `GOOGLE_PAGE_DELAY_MS`, `GOOGLE_LANGUAGE`, `GOOGLE_REGION`, `GOOGLE_RADIUS_KM_DEFAULT`.
- The migration and inserts are idempotent; re-running with the same inputs mostly updates `last_seen_at` and maintains dedup.
