# TASK BREAKDOWN — Actionable Implementation Tasks

> **Format**: Each task follows DoD (Definition of Done) pattern for trackability

---

## LEGEND
- **Priority**: P0 (Critical) | P1 (High) | P2 (Medium) | P3 (Low)
- **Risk**: 🔴 High | 🟡 Medium | 🟢 Low
- **Effort**: T-shirt sizing (XS=1-2h, S=2-4h, M=4-8h, L=8-16h, XL=16-32h)

---

## PHASE 0: CRITICAL BLOCKERS

### TASK-001: Encrypt SQLite Database
**Priority**: P0  
**Risk**: 🔴 High (data breach if skipped)  
**Effort**: L (12-16h)  
**Owner**: DevOps + Backend  
**Labels**: `security`, `database`, `encryption`

**Scope**:
1. Research encryption options (SQLCipher vs filesystem-level)
2. Backup current `storage/app.sqlite` (verified restore)
3. Install SQLCipher or enable filesystem encryption
4. Migrate database to encrypted format
5. Update connection configuration
6. Test full application CRUD
7. Document decryption process for DR

**Files**:
- `config/.env.php` (update connection string)
- `storage/app.sqlite` (encrypt)
- New file: `docs/ENCRYPTION.md` (document key management)

**Validation Criteria**:
- [ ] Raw database file unreadable without encryption key
- [ ] Application connects successfully
- [ ] All API endpoints functional (smoke test)
- [ ] Backup/restore verified with encrypted DB

**DoD**:
- Code reviewed + merged
- Deployed to staging + tested
- Deployment runbook updated
- Team trained on key management

---

### TASK-002: Restrict opcache_reset Endpoint
**Priority**: P0  
**Risk**: 🔴 High (DoS vulnerability)  
**Effort**: XS (1-2h)  
**Owner**: Backend  
**Labels**: `security`, `api`, `quickwin`

**Scope**:
Add IP whitelist check to `api/opcache_reset.php` (localhost only)

**Files**:
- [api/opcache_reset.php](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/api/opcache_reset.php)

**Change**:
```php
$allowedIPs = ['127.0.0.1', '::1'];
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', $allowedIPs, true)) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}
```

**Validation Criteria**:
- [ ] Access from `127.0.0.1` returns 200 OK
- [ ] Access from external IP returns 403 Forbidden
- [ ] Verified with curl from remote machine

**DoD**:
- Unit test added
- Deployed to production
- Alerts configured for 403 responses on this endpoint

---

### TASK-003: Implement Login Rate Limiting
**Priority**: P0  
**Risk**: 🔴 High (brute-force attacks)  
**Effort**: S (4-6h)  
**Owner**: Backend  
**Labels**: `security`, `authentication`

**Scope**:
1. Add rate limit check in `/auth/login.php`
2. Query `auth_attempts` table for IP + key (mobile/username)
3. If > 5 attempts in last 15 minutes → return 429
4. On successful login → clear attempts
5. Log all failures to `auth_attempts`

**Files**:
- `/auth/login.php` (add logic)
- New file: `lib/rate_limit_login.php` (helper functions)

**Pseudocode**:
```php
$ip = $_SERVER['REMOTE_ADDR'];
$key = $_POST['mobile'] ?? '';
$window = 900; // 15 minutes
$max = 5;

$count = db()->prepare("SELECT COUNT(*) FROM auth_attempts WHERE ip=? AND key=? AND created_at > datetime('now', '-15 minutes')")->execute([$ip, $key])->fetchColumn();

if ($count >= $max) {
    http_response_code(429);
    echo json_encode(['error' => 'too_many_attempts', 'retry_after' => 900]);
    exit;
}

// ... attempt login ...
if (!$success) {
    db()->prepare("INSERT INTO auth_attempts(ip, key, created_at) VALUES(?, ?, datetime('now'))")->execute([$ip, $key]);
}
```

**Validation Criteria**:
- [ ] 6th login attempt returns 429
- [ ] Successful login clears counter
- [ ] Different IPs not rate-limited together

**DoD**:
- Integration test added
- Alerting on high failure rates (> 10 failures/min)
- User-facing error message includes "Try again in X minutes"

---

### TASK-004: Fix File Permissions
**Priority**: P0  
**Risk**: 🟡 Medium (information disclosure)  
**Effort**: XS (1h)  
**Owner**: DevOps  
**Labels**: `security`, `infrastructure`

