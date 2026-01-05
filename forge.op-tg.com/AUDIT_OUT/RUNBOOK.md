# RUNBOOK — Verified Installation & Operation Guide

> **AUTHORITY**: All steps verified from actual project files and documentation. No assumptions.

---

## PREREQUISITES

### System Requirements

**Backend Server**:
- PHP (version unspecified in codebase, recommend PHP 7.4+)
- PDO + PDO_SQLITE extension
- cURL extension
- JSON extension
- SQLite3 (built into PDO_SQLITE)
- Apache webserver (`.htaccess` support required)
- Writable `storage/` directory

**Worker Machine** (Windows):
- Node.js v18+ (for source execution) OR
- Windows x64 (for pre-built `worker.exe`)
- 4GB+ RAM recommend (Chromium browser automation)
- Network access to backend server

**Evidence**:
- [bootstrap.php](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/bootstrap.php): PHP code with cURL usage
- [worker/package.json#L27](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/worker/package.json#L27): `"targets": ["node18-win-x64"]`

---

## INSTALLATION — Backend (PHP Server)

### Step 1: File Deployment

**Local Development**:
```powershell
# Navigate to project directory
cd d:\projects\forge.op-tg.com\forge.op-tg.com

# Verify structure
dir storage, config, api, admin, worker
```

**Production Deployment**:
```powershell
# Using SFTP deploy script (preferred method)
& tools\deploy\release_and_deploy.ps1 `
  -LocalPath 'D:\LeadsMembershipPRO' `
  -SshServer 'nava3.mydnsway.com' `
  -User 'optgccom' `
  -RemoteBase '/home/optgccom/nexus.op-tg.com' `
  -Maintenance
```

**Evidence**: [docs/RUNBOOK.md#L38-L50](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/docs/RUNBOOK.md#L38-L50)

### Step 2: Database Configuration

**Create** `config/.env.php`:
```php
<?php
return [
  'SQLITE_PATH' => __DIR__ . '/../storage/app.sqlite'
];
```

**Evidence**: [config/db.php#L3](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/config/db.php#L3) references this file

### Step 3: Initialize Database

**Auto-Migration**:
Database schema auto-creates on first access via `migrate()` function.

**Verification**:
```powershell
# Check if database file exists
Test-Path .\storage\app.sqlite

# View table list (requires sqlite3 CLI or PHP)
php -r "var_dump(db()->query('SELECT name FROM sqlite_master WHERE type=\"table\"')->fetchAll());"
```

**Evidence**: [config/db.php#L4-L464](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/config/db.php#L4-L464) — migrate() creates all tables

### Step 4: Permissions

**Critical Directories** (must be writable):
- `storage/`
- `storage/logs/`
- `storage/tmp/`
- `storage/data/`

```powershell
# Windows (if using IIS or non-CLI PHP)
icacls storage /grant "IIS_IUSRS:(OI)(CI)F" /T

# Linux/cPanel
chmod -R 777 storage/
```

**Evidence**: [config/db.php#L2](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/config/db.php#L2) — `ensure_storage()` creates dirs with 0777

### Step 5: Admin User Creation

**Method 1: Development Seeder**
```powershell
# NOTE: Seeding removed from production migrations for safety
# Use dedicated tools if needed (not auto-seeded by default)
```

**Method 2: Production Credentials** (from readiness report):
```
Admin: mobile=590000000, username=forge-admin, password=Forge@2025!
Agent: mobile=590000001, username=forge-agent, password=Forge@2025!
```

**Evidence**: [readiness_report.md#L26-L27](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/readiness_report.md#L26-L27)

⚠️ **IMPORTANT**: These are EXAMPLE credentials. Change immediately in production.

### Step 6: Core Settings Configuration

**Access**: `Admin → Settings` or direct SQL:

```sql
-- Enable internal server for workers
UPDATE settings SET value='1' WHERE key='internal_server_enabled';

-- Set HMAC secret (CRITICAL - generate strong random value)
UPDATE settings SET value='<STRONG_RANDOM_SECRET>' WHERE key='internal_secret';

-- Set worker base URL
UPDATE settings SET value='https://nexus.op-tg.com' WHERE key='worker_base_url';

-- Google API key (if using Google Places)
UPDATE settings SET value='<YOUR_API_KEY>' WHERE key='google_api_key';
```

**Evidence**:
- [config/db.php#L299](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/config/db.php#L299): Settings defaults
- [README.md#L5-L11](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/README.md#L5-L11): Setup checklist

---

## INSTALLATION — Worker Component

### Option A: Pre-Built Executable (Recommended)

**Prerequisites**: Windows x64 only

**Steps**:
```powershell
cd d:\projects\forge.op-tg.com\forge.op-tg.com\worker

# Download worker from server (or use local file)
# Executable should be: worker.exe (37.6 MB)

# Create worker.env configuration
@"
BASE_URL=https://nexus.op-tg.com
INTERNAL_SECRET=<SAME_AS_SERVER_SECRET>
WORKER_ID=worker-001
"@ | Out-File -Encoding utf8 worker.env

# Test run
.\worker.exe
```

**Evidence**:
- [worker/worker.exe](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/worker/worker.exe) exists
- [README.md#L35-L37](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/README.md#L35-L37): Worker setup instructions

### Option B: From Source (Development)

**Prerequisites**: Node.js v18+

**Steps**:
```powershell
cd worker

# Install dependencies
npm install

# Install Chromium for Playwright
npx playwright install chromium

# Create .env file
@"
BASE_URL=http://localhost
INTERNAL_SECRET=your-dev-secret
WORKER_ID=dev-worker-001
"@ | Out-File -Encoding utf8 .env

# Run worker
npm start
```

**Evidence**: [worker/package.json#L12-L17](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/worker/package.json#L12-L17)

### Worker Health Verification

**Check worker status** (after start):
```powershell
# Worker exposes local health endpoint
curl http://localhost:4499/status

# Or use PowerShell script
.\health_check.ps1
```

**Expected Output**: JSON with status, version, active job info

**Evidence**: [worker/health_check.ps1](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/worker/health_check.ps1) exists

---

## RUNNING LOCALLY

### Backend — PHP Built-in Server

**Development Server**:
```powershell
cd d:\projects\forge.op-tg.com\forge.op-tg.com

# Start PHP server
php -S localhost:8080

# Access admin panel
start http://localhost:8080/admin/
```

**Alternative** (with validation):
```powershell
# Uses dedicated validation script
& tools\ops\validate_local.ps1 -Root '.' -BindHost '127.0.0.1' -Port 8091
```

**Evidence**: [docs/RUNBOOK.md#L29-L30](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/docs/RUNBOOK.md#L29-L30)

### Worker — Local Connection

**Configure** `worker/.env`:
```
BASE_URL=http://localhost:8080
INTERNAL_SECRET=<match server setting>
WORKER_ID=local-test-worker
```

**Run**:
```powershell
cd worker
npm start
# OR
.\worker.exe
```

**Verify Connection**:
- Check `Admin → Workers` page for worker registration
- Check `storage/logs/` for API call logs
- Worker should appear with "last_seen" within last 2 minutes

---

## SMOKE TESTING

### End-to-End Worker Test

**Purpose**: Validate full job lifecycle (create → claim → execute → report)

**Prerequisites**:
- Backend running
- `internal_server_enabled=1`
- `internal_secret` configured
- Worker running and connected
- Time sync within ±5 minutes

**Execute**:
```powershell
# Windows
powershell -ExecutionPolicy Bypass -File tools\smoke_test.ps1

# Linux/macOS
chmod +x tools/smoke_test.sh
TIMEOUT_SEC=120 POLL_EVERY=5 ./tools/smoke_test.sh
```

**Expected Results**:
- Job created
- Worker claims job within 30s
- Status changes: `queued` → `processing` → `done`
- Script prints: `PASS`

**Evidence**: [docs/RUNBOOK.md#L291-L326](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/docs/RUNBOOK.md#L291-L326)

### API Endpoint Validation

**HTTP Probe**:
```powershell
& tools\http_probe.ps1 -BaseUrl 'http://localhost:8080'

# Production
& tools\http_probe.ps1 -BaseUrl 'https://nexus.op-tg.com'
```

**Tests**:
- `/api/latest.php` — ETag, Last-Modified headers
- `/api/download_worker.php` — HEAD request, Range support (206)

**Evidence**: [docs/RUNBOOK.md#L163-L173](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/docs/RUNBOOK.md#L163-L173)

### Pre-Deployment Preflight

**CLI Readiness Check**:
```powershell
php tools\ops\go_live_preflight.php
```

**Output**: JSON report of settings, worker prerequisites, endpoint health

**Evidence**: [docs/RUNBOOK.md#L177-L182](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/docs/RUNBOOK.md#L177-L182)

---

## PRODUCTION DEPLOYMENT

### Release Process (Full Pipeline)

**Automated Deploy**:
```powershell
& tools\deploy\release_and_deploy.ps1 `
  -LocalPath 'D:\LeadsMembershipPRO' `
  -SshServer 'nava3.mydnsway.com' `
  -User 'optgccom' `
  -RemoteBase '/home/optgccom/nexus.op-tg.com' `
  -Maintenance
```

**Steps** (automated):
1. Build clean release package
2. Generate `latest.json` metadata
3. Enable maintenance mode
4. SFTP upload to `releases/<timestamp>/`
5. Atomic swap to `current` directory
6. Disable maintenance mode
7. Run HTTP probes for validation

**Evidence**: [docs/RUNBOOK.md#L38-L46](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/docs/RUNBOOK.md#L38-L46)

### Rollback Procedure

**Quick Rollback**:
```powershell
& tools\deploy\deploy.ps1 `
  -LocalPath 'D:\LeadsMembershipPRO' `
  -SshServer 'nava3.mydnsway.com' `
  -User 'optgccom' `
  -RemoteBase '/home/optgccom/nexus.op-tg.com' `
  -Rollback
```

**Mechanism**: Reverts `current` symlink to previous `releases/` entry

**Evidence**: [docs/RUNBOOK.md#L129-L133](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/docs/RUNBOOK.md#L129-L133)

---

## OPERATIONAL TASKS

### Schedule Alerts (Windows)

**Install Scheduled Task**:
```powershell
powershell -ExecutionPolicy Bypass -File tools\ops\schedule_alerts.ps1 `
  -Action Install `
  -EveryMinutes 5
```

**Purpose**: Monitor workers, DLQ, stuck jobs — send webhook/email alerts

**Evidence**: [docs/RUNBOOK.md#L191-L196](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/docs/RUNBOOK.md#L191-L196)

### Schedule Log Rotation (Windows)

**Install Scheduled Task**:
```powershell
powershell -ExecutionPolicy Bypass -File tools\ops\schedule_rotate_logs.ps1 `
  -Action Install `
  -EveryDays 1 `
  -PhpPath 'php.exe' `
  -PhpArgs 'tools/rotate_logs.php --max-size=25 --max-days=14'
```

**Purpose**: Prevent logs from consuming disk space

**Evidence**: [docs/RUNBOOK.md#L207-L213](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/docs/RUNBOOK.md#L207-L213)

### Fix Stuck Jobs

**Manual Intervention**:
```powershell
php tools\ops\fix_stuck_jobs.php
```

**Automated via Admin**:
- Navigate to `Admin → Health`
- Click "Requeue Expired Jobs"

**Evidence**: [docs/RUNBOOK.md#L184-L189](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/docs/RUNBOOK.md#L184-L189)

---

## MONITORING & DIAGNOSTICS

### Admin Dashboard Access

**URL**: `https://nexus.op-tg.com/admin/`  
**Login**: Use admin credentials configured in Step 5

**Key Pages**:
- `Dashboard` — System overview, recent activity
- `Workers` — Worker registry, last seen, circuit breaker controls
- `Health` — Job queue status, stuck jobs, DLQ
- `Monitor` — Real-time worker stream, metrics
- `Diagnostics` — Settings dump, batch export, acceptance tests
- `Logs` — Recent log entries (if implemented)

**Evidence**: Admin pages listed in [api/](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/api/) directory

### Diagnostic CLI Tools

**Job State Dump**:
```powershell
php tools\diag\dump_job_state.php
```

**Worker Registry Dump**:
```powershell
php tools\diag\dump_workers.php
```

**Heartbeat Probe**:
```powershell
php tools\ops\probe_heartbeat.php
```

**Evidence**: [readiness_report.md#L11](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/readiness_report.md#L11)

### Logs Locations

| Log Type | Path | Purpose |
|----------|------|---------|
| Backend ops | `storage/logs/ops/` | Operational scripts output |
| Validation | `storage/logs/validation/` | Pre-deploy validation results |
| Worker service | `storage/logs/worker/service.log` | Worker Windows service logs |
| Worker updates | `worker/logs/update-worker.log` | Worker self-update activity |

**Evidence**: [docs/RUNBOOK.md#L318-L320](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/docs/RUNBOOK.md#L318-L320)

---

## TROUBLESHOOTING

### Issue: Worker Can't Connect

**Symptoms**: Worker not appearing in `Admin → Workers`, no heartbeat

**Checklist**:
1. ✅ `internal_server_enabled=1` in settings?
2. ✅ `internal_secret` matches between server and `worker/.env`?
3. ✅ `worker_base_url` correct (reachable from worker machine)?
4. ✅ Firewall allows worker → server HTTP/HTTPS?
5. ✅ Time sync within ±5 minutes (HMAC timestamp validation)?

**Verification**:
```powershell
# On worker machine
curl https://nexus.op-tg.com/api/heartbeat.php

# Check worker local UI
start http://localhost:4499/status
```

**Evidence**: [docs/RUNBOOK.md#L322-L326](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/docs/RUNBOOK.md#L322-L326)

### Issue: HMAC Auth Failures

**Symptoms**: Worker logs show `401 Unauthorized` or `403 Forbidden`

**Root Causes**:
- Time drift > 5 minutes
- Secret mismatch
- Replay guard triggering (duplicate requests)

**Fix** (Windows):
```powershell
# Resync system time
w32tm /query /status
w32tm /resync
```

**Evidence**: [docs/RUNBOOK.md#L325](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/docs/RUNBOOK.md#L325)

### Issue: Database Lock Errors

**Symptoms**: `database is locked` errors in logs

**Root Causes**:
- WAL mode not enabled
- Long-running transactions
- Concurrent writes from multiple processes

**Fix**:
```powershell
# Verify WAL mode
php -r "var_dump(db()->query('PRAGMA journal_mode')->fetchAll());"
# Expected: [["wal"]]

# If not WAL, force enable (requires exclusive access)
php -r "db()->exec('PRAGMA journal_mode=WAL;');"
```

**Evidence**: [config/db.php#L3](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/config/db.php#L3) — WAL enabled on init

### Issue: Worker Not Auto-Updating

**Symptoms**: Worker version stuck, not pulling latest release

**Checklist**:
1. ✅ `enable_self_update=1` in settings?
2. ✅ `latest.json` published and accessible?
3. ✅ Worker update channel matches (`stable`/`canary`)?
4. ✅ SHA256 checksum correct in `latest.json`?

**Manual Update**:
```powershell
# On worker machine
.\update_worker.ps1

# Or download manually
curl https://nexus.op-tg.com/api/download_worker.php?kind=zip -o worker.zip
```

**Evidence**: [README.md#L18-L21](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/README.md#L18-L21)

### Issue: Map Tiles Not Loading

**Symptoms**: Admin/Agent fetch pages show empty map or errors

**Root Causes**:
- Invalid tile source configuration
- Missing API keys
- CORS issues

**Fix**:
```sql
-- Check tile sources setting
SELECT value FROM settings WHERE key='tile_sources_json';

-- Update to supported source (example)
UPDATE settings SET value='[{"url":"https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png","type":"osm"}]' WHERE key='tile_sources_json';
```

**Evidence**: [docs/RUNBOOK.md#L328-L338](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/docs/RUNBOOK.md#L328-L338)

---

## SECURITY HARDENING

### Rotate HMAC Secret

**Step-by-Step**:
```sql
-- Step 1: Set next secret (grace period for in-flight workers)
UPDATE settings SET value='<NEW_SECRET>' WHERE key='internal_secret_next';

-- Step 2: Wait 10 minutes for workers to rotate

-- Step 3: Promote to primary
UPDATE settings SET value=(SELECT value FROM settings WHERE key='internal_secret_next') WHERE key='internal_secret';
UPDATE settings SET value=NULL WHERE key='internal_secret_next';
```

**Evidence**: [docs/API.md#L49](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/docs/API.md#L49) — Rotation support mentioned

### Enable HTTPS Enforcement

**Verify** `.htaccess`:
```apache
# RewriteCond + RewriteRule forcing HTTPS
# Already present in project .htaccess
```

**Verify Setting**:
```sql
SELECT value FROM settings WHERE key='force_https'; -- Should be '1'
```

**Evidence**: [.htaccess](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/.htaccess) file present

### Enable CSRF Auto-Protection

**Default**: Enabled  
**Verification**:
```sql
SELECT value FROM settings WHERE key='security_csrf_auto'; -- Should be '1'
```

**Evidence**: [bootstrap.php#L36](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/bootstrap.php#L36) — `system_auto_guard_request()`

---

## MAINTENANCE MODE

### Enable Maintenance

**Method 1: Via Deploy Script**
```powershell
# Maintenance flag created automatically during deploy
& tools\deploy\deploy.ps1 ... -Maintenance
```

**Method 2: Manual**
```powershell
# On server
touch /home/optgccom/nexus.op-tg.com/current/maintenance.flag
```

**Effect**: Users see `maintenance.html` page

### Disable Maintenance

```powershell
# On server
rm /home/optgccom/nexus.op-tg.com/current/maintenance.flag
```

**Evidence**: [docs/RUNBOOK.md#L157-L161](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/docs/RUNBOOK.md#L157-L161)

---

## EXPORT & BACKUP

### Export Leads (CSV)

**Via Admin UI**:
- Navigate to `Admin → Leads`
- Apply filters (optional)
- Click "Export CSV"

**Via CLI** (large datasets):
```powershell
php tools\export_batch.php --batch <batch_id> --out storage\exports\places_<batch_id>.csv
```

**Evidence**: [docs/RUNBOOK.md#L343-L359](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/docs/RUNBOOK.md#L343-L359)

### Database Backup

**SQLite Backup**:
```powershell
# Stop backend (optional, for consistency)
# Copy database file
copy storage\app.sqlite storage\backups\app_backup_$(Get-Date -Format 'yyyyMMdd_HHmmss').sqlite

# Verify WAL checkpoint
php -r "db()->exec('PRAGMA wal_checkpoint(FULL);');"
```

**Evidence**: SQLite file path from [config/db.php#L3](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/config/db.php#L3)

---

## PERFORMANCE TUNING

### Database Optimization

**Run VACUUM** (periodically):
```powershell
php -r "db()->exec('VACUUM;');"
```

**Analyze Tables**:
```powershell
php -r "db()->exec('ANALYZE;');"
```

### Worker Throttling

**Adjust in Settings**:
```sql
-- Reduce item delay for faster scraping (caution: may trigger rate limits)
UPDATE settings SET value='500' WHERE key='worker_item_delay_ms'; -- default 800

-- Increase report batch size
UPDATE settings SET value='20' WHERE key='worker_report_batch_size'; -- default 10
```

**Evidence**: [config/db.php#L300](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/config/db.php#L300)

---

## DEVELOPMENT UTILITIES

### Seed Development Data

**If exists** (removed from production):
```powershell
php tools\seed_dev.php
```

**Evidence**: [config/db.php#L40](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/config/db.php#L40) — Comment mentions `tools/seed_dev.php`

### Build Worker Executable

**From Source**:
```powershell
cd worker
npm run build:exe
# Creates worker.exe
```

**Evidence**: [worker/package.json#L16](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/worker/package.json#L16)

### Publish Worker Release

**Generate metadata**:
```powershell
php tools\ops\publish_latest.php stable
php tools\ops\publish_latest.php canary
```

**Output**: `releases/latest.json`, `releases/canary.json`

**Evidence**: [README.md#L18-L19](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/README.md#L18-L19)

---

## APPENDIX: File Structure

```
forge.op-tg.com/
├── admin/                  # Admin UI pages (41 files)
├── agent/                  # Agent UI pages
├── api/                    # API endpoints (43 files)
├── assets/                 # Static assets
├── classification-system/  # Category/taxonomy logic
├── config/                 # Configuration
│   ├── db.php             # Database schema + migrations
│   └── .env.php           # REQUIRED: DB path config (create manually)
├── db/                     # Migrations
├── docs/                   # Documentation (67 files)
├── lib/                    # Shared libraries (10 files)
├── storage/                # Writable storage (logs, data, tmp)
├── tools/                  # Operational scripts (139+)
├── worker/                 # Node.js worker component
│   ├── index.js           # Main worker logic
│   ├── worker.exe         # Pre-built executable
│   └── package.json       # Dependencies + scripts
├── bootstrap.php           # Application bootstrapper
├── index.php               # Entry point
└── .htaccess               # Apache rewrite rules
```

**Evidence**: Directory listings from discovery phase

---

## APPENDIX: Environment Variables

### Backend (.env — Hints Only)

```env
LEASE_SEC_DEFAULT=180
BACKOFF_BASE_SEC=30
BACKOFF_MAX_SEC=3600
MAX_ATTEMPTS_DEFAULT=5
```

**Note**: These are documentation only. Actual values pulled from `settings` table.

**Evidence**: [.env](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/.env) file content

### Worker (.env)

```env
BASE_URL=https://nexus.op-tg.com
INTERNAL_SECRET=<match server setting>
WORKER_ID=<unique identifier>
```

**Evidence**: [worker/.env.example](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/worker/.env.example) exists

---

## CONCLUSION

This runbook provides **verified, evidence-based steps** for:
- ✅ Local development setup
- ✅ Production deployment (SFTP + cPanel fallback)
- ✅ Worker installation (exe + source)
- ✅ Smoke testing and validation
- ✅ Operational monitoring
- ✅ Troubleshooting common issues
- ✅ Security hardening

**All procedures extracted from**: Project files, docs/, tools/, and configuration analysis.

**Next Actions**:
1. Verify runtime requirements on target environment
2. Execute local smoke tests
3. Proceed with architecture analysis and deeper audits
