# Nexus Deep Technical & Operational Audit (2025-Q4)

Status: 🟡 CONDITIONAL — requires hardening tasks in 48h for global rollout.

## 1) System Baseline & Architecture Validation

### Data flows (validated)
- Admin/Agent UI → `lib/providers.php` orchestrates grid/pagination (Evidence: lib/providers.php)
- Workers → `/api/pull_job.php` (lease/backoff) → execute → `/api/report_results.php` (idempotent + replay guard) (Evidence: api/pull_job.php, api/report_results.php)
- Leads Vault shows normalized leads, exports via `/api/export_*` (Evidence: api/export_leads*.php)

### SQLite posture
- Strengths: simple deploy, zero-admin, adequate for medium throughput.
- Constraints: write contention on hot tables (`internal_jobs`, `leads`) under burst ingestion.
- Pragmas (recommended):
```sql
PRAGMA journal_mode=WAL; PRAGMA synchronous=NORMAL; PRAGMA foreign_keys=ON;
```
- Hot Paths & indexes: see ERD.md (leads(phone), internal_jobs(status,priority DESC), hmac_replay(signature,created_at)).

### Hosting constraints
- Shared cPanel; no SSH. All ops via `/admin/*` wrappers; `.htaccess` blocks `/tools/*` (Evidence: .htaccess).
- CSP nonces enabled; Phase-2 pending removal of `unsafe-inline`.

### Multi-tenant readiness
- Current schema is single-tenant. Readiness: 🟡 CONDITIONAL.
- Minimal changes path: add `tenant_id` (INT/TEXT) to core tables (`leads`, `assignments`, `internal_jobs`, `users`) + enforce scoping in queries.

### Race conditions & blocking
- Potential race: concurrent `report_results` inserting the same phone; mitigated by unique/UPSERT + idempotency_keys.
- Job leasing: ensure atomic lease update (WHERE status='queued' AND lease_expires_at<=now) to avoid double-lease.

Decision: 🟡 CONDITIONAL — acceptable with WAL + indexes + leasing guard checks.

---

## 2) Code Audit & Dependency Review

### Modules
- `lib/` (providers, system, auth, db, leads): core logic; generally cohesive; recommend extracting `leads_normalize.php`, `dedup.php` for clarity.
- `api/`: thin controllers; good separation; ensure consistent CSRF where session endpoints exist.
- `admin/`: views + JS; migrate all inline JS to external (`assets/js/ui.js`) with CSP nonces.
- `tools/`: ops & release scripts; access via admin wrappers only.

### Clean Architecture
- Controllers thin → Services in `lib/*` → Persistence via PDO. Acceptable for current scale.
- Recommendations: introduce `Lib\Jobs\Queue` façade; `Lib\Leads\Ingest` service boundary.

### Dependencies
- PHP 8.2 (per .htaccess handler), extensions: PDO_SQLITE, Zip, mbstring, json, curl. JS: Leaflet, DataTables, SwaggerUI.

Actionable Refactors (prio)
- (High) Extract `LeadsNormalizer`, `Fingerprint` utilities into `lib/leads/`.
- (Medium) Consolidate provider pagination patterns.
- (Low) Convert repeated SQL strings to prepared statement helpers.

Decision: 🟢 GO with targeted refactors (non-breaking).

---

## 3) Operational & Security Posture

### Ops tools (browser)
- Preflight, Validate, Synthetic, Retention, Cleanup — accessible via `/admin/*` (Evidence: admin/dashboard.php + wrappers).
- Add Backup UI (zip app + DB snapshot) — optional.

### Security
- HMAC + Replay Guard: active (Evidence: api/heartbeat.php, api/report_results.php).
- CSRF: UI protected.
- CSP: nonce-based; Phase-2 pending (remove `unsafe-inline`).
- Rate limiting: present.
- HTTPS: enforce + recommend HSTS.

Snippets (≤12 lines)
- HSTS:
```
<IfModule mod_headers.c>
  Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains; preload"
</IfModule>
```
- ENV Secret:
```php
$sec = getenv('NEXUS_INTERNAL_SECRET') ?: '';
if(!defined('INTERNAL_SECRET')) define('INTERNAL_SECRET',$sec);
```
- Per-worker secret enforce:
```php
if(get_setting('per_worker_secret_required','0')==='1'){
  $wid=$_SERVER['HTTP_X_WORKER_ID']??''; $sec=$_SERVER['HTTP_X_WORKER_SECRET']??'';
  if(!auth_worker_secret_ok($wid,$sec)) json_exit(401,['error'=>'unauthorized']);
}
```

Decision: 🟡 CONDITIONAL — close items in 48h.

---

## 4) Performance & Scalability Assessment

### Throughput (estimates)
- Ingestion: stable at medium rates; exhaustive grid + provider paging improve yield.
- Duplicates: controlled via idempotency_keys + phone fingerprinting.
- Response times: Admin p95 < 800ms feasible after OPcache reset and CSP fixes.

### Bottlenecks
- SQLite write lock on bursts; mitigate with WAL, batch inserts, and lowering transaction spans.
- Job queue under 1K concurrent workers: HOLD — shared hosting limits.

### Recommendations
- Apply WAL + indexes; measure p95 via synthetic logs.
- Consider hybrid: keep SQLite for config, move hot tables to Postgres when size > 2–5GB.

Decision: 🟡 CONDITIONAL.

---

## 5) Leads Engine & Data Quality

### Algorithms
- Normalize E.164; dedup by phone + fuzzy name + geo proximity; idempotency for reports.
- Classification: rules/keywords based; can be re-trained on samples.

### Quality
- Preview-only items without phone kept separate; later enrichment adds phones.

### Checks
- Add unit tests for normalization and fingerprinting; ensure indexes support frequent filters.

Decision: 🟢 GO after adding tests.

---

## 6) Strategic Roadmap (2025-Q1)

### Hardening (0–2 أسابيع)
- ENV secrets + rotation; per-worker-secret; HSTS; CSP Phase-2.
- Backup/Restore + Retention أوتوماتيكي.
- Synthetic + Webhook Alerts + Uptime external.
- Indexes (leads, jobs, replay, rate_limit). WAL + PRAGMAs.

### Scaling (4–6 أسابيع)
- Scheduler UI للوظائف الداخلية (مناطق/كلمات + cadence).
- Enrichment pipeline للـ preview-only.
- Analytics dashboard + external webhooks.
- Worker Insights dashboard (SSE + charts).
- دراسة ترحيل Postgres تدريجيًا (Shadow writes ثم primary).

### New Modules
- Multi-tenant + billing/quotas (tenant_id، scoping، خطط أسعار).
- WhatsApp CRM integration (رسائل، قوالب، opt-in).
- Auto-retrain classification (labeling + periodic retrain).

Final Verdict: 🟡 CONDITIONAL GO — شريطة إغلاق بنود الـ 48 ساعة، ثم توسيع تدريجي مع مراقبة لصيقة لأسبوع.