**Scope**:
Set restrictive permissions on production server

**Commands**:
```bash
cd /home/optgccom/nexus.op-tg.com/current
chown -R www-data:www-data storage/
chmod 750 storage/
chmod 600 storage/app.sqlite
chmod 600 storage/logs/*
chmod 755 worker/
```

**Validation Criteria**:
- [ ] Web server user can read/write `storage/`
- [ ] Other users cannot list `storage/` contents
- [ ] SQLite file not readable by group/world

**DoD**:
- Applied to production
- Deployment script updated to set permissions automatically
- Verified with `ls -la storage/`

---

## PHASE 1: SECURITY HARDENING

### TASK-101: Remove Debug Mode
**Priority**: P1  
**Risk**: 🟡 Medium (information leakage)  
**Effort**: S (2-3h)  
**Owner**: Backend  
**Labels**: `security`, `cleanup`

**Scope**:
1. Remove all `?debug=1` checks from API endpoints
2. Implement structured error logging (JSON to file)
3. Set `display_errors=0` in production php.ini
4. Add generic error responses (no stack traces)

**Files**:
- [api/report_results.php#L312](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/api/report_results.php#L312)
- [api/pull_job.php#L237](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/api/pull_job.php#L237)
- Search codebase for `$_GET['debug']` (find all instances)

**Change Pattern**:
```php
// BEFORE
$debug = isset($_GET['debug']) && $_GET['debug']==='1';
echo json_encode($debug ? ['error'=>'server_error','detail'=>$e->getMessage()] : ['error'=>'server_error']);

// AFTER
error_log(json_encode(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString(), 'endpoint' => $_SERVER['REQUEST_URI']]));
echo json_encode(['error'=>'server_error', 'ref' => uniqid()]);
```

**Validation Criteria**:
- [ ] No stack traces visible to users
- [ ] Errors logged to `storage/logs/errors.log` with correlation ID
- [ ] Verified by intentionally triggering error

**DoD**:
- All debug code paths removed
- Error monitoring dashboard configured (Sentry/Rollbar optional)

---

### TASK-102: Enable Global Rate Limiting
**Priority**: P1  
**Risk**: 🟡 Medium (DoS vulnerability)  
**Effort**: XS (1h)  
**Owner**: Backend  
**Labels**: `security`, `performance`

**Scope**:
1. Enable setting: `UPDATE settings SET value='1' WHERE key='rate_limit_basic';`
2. Configure limits: `rate_limit_global_per_min = 600` 

**Validation Criteria**:
- [ ] 601st request in 1 minute returns 429
- [ ] Rate limit resets after window
- [ ] Admin users get 2x multiplier (1200 req/min)

**DoD**:
- Setting enabled in production
- Monitoring alert if rate limit hit frequently (indicates need for adjustment)

---

### TASK-103: Migrate Secrets to Environment Variables
**Priority**: P1  
**Risk**: 🟡 Medium (secret exposure)  
**Effort**: M (6-8h)  
**Owner**: Backend + DevOps  
**Labels**: `security`, `configuration`

**Scope**:
1. Create `.env.production` template (NOT in git)
2. Modify `config/db.php` and `lib/auth.php` to read `$_ENV` first, fallback to DB
3. Update deployment scripts to inject secrets from secure store (AWS Secrets Manager, Azure Key Vault, or encrypted file)
4. Migrate existing secrets from DB to env vars
5. Remove sensitive values from DB (or hash them)

**Files**:
- New: `.env.production.example` (template with placeholders)
- `config/db.php` (modify to check env vars)
- `lib/auth.php` (modify `get_setting()` to prioritize `$_ENV`)
- `tools/deploy/deploy.ps1` (inject secrets during deployment)

**Implementation**:
```php
function get_setting($key, $default='') {
    // Try environment variable first
    $envKey = strtoupper($key);
    if (isset($_ENV[$envKey]) && $_ENV[$envKey] !== '') {
        return $_ENV[$envKey];
    }
    // Fallback to database
    $stmt = db()->prepare("SELECT value FROM settings WHERE key=?");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? $row['value'] : $default;
}
```

**Validation Criteria**:
- [ ] Application reads secrets from environment
- [ ] Database no longer contains plaintext API keys
- [ ] Secrets rotation procedure documented

**DoD**:
- Deployed to production
- Secret rotation runbook created
- Team trained on secret management

---

### TASK-104: XSS Audit & Remediation
**Priority**: P1  
**Risk**: 🔴 High (user data compromise)  
**Effort**: XL (20-24h)  
**Owner**: Frontend + Backend  
**Labels**: `security`, `xss`, `audit`

**Scope**:
1. Audit ALL PHP pages for unescaped output:
   - Search for `echo $` → review context (HTML, JS, URL, CSS)
   - Search for `<?= $` (short tags)
2. Apply context-appropriate escaping:
   - HTML context: `htmlspecialchars($var, ENT_QUOTES, 'UTF-8')`
   - JS context: `json_encode($var, JSON_HEX_TAG | JSON_HEX_AMP)`
   - URL context: `urlencode($var)`
3. Enable CSP phase-1: `UPDATE settings SET value='1' WHERE key='csp_phase1_enforced';`
4. Fix inline scripts to use nonces: `<script nonce="<?= csp_nonce() ?>">`

**High-Risk Files** (prioritize):
- `admin/leads.php`
- `admin/dashboard.php`
- `agent/fetch.php`
- `admin/categories.php`
- `layout_header.php` (shared template)

**Validation Criteria**:
- [ ] No XSS vulnerabilities found in automated scan (OWASP ZAP or Burp Suite)
- [ ] Manual testing with `<script>alert(1)</script>` in all input fields
- [ ] CSP violations logged (none expected)

**DoD**:
- Security scan report shows zero XSS findings
- CSP phase-1 enabled in production
- Dev team trained on output encoding best practices

---

## PHASE 2: CORRECTNESS & RELIABILITY

### TASK-201: Add Missing Database Indexes
**Priority**: P2  
**Risk**: 🟢 Low (performance degradation under load)  
**Effort**: S (3-4h)  
**Owner**: Backend + DevOps  
**Labels**: `performance`, `database`

**Scope**:
1. Analyze slow queries via `EXPLAIN QUERY PLAN`
2. Add compound indexes for common filters:
   ```sql
   CREATE INDEX IF NOT EXISTS idx_leads_created_category ON leads(created_at DESC, category_id);
   CREATE INDEX IF NOT EXISTS idx_jobs_status_priority_created ON internal_jobs(status, priority DESC, created_at ASC);
   CREATE INDEX IF NOT EXISTS idx_leads_geo_region_city ON leads(geo_region_code, geo_city_id);
   ```
3. Run `ANALYZE` to update query planner statistics

**Files**:
- New migration: `db/migrations/20250106_indexes.php`

**Validation Criteria**:
- [ ] `EXPLAIN QUERY PLAN` shows index usage for filtered queries
- [ ] Query time reduced by >30% (benchmark before/after)

**DoD**:
- Deployed to production
- Monitoring confirms query time improvement

---

### TASK-202: Phone Normalization Enhancement
**Priority**: P2  
**Risk**: 🟢 Low (data quality)  
**Effort**: S (4h)  
**Owner**: Backend  
**Labels**: `data-quality`, `enhancement`

**Scope**:
Support GCC countries (UAE, Kuwait, Qatar, Oman, Bahrain) in phone normalization

**Files**:
- [api/report_results.php#L118-L124](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/api/report_results.php#L118-L124)
- [lib/providers.php](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/lib/providers.php) (similar logic)

**Implementation**:
```php
$countryPrefixes = [
    'sa' => '966', 'ae' => '971', 'kw' => '965', 
    'qa' => '974', 'om' => '968', 'bh' => '973'
];
$countryLower = mb_strtolower($country);
$prefix = $countryPrefixes[$countryLower] ?? '966'; // Default SA
if (strlen($digits) >= 9 && strlen($digits) <= 10 && !str_starts_with($digits, $prefix)) {
    $digits = $prefix . ltrim($digits, '0');
}
```

**Validation Criteria**:
- [ ] UAE number `0501234567` normalized to `971501234567`
- [ ] Kuwait number `99123456` normalized to `96599123456`
- [ ] Existing SA numbers still work

**DoD**:
- Unit tests added for each country
- Backward compatibility verified (no existing leads re-duplicated)

---

### TASK-203: Geo Classification Accuracy Improvement
**Priority**: P2  
**Risk**: 🟡 Medium (poor data quality)  
**Effort**: M (10-12h)  
**Owner**: Backend  
**Labels**: `data-quality`, `geo`

**Scope**:
1. Import latest Saudi Arabia geo datasets
2. Run acceptance test: `php tools/geo/acceptance_test.php`
3. Tune thresholds (currently hardcoded confidence scores)
4. Add logging for unclassified locations
5. Monthly review of `geo_unknown.log` + add missing cities

**Files**:
- `tools/geo/sa_import.py`
- `storage/data/geo/sa/*.csv` (update datasets)
- `lib/geo.php` (tune matching logic)

**Validation Criteria**:
- [ ] Acceptance test shows ≥98% accuracy
- [ ] p50 latency ≤ 50ms
- [ ] p95 latency ≤ 200ms

**DoD**:
- Acceptance test passing
- Unclassified location logging enabled
- Monthly review process documented

---

## PHASE 3: PERFORMANCE OPTIMIZATION

### TASK-301: Implement Settings Cache
**Priority**: P2  
**Risk**: 🟢 Low  
**Effort**: S (4h)  
**Owner**: Backend  
**Labels**: `performance`, `caching`

**Scope**:
Cache frequently accessed settings in PHP opcache or APCu to avoid DB query on every request

**Files**:
- `lib/auth.php` (modify `get_setting()`)

**Implementation**:
```php
function get_setting($key, $default='') {
    static $cache = [];
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    // ... fetch from DB ...
    $cache[$key] = $value;
    return $value;
}
```

**Validation Criteria**:
- [ ] Only 1 DB query per setting per request (verified with profiler)
- [ ] Cache invalidated on setting update

**DoD**:
- Deployed to production
- Monitoring shows reduced DB query count (~30% reduction)

---

## PHASE 4: TESTING

### TASK-401: Setup PHPUnit
**Priority**: P2  
**Risk**: 🟢 Low  
**Effort**: S (3h)  
**Owner**: QA + Backend  
**Labels**: `testing`, `infrastructure`

**Scope**:
1. Install PHPUnit: `composer require --dev phpunit/phpunit`
2. Create `tests/` directory structure
3. Configure `phpunit.xml`
4. Write sample test (e.g., `tests/ClassifyTest.php`)
5. Add to CI/CD pipeline

**Files**:
- New: `composer.json` (if doesn't exist, create minimal one)
- New: `phpunit.xml`
- New: `tests/bootstrap.php`

**Validation Criteria**:
- [ ] `vendor/bin/phpunit` runs successfully
- [ ] Sample test passes

**DoD**:
- CI pipeline runs tests on every PR
- Coverage report generated (via `--coverage-html`)

---

### TASK-402: Unit Tests for classify.php
**Priority**: P2  
**Risk**: 🟡 Medium (critical logic)  
**Effort**: M (8h)  
**Owner**: Backend  
**Labels**: `testing`, `unit`

**Scope**:
Test classification engine with various scenarios

**Test Cases**:
1. Exact keyword match in name → correct category
2. Multiple categories match → highest weight wins
3. No match → return null
4. Regex pattern match
5. Arabic vs English keyword matching

**Files**:
- New: `tests/unit/ClassifyTest.php`

**Coverage Target**: 80%

**DoD**:
- All tests passing
- Coverage report shows ≥80% for `lib/classify.php`

---

## TOTAL TASK COUNT

| Phase | Tasks | Total Effort (hours) |
|-------|-------|---------------------|
| 0 | 4 | 22h |
| 1 | 4 | 40h |
| 2 | 3 | 28h |
| 3 | 1 | 4h |
| 4 | 2 | 11h |
| **TOTAL** | **14** | **105h** |

**Note**: This is a **prioritized subset** of the full roadmap (Phase 0-4 critical path). Remaining tasks from Phase 5-7 documented in [ROADMAP.md](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/AUDIT_OUT/ROADMAP.md).

---

## TRACKING

**Recommended Tool**: GitHub Issues + Projects OR Jira

**Labels**:
- Priority: `P0-critical`, `P1-high`, `P2-medium`, `P3-low`
- Type: `security`, `bug`, `enhancement`, `testing`, `documentation`
- Component: `backend`, `frontend`, `database`, `infrastructure`, `worker`

**Workflow**:
1. Create issue per task
2. Assign to sprint/milestone
3. Link to pull request
4. Update task markdown with PR link
5. Close on deployment to production

---

## SUCCESS METRICS

Track weekly:
- [ ] Tasks completed (target: 3-4/week for 2-person team)
- [ ] Test coverage % (target: increase by 5% weekly)
- [ ] Security findings resolved (target: zero P0/P1 by Week 4)
- [ ] Performance improvement (target: API latency -10% by Week 8)
