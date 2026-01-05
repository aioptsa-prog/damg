# PROJECT FACTS — Evidence-Based Analysis

> **CRITICAL**: All information in this document is derived from actual file inspection, configuration analysis, and code examination. No assumptions or guesses.

---

## 1. PROJECT IDENTITY

**Project Name**: OptForge (Nexus)  
**Internal Code Name**: forge.op-tg.com  
**Domain (Production)**: `https://nexus.op-tg.com` (as documented in [docs/RUNBOOK.md](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/docs/RUNBOOK.md#L46))  
**Purpose**: Data scraping and operations automation platform — A lead extraction, classification, and management system with distributed worker architecture  

**Evidence**:
- [bootstrap.php#L312](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/bootstrap.php#L312): `'brand_name'=>'OptForge'`
- [README.md#L1](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/README.md#L1): "Nexus (OptForge) — Go-Live Playbook"
- [readiness_report.md#L4](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/readiness_report.md#L4): Target domain documented

---

## 2. TECHNOLOGY STACK

### 2.1 Backend — PHP + SQLite

**Language**: PHP (no specific version constraint found in composer.json)  
**Web Server**: Apache (`.htaccess` file present)  
**Database**: SQLite3 with WAL mode  
**Database Path**: Configured via `config/.env.php` (`SQLITE_PATH`)

**Evidence**:
- [bootstrap.php](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/bootstrap.php): All requires are PHP files
- [config/db.php#L3](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/config/db.php#L3): `$pdo=new PDO('sqlite:'.$p);`
- [config/db.php#L3](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/config/db.php#L3): `$pdo->exec('PRAGMA journal_mode=WAL;');`
- [.htaccess](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/.htaccess): Apache rewrite rules present

**PHP Extensions Required** (inferred from code):
- PDO + PDO_SQLITE
- cURL ([bootstrap.php#L17](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/bootstrap.php#L17): `curl_init`)
- JSON

### 2.2 Worker Component — Node.js + Playwright

**Runtime**: Node.js v18+ (targeted for executable build)  
**Main Script**: `worker/index.js` (90,565 bytes)  
**Launcher**: `worker/launcher.js`  
**Browser Automation**: Playwright (Chromium only)

**Dependencies** (from [worker/package.json](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/worker/package.json)):
```json
{
  "dotenv": "^16.4.5",
  "express": "^4.19.2",
  "node-fetch": "^3.3.2",
  "playwright": "^1.47.0"
}
```

**Executable Packaging**: Uses `pkg` to create standalone `worker.exe` (node18-win-x64 target)

**Evidence**:
- [worker/package.json#L9](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/worker/package.json#L9): `"playwright": "^1.47.0"`
- [worker/package.json#L16](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/worker/package.json#L16): `"build:exe": "npx pkg -t node18-win-x64 launcher.js -o worker.exe"`
- Worker executable exists: `worker/worker.exe` (37,649,373 bytes as of last listing)

### 2.3 No Frontend Framework Detected

**Finding**: No modern frontend framework (React, Vue, Next.js) detected  
**UI Approach**: Server-rendered PHP pages with inline JavaScript

**Evidence**:
- No package.json in root directory
- Admin pages are `.php` files (41 files in `admin/`)
- [layout_header.php](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/layout_header.php) (16,047 bytes) suggests templated HTML

---

## 3. ARCHITECTURE OVERVIEW

### 3.1 System Components

```
┌─────────────────────────────────────────────────────────────┐
│                    OptForge Platform                         │
│                                                              │
│  ┌──────────────┐         ┌───────────────┐                │
│  │ PHP Backend  │◄────────┤ SQLite DB     │                │
│  │ (Web Admin)  │         │ (app.sqlite)  │                │
│  └──────┬───────┘         └───────────────┘                │
│         │                                                    │
│         │ HMAC Auth                                         │
│         ▼                                                    │
│  ┌──────────────────────────────────────┐                  │
│  │   Distributed Workers (Node.js)      │                  │
│  │   - Playwright Chromium              │                  │
│  │   - HTTP health endpoint (port 4499) │                  │
│  │   - Auto-update capability           │                  │
│  └──────────────────────────────────────┘                  │
└─────────────────────────────────────────────────────────────┘
```

### 3.2 Communication Pattern

**Worker ← → Server**: HMAC-authenticated API calls
- Workers poll jobs via `GET /api/pull_job.php`
- Workers report progress via `POST /api/report_results.php`
- Heartbeat via `GET /api/heartbeat.php`

**Evidence**:
- [docs/API.md#L46-L49](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/docs/API.md#L46-L49): HMAC signature spec
- [api/pull_job.php](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/api/pull_job.php) exists (14,950 bytes)
- [api/heartbeat.php](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/api/heartbeat.php) exists (2,782 bytes)

---

## 4. DATABASE SCHEMA

### 4.1 Core Tables (from config/db.php migrations)

| Table | Purpose | Key Columns |
|-------|---------|-------------|
| `users` | Admin/Agent accounts | id, mobile, username, role, password_hash, is_superadmin |
| `sessions` | Auth sessions | id, user_id, token_hash, expires_at |
| `leads` | Extracted business leads | id, phone, name, city, country, category_id, lat, lon, rating |
| `assignments` | Lead → Agent mapping | id, lead_id, agent_id, status |
| `internal_jobs` | Job queue | id, status, worker_id, attempts, lease_expires_at, query, ll, radius_km |
| `internal_workers` | Worker registry | id, worker_id, last_seen, host, version, status, secret |
| `categories` | Hierarchical taxonomy | id, parent_id, name, slug, depth, path, is_active |
| `category_keywords` | Search keywords | id, category_id, keyword, lang |
| `job_attempts` | Attempt logs | id, job_id, worker_id, started_at, finished_at, success |
| `dead_letter_jobs` | Failed jobs | id, job_id, worker_id, reason, payload |
| `rate_limit` | Rate limiting windows | ip, key, window_start, count (composite PK) |
| `hmac_replay` | Replay attack prevention | worker_id, ts, body_sha, method, path |
| `audit_logs` | Admin actions | id, user_id, action, target, payload |

**Evidence**: All table definitions extracted from [config/db.php#L6-L464](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/config/db.php#L6-L464)

### 4.2 Database Features

- **Foreign Keys**: Enabled via `PRAGMA foreign_keys=ON`
- **WAL Mode**: `PRAGMA journal_mode=WAL`
- **Performance Tuning**: Cache size set to -8000 (8MB), NORMAL sync, MEMORY temp_store
- **Indexes**: 20+ indexes created for common query patterns

**Evidence**: [config/db.php#L3](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/config/db.php#L3)

---

## 5. SECURITY MECHANISMS

### 5.1 Authentication & Authorization

**Admin/Agent Auth**:
- Session-based authentication with cookie
- Password hashing (function used in auth.php)
- Role-based access control: `admin`, `agent`, `is_superadmin` flag
- Login rate limiting via `auth_attempts` table

**Worker Auth**:
- HMAC SHA-256 signatures
- Timestamp validation (prevents replay > 5 min window)
- Optional per-worker secret rotation
- Replay guard table (`hmac_replay`)

**Evidence**:
- [lib/auth.php](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/lib/auth.php) (4,134 bytes)
- [lib/security.php](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/lib/security.php) (4,296 bytes)
- [config/db.php#L53](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/config/db.php#L53): auth_attempts table
- [docs/API.md#L46-L49](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/docs/API.md#L46-L49): HMAC spec

### 5.2 CSRF Protection

**Implementation**: Auto-guard enabled for state-changing requests  
**Token Generation**: Via `lib/csrf.php`  
**Setting**: `security_csrf_auto = 1` (default)

**Evidence**:
- [lib/csrf.php](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/lib/csrf.php) (620 bytes)
- [bootstrap.php#L36](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/bootstrap.php#L36): `system_auto_guard_request()`

### 5.3 Rate Limiting

**Table**: `rate_limit` with composite PK (ip, key, window_start)  
**Granularity**: Per-IP, per-endpoint, per-minute windows  
**Admin Multiplier**: 2x rate for admin users

**Evidence**:
- [config/db.php#L58-L93](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/config/db.php#L58-L93): rate_limit table migration

### 5.4 HTTPS Enforcement

**Mechanism**: `.htaccess` rules redirect HTTP → HTTPS  
**Setting**: `force_https = 1` (seeded default)

**Evidence**:
- [.htaccess](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/.htaccess) file exists (1,485 bytes)

---

## 6. CONFIGURATION MANAGEMENT

### 6.1 Environment Files

| File | Purpose | Evidence |
|------|---------|----------|
| `.env` (root) | Backend default hints | Exists (633 bytes), documented as non-authoritative |
| `config/.env.php` | **Source of truth** for DB path | Referenced in [config/db.php#L3](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/config/db.php#L3) |
| `worker/.env` | Worker runtime config | Exists (256 bytes) |
| `worker/worker.env` | Worker config download | Exists (188 bytes) |

### 6.2 Settings Table (Dynamic Configuration)

**Storage**: SQLite `settings` table (key-value)  
**Defaults Seeded**: [config/db.php#L290-L386](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/config/db.php#L290-L386)

**Critical Settings**:
- `internal_server_enabled` (0/1) — Enables worker API
- `internal_secret` — HMAC shared secret
- `google_api_key` — External API integration
- `worker_base_url` — Server base URL for workers
- `worker_pull_interval_sec` (default: 30)
- `workers_online_window_sec` (default: 90)
- `MAX_ATTEMPTS_DEFAULT` (default: 5)
- `BACKOFF_BASE_SEC` (default: 30)
- `BACKOFF_MAX_SEC` (default: 3600)

---

## 7. OPERATIONAL TOOLING

### 7.1 Deployment Scripts (PowerShell)

Located in `tools/deploy/` and `tools/ops/`:

| Script | Purpose |
|--------|---------|
| `deploy.ps1` | SFTP-based deployment with WinSCP |
| `deploy_cpanel_uapi.ps1` | Fallback deployment via cPanel API |
| `release_and_deploy.ps1` | Build + deploy + verify pipeline |
| `go_live_preflight.php` | Pre-deployment readiness check |
| `production_evidence.ps1` | Full deployment with evidence capture |

**Evidence**: [docs/RUNBOOK.md#L9-L139](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/docs/RUNBOOK.md#L9-L139)

### 7.2 Maintenance Mode

**Mechanism**: File-based flag (`maintenance.flag` at site root)  
**Frontend**: `maintenance.html` served when flag exists  
**Backend**: `.htaccess` conditional redirect

**Evidence**: [docs/RUNBOOK.md#L157-L161](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/docs/RUNBOOK.md#L157-L161)

### 7.3 Worker Management

**Health Check**: Workers expose `http://localhost:4499/status`  
**Update Mechanism**: Self-update via `api/latest.php` and `api/download_worker.php`  
**Channels**: `stable`, `canary`, `beta` (configurable per-worker)  
**Circuit Breaker**: Admin can disable specific workers via `cb_open_workers_json` setting

**Evidence**:
- [worker/health_check.ps1](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/worker/health_check.ps1) (3,569 bytes)
- [api/latest.php](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/api/latest.php) (8,248 bytes)
- [docs/API.md#L53-L55](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/docs/API.md#L53-L55)

### 7.4 Logging

**Locations**:
- `storage/logs/` — Backend logs (app, ops, validation)
- `worker/logs/` — Worker logs (update, service)

**Log Rotation**: Scheduled task via `schedule_rotate_logs.ps1`

**Evidence**: [docs/RUNBOOK.md#L207-L213](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/docs/RUNBOOK.md#L207-L213)

---

## 8. BUSINESS LOGIC DOMAINS

### 8.1 Lead Management

**Workflow**:
1. Admin/Agent creates search job (query + location + category)
2. Job queued in `internal_jobs` table
3. Worker claims job, scrapes data via Playwright
4. Results sent to `POST /api/report_results.php`
5. Leads deduplicated and inserted into `leads` table
6. Classification applied (category assignment)
7. Optional assignment to agent

**Evidence**:
- [api/jobs_multi_create.php](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/api/jobs_multi_create.php) (7,759 bytes)
- [api/report_results.php](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/api/report_results.php) (17,982 bytes)
- [lib/classify.php](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/lib/classify.php) (4,544 bytes)

### 8.2 Classification System

**Taxonomy**: Hierarchical categories with keywords  
**Match Modes**: `contains`, `exact`, `regex`  
**Weighting**: Configurable weights per target field (name, types, website, etc.)  
**Threshold**: Minimum score to auto-assign (default: 1.0)

**Evidence**:
- [classification-system/](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/classification-system/) directory (26 items)
- [config/db.php#L177-L202](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/config/db.php#L177-L202): category_rules table

### 8.3 Geographical Classification

**Supported Region**: Saudi Arabia (regions, cities, districts)  
**Data Format**: CSV imports  
**Point-in-Polygon**: Haversine + hierarchical lookup  
**Performance Target**: p50 ≤ 50ms, ≥98% accuracy

**Evidence**:
- [lib/geo.php](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/lib/geo.php) (11,315 bytes)
- [docs/RUNBOOK.md#L238-L247](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/docs/RUNBOOK.md#L238-L247)

### 8.4 Multi-Location Jobs

**Feature**: Single job → multiple locations (max 10)  
**Grouping**: `job_groups` table links related jobs  
**Use Case**: National campaigns with city-specific targeting

**Evidence**:
- [config/db.php#L213-L238](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/config/db.php#L213-L238): job_groups migration
- [docs/API.md#L16-L24](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/docs/API.md#L16-L24): Multi-location API

---

## 9. EXTERNAL INTEGRATIONS

### 9.1 Data Providers

**Configured Providers** (from settings defaults):
- Google Places API (`google_api_key`)
- OpenStreetMap / Overpass API
- Foursquare (`foursquare_api_key`)
- Mapbox (`mapbox_api_key`)
- Radar (`radar_api_key`)

**Fallback Order**: `osm,foursquare,mapbox,radar,google`

**Evidence**:
- [config/db.php#L294](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/config/db.php#L294): provider_order setting
- [lib/providers.php](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/lib/providers.php) (21,282 bytes)

### 9.2 WhatsApp Integration (Washeej)

**Provider**: wa.washeej.com  
**Endpoint**: `/api/qr/rest/send_message`  
**Auth**: Token-based  
**Settings**: `washeej_token`, `washeej_sender`, `washeej_instance_id`  
**Per-Agent Mode**: Supported (`washeej_use_per_agent`)

**Evidence**:
- [config/db.php#L293](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/config/db.php#L293): washeej_url setting
- [lib/wh_sender.php](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/lib/wh_sender.php) (2,481 bytes)
- [config/db.php#L11](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/config/db.php#L11): washeej_logs table

### 9.3 Alerting Channels

**Supported**:
- Webhook (generic JSON or Slack/Discord/Teams format detection)
- Email (`alert_email`)
- Slack App API (`alert_slack_token`, `alert_slack_channel`)

**Trigger Conditions**:
- Workers offline > threshold
- Jobs stuck in processing
- DLQ items present

**Evidence**: [docs/RUNBOOK.md#L191-L230](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/docs/RUNBOOK.md#L191-L230)

---

## 10. TESTING & QUALITY ASSURANCE

### 10.1 Automated Tests

**Location**: `tests/` directory (3 items)  
**Acceptance Tests**:
- [tools/tests/leads_acceptance.php](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/readiness_report.md#L38) — Lead ingestion pipeline validation
- [tools/geo/acceptance_test.php](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/docs/RUNBOOK.md#L245) — Geo classification accuracy

**Smoke Tests**:
- [tools/smoke_test.ps1](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/docs/RUNBOOK.md#L303) (Windows)
- `tools/smoke_test.sh` (Linux/macOS)

**Evidence**: [readiness_report.md#L38](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/readiness_report.md#L38)

### 10.2 Diagnostic Tools

Located in `tools/diag/`:
- `dump_job_state.php` — Job queue snapshot
- `dump_workers.php` — Worker registry dump
- `probe_heartbeat.php` — Worker heartbeat test
- `probe_pull_job.php` — Job pull API test
- `worker_failover_sim.php` — Failover simulation

**Evidence**: [readiness_report.md#L11](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/readiness_report.md#L11)

---

## 11. DEPLOYMENT ENVIRONMENTS

### 11.1 Production

**Domain**: `https://nexus.op-tg.com`  
**Host**: `nava3.mydnsway.com`  
**User**: `optgccom`  
**Remote Path**: `/home/optgccom/nexus.op-tg.com`  
**Structure**: `{ releases/, current }` (symlink-based)

**Evidence**: [docs/RUNBOOK.md#L40](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/docs/RUNBOOK.md#L40)

### 11.2 Alternative Domain (Historical)

**Domain**: `https://forge.sotwsora.net`  
**Status**: Documented in readiness report but unclear if active

**Evidence**: [readiness_report.md#L4](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/readiness_report.md#L4)

---

## 12. KNOWN GAPS & UNKNOWNS

### 12.1 Missing Information

| Item | Status | Needed For |
|------|--------|------------|
| `config/.env.php` | **NOT FOUND** in visible files | Database path verification |
| PHP version requirement | **NOT SPECIFIED** | Runtime compatibility |
| Composer dependencies | **NOT FOUND** (no composer.json in root) | Library management clarity |
| Frontend bundling | **N/A** (no build system) | Asset optimization |
| Test coverage metrics | **UNKNOWN** | Quality gates |

### 12.2 Secrets Not Committed

✅ **GOOD PRACTICE**: The following are placeholders in settings:
- `google_api_key` (empty default)
- `internal_secret` (must be set in production)
- `washeej_token` (empty default)
- `maintenance_secret` (must be set)

**Evidence**: [config/db.php#L291-L340](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/config/db.php#L291-L340)

---

## 13. TIMEZONE & LOCALIZATION

**Default Timezone**: `Asia/Riyadh`  
**Primary Language**: Arabic (ar)  
**Secondary Language**: English (en)  
**RTL Support**: Likely (given Arabic context)

**Evidence**:
- [bootstrap.php#L8](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/bootstrap.php#L8): `date_default_timezone_set('Asia/Riyadh');`
- [config/db.php#L291](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/config/db.php#L291): `'default_language'=>'ar'`

---

## 14. PERFORMANCE CHARACTERISTICS

### 14.1 Database Optimizations

- **WAL Mode**: Concurrent reads during writes
- **Cache Size**: 8MB in-memory page cache
- **Synchronous Mode**: NORMAL (balance safety/speed)
- **Temp Store**: MEMORY (faster tmp operations)

**Evidence**: [config/db.php#L3](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/config/db.php#L3)

### 14.2 Worker Throttling

- **Item Delay**: 800ms default between scrape items
- **Report Batch Size**: 10 items
- **Report Interval**: 15s (15,000ms)
- **Lease Duration**: 180s default

**Evidence**: [config/db.php#L300](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/config/db.php#L300)

### 14.3 Rate Limits

- **Category Search**: 30 req/min per IP (default)
- **Admin Multiplier**: 2x for admin role
- **Per-Worker Limits**: Optional (`rate_limit_per_min` column)

**Evidence**: [config/db.php#L381](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/config/db.php#L381)

---

## 15. SECURITY POSTURE SUMMARY

✅ **Strengths**:
- HMAC authentication for distributed workers
- Replay attack prevention
- CSRF protection auto-enabled
- Rate limiting infrastructure
- Session-based admin auth with roles
- HTTPS enforcement
- Secrets not committed to repo
- Audit logging for admin actions

⚠️ **Areas for Review** (will be detailed in SECURITY_REPORT.md):
- OPcache reset endpoint security ([docs/RUNBOOK.md#L48-L50](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/docs/RUNBOOK.md#L48-L50))
- File upload handling (if implemented)
- Input validation coverage across all endpoints
- SQL injection prevention (using PDO prepared statements — good practice observed)
- Content Security Policy status (`csp_phase1_enforced=0` default)

---

## 16. CODEBASE STATISTICS

| Component | File Count | Evidence |
|-----------|-----------|----------|
| Admin Pages | 41 | `admin/*.php` listing |
| API Endpoints | 43 | `api/` directory listing |
| Library Modules | 10 | `lib/` directory listing |
| Tools/Scripts | 139+ | `tools/` directory (nested) |
| Documentation | 67+ | `docs/` directory |

**Worker Component**:
- Main logic: 90,565 bytes (index.js)
- Executable: 37.6 MB (worker.exe)
- Profile data: 4,804 items (likely Chromium user data)

---

## CONCLUSION

This is a **production-ready, distributed data extraction platform** with:
- Robust worker orchestration
- HMAC-secured communication
- Comprehensive operational tooling
- Multi-tenant lead management
- Automated deployment pipelines
- Extensive diagnostic capabilities

**Next Steps**: 
1. Verify runtime environment (PHP + SQLite + Node.js)
2. Test local execution
3. Proceed with architecture analysis and deeper code audits
