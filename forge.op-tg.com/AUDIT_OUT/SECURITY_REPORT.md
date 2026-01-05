# SECURITY_REPORT — Comprehensive Security Audit

> **CRITICAL**: All findings evidence-based from code inspection. Severity: 🔴 Critical | 🟠 High | 🟡 Medium | 🟢 Low

---

## EXECUTIVE SUMMARY

**Overall Security Posture**: ⚠️ MODERATE with areas requiring immediate attention

**Strengths**:
- ✅ HMAC authentication for worker API
- ✅ CSRF protection enabled
- ✅ Replay attack prevention
- ✅ Password hashing (bcrypt/argon2)
- ✅ Secure cookie flags (HttpOnly, Secure, SameSite)
- ✅ Foreign key constraints enabled

**Critical Risks**:
- 🔴 OPcache reset endpoint accessible during maintenance
- 🔴 Debug mode exposure in some endpoints
- 🔴 Secrets in settings table (SQLite file readable if compromised)
- 🟠 No encryption at rest for sensitive data
- 🟠 Rate limiting not enforced on all endpoints

---

## 1. AUTHENTICATION & SESSION MANAGEMENT

### 1.1 Password Security ✅ GOOD
**Evidence**: [lib/auth.php#L19](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/lib/auth.php#L19)

**Implementation**:
```php
password_verify($password, $u['password_hash'])
```

**Strengths**:
- Uses PHP `password_hash()` / `password_verify()` (bcrypt/argon2)
- Passwords salted automatically

**Recommendations**:
- 🟢 Consider enforcing minimum password strength (8+ chars, complexity)
- 🟢 Implement password expiry for admin accounts (90 days)

---

### 1.2 Session Management ✅ MOSTLY SECURE
**Evidence**: [lib/auth.php#L4-L14](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/lib/auth.php#L4-L14)

**Cookie Flags**:
```php
'secure' => (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS'])!=='off'),
'httponly' => true,
'samesite' => 'Lax'
```

**Strengths**:
- ✅ HttpOnly (prevents XSS cookie theft)
- ✅ Secure flag (HTTPS-only, when detected)
- ✅ SameSite=Lax (CSRF mitigation)
- ✅ Session regeneration on login ([auth.php#L19](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/lib/auth.php#L19))

**Issues**:
- 🟡 **MEDIUM**: Remember-me tokens stored as SHA-256 (better than plaintext, but consider HMAC)
- 🟡 **MEDIUM**: No session timeout enforcement (relies on cookie expiry only)

**Recommendations**:
- Implement server-side session expiry (e.g., 24h for admin, 7d for agent)
- Consider rotating remember-me tokens on each use

---

### 1.3 Login Rate Limiting ⚠️ BASIC
**Evidence**: [config/db.php#L53](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/config/db.php#L53) — `auth_attempts` table exists

**Current State**: Table created but enforcement not visible in login flow

**Issues**:
- 🟠 **HIGH**: No evidence of rate limiting on `/auth/login.php`
- 🟠 **HIGH**: Brute-force attacks possible (10-100 attempts/sec)

**Recommendations**:
- **URGENT**: Implement login throttling (max 5 attempts per IP per 15 minutes)
- Log failed attempts to `auth_attempts` table
- Consider CAPTCHA after 3 failed attempts

---

## 2. API SECURITY

### 2.1 HMAC Authentication ✅ EXCELLENT
**Evidence**: [lib/security.php](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/lib/security.php), [api/pull_job.php#L31](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/api/pull_job.php#L31)

**Algorithm**:
```
HMAC-SHA256(upper(METHOD) + '|' + PATH + '|' + sha256(body) + '|' + TIMESTAMP)
```

**Strengths**:
- ✅ Strong signature algorithm (SHA-256)
- ✅ Timestamp validation (prevents replay > 5min window)
- ✅ Body integrity check (SHA-256 hash)
- ✅ Replay prevention table (`hmac_replay`)
- ✅ Secret rotation support (`internal_secret_next`)

**Issues**:
- 🟢 **LOW**: 5-minute time window may be too wide (consider reducing to 2-3 min)

---

### 2.2 Replay Attack Prevention ✅ IMPLEMENTED
**Evidence**: [api/pull_job.php#L37-L46](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/api/pull_job.php#L37-L46)

**Mechanism**:
- Unique constraint on `(worker_id, ts, body_sha, method, path)`
- Returns 409 Conflict if duplicate detected

**Strengths**:
- ✅ Prevents exact duplicate requests
- ✅ TTL cleanup (7 days default)

**Issues**:
- 🟡 **MEDIUM**: Table growth (10K-1M rows) — ensure periodic cleanup runs

---

### 2.3 Rate Limiting ⚠️ PARTIAL
**Evidence**: [lib/system.php#L179-L216](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/lib/system.php#L179-L216)

**Current State**: Global rate limit exists but gated by `rate_limit_basic` setting

**Issues**:
- 🟠 **HIGH**: Not enabled by default (`rate_limit_basic=0`)
- 🟠 **HIGH**: Worker API endpoints not rate-limited per worker (only HMAC auth)
- 🟡 **MEDIUM**: Category search limited but only for admins

**Recommendations**:
- **Enable global rate limiting** (`rate_limit_basic=1`)
- Implement per-worker rate limits (use `rate_limit_per_min` column)
- Add rate limiting to public endpoints (health, latest.php)

---

## 3. INPUT VALIDATION & INJECTION

### 3.1 SQL Injection ✅ PROTECTED
**Evidence**: All database queries use PDO prepared statements

**Example** ([api/report_results.php#L169](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/api/report_results.php#L169)):
```php
$insLead->execute([$phone,$phone_norm,$name,$city,$country,$job['requested_by_user_id']]);
```

**Strengths**:
- ✅ Consistent use of `$pdo->prepare()` + `execute()`
- ✅ No string concatenation in SQL
- ✅ Foreign keys enforced (`PRAGMA foreign_keys=ON`)

**No issues found in this area** ✅

---

### 3.2 XSS (Cross-Site Scripting) ⚠️ NEEDS REVIEW
**Evidence**: Server-rendered PHP pages (admin/, agent/)

**Current Protection**:
- CSP with nonce support ([lib/system.php#L32-L70](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/lib/system.php#L32-L70))
- `htmlspecialchars()` used in some places

**Issues**:
- 🟠 **HIGH**: Need to audit ALL output contexts for proper escaping
- 🟠 **HIGH**: `unsafe-inline` still allowed by default (CSP phase-1 not enforced)
- 🟡 **MEDIUM**: No evidence of output encoding library (e.g., Twig auto-escape)

**Recommendations**:
- **Audit all PHP pages** for user-controlled output
- Enable CSP phase-1 enforcement (`csp_phase1_enforced=1`)
- Use templating engine with auto-escaping OR enforce `htmlspecialchars($var, ENT_QUOTES, 'UTF-8')` everywhere

---

### 3.3 CSRF Protection ✅ IMPLEMENTED
**Evidence**: [lib/csrf.php](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/lib/csrf.php), [lib/system.php#L169-L178](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/lib/system.php#L169-L178)

**Strengths**:
- ✅ Auto-enforced on form POST requests (`security_csrf_auto=1`)
- ✅ Token stored in session
- ✅ SameSite=Lax cookie (additional layer)

**Issues**:
- 🟢 **LOW**: No evidence of double-submit cookie pattern (current approach is fine)

---

## 4. DATA PROTECTION

### 4.1 Encryption at Rest 🔴 CRITICAL ISSUE
**Current State**: SQLite database file **NOT** encrypted

**Risks**:
- 🔴 **CRITICAL**: If server compromised, `storage/app.sqlite` contains:
  - All user password hashes
  - INTERNAL_SECRET (in `settings` table)
  - All lead data (phone numbers, names, emails)
  - Worker secrets
  - Session tokens

**Recommendations**:
- **URGENT**: Use SQLite Encryption Extension (SEE) or SQLCipher
- **Alternative**: Encrypt entire `storage/` directory at filesystem level (LUKS, BitLocker)
- **Immediate**: Restrict file permissions (`chmod 600 storage/app.sqlite`)

---

### 4.2 Secrets Management 🟠 HIGH RISK
**Current State**: Secrets stored in `settings` table (plaintext in SQLite)

**Sensitive Values**:
- `internal_secret` (HMAC key)
- `google_api_key`
- `washeej_token`
- `maintenance_secret`

**Evidence**: [config/db.php#L291-L340](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/config/db.php#L291-L340)

**Issues**:
- 🟠 **HIGH**: No encryption of secrets at rest
- 🟠 **HIGH**: Secrets visible in database exports
- 🟡 **MEDIUM**: No audit trail for secret access/rotation

**Recommendations**:
- Use environment variables + **Vault/KMS** for production secrets
- Encrypt sensitive columns in database (transparent encryption)
- Implement secret access logging

---

### 4.3 Sensitive Data Exposure ⚠️ MODERATE
**Leakage Risks**:
- 🟡 Debug endpoints with `?debug=1` query parameter
- 🟡 Error messages may expose stack traces ([api/report_results.php#L312](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/api/report_results.php#L312))
- 🟡 Worker logs may contain sensitive job data

**Recommendations**:
- Disable debug mode in production (`display_errors=0` enforced)
- Implement structured logging with PII redaction
- Review exported CSV files for over-exposure

---

## 5. ACCESS CONTROL

### 5.1 Role-Based Access Control ✅ IMPLEMENTED
**Evidence**: [lib/auth.php#L37-L47](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/lib/auth.php#L37-L47)

**Strengths**:
- ✅ `require_role('admin')` enforced on admin pages
- ✅ `require_role('agent')` enforced on agent pages
- ✅ Superadmin flag exists (`is_superadmin`)

**Issues**:
- 🟡 **MEDIUM**: No evidence of permission granularity (e.g., can agent A access agent B's leads?)
- 🟡 **MEDIUM**: Superadmin privileges not clearly defined in code

**Recommendations**:
- Implement permission matrix (CRUD per resource per role)
- Add row-level security (agents see only their assignments)

---

### 5.2 API Authorization ✅ HMAC-GATED
**Evidence**: All worker API endpoints check `verify_worker_auth()`

**Strengths**:
- ✅ No API endpoints exposed without auth
- ✅ Circuit breaker can disable specific workers

**Issues**:
- 🟢 **LOW**: No per-worker permission scoping (all workers can claim any job)

---

## 6. NETWORK SECURITY

### 6.1 HTTPS Enforcement ✅ IMPLEMENTED
**Evidence**: [lib/system.php#L151-L165](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/lib/system.php#L151-L165)

**Mechanism**:
- Setting: `force_https=1`
- 308 redirect HTTP → HTTPS
- HSTS header with 1-year max-age

**Strengths**:
- ✅ Redirect enforced at application level
- ✅ HSTS prevents downgrade attacks

**Issues**:
- 🟢 **LOW**: `.htaccess` rules should also enforce HTTPS (defense in depth)

---

### 6.2 CORS (Cross-Origin Resource Sharing) ℹ️ NOT CONFIGURED
**Current State**: No explicit CORS headers set

**Implication**: Same-origin policy enforced by default (browsers)

**Recommendation**:
- 🟢 If future SPA needed, configure CORS carefully (whitelist specific origins)

---

## 7. FILE UPLOAD SECURITY

**Evidence**: No file upload handlers found in codebase  
**Status**: ✅ NOT APPLICABLE (no user file uploads)

**Future Consideration**: If implemented:
- Validate file type (whitelist extensions)
- Scan for malware
- Store outside webroot
- Use random filenames

---

## 8. DEPENDENCY SECURITY

### 8.1 PHP Dependencies
**Evidence**: No `composer.json` found  
**Implication**: No third-party PHP libraries used (reduces attack surface)

---

### 8.2 Node.js Dependencies (Worker)
**Evidence**: [worker/package.json](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/worker/package.json)

**Dependencies**:
- `playwright@1.47.0`
- `express@4.19.2`
- `node-fetch@3.3.2`
- `dotenv@16.4.5`

**Recommendations**:
- 🟡 **MEDIUM**: Run `npm audit` regularly
- 🟡 **MEDIUM**: Update dependencies quarterly
- 🟢 Consider using Snyk or Dependabot

---

## 9. LOGGING & MONITORING

### 9.1 Audit Logging ✅ PARTIAL
**Evidence**: `audit_logs` table exists, `alert_events` table exists

**Strengths**:
- ✅ Admin actions logged
- ✅ Worker health events captured

**Issues**:
- 🟡 **MEDIUM**: No evidence of login/logout logging
- 🟡 **MEDIUM**: No evidence of failed auth attempt logging
- 🟡 **MEDIUM**: Logs not protected from tampering (SQLite file writable)

**Recommendations**:
- Log all authentication events (success + failure)
- Send audit logs to external SIEM (Splunk, ELK)
- Implement log integrity checks (HMAC signatures)

---

### 9.2 Alert Mechanisms ✅ IMPLEMENTED
**Evidence**: [tools/ops/alerts_tick.php](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/docs/RUNBOOK.md#L225)

**Triggers**:
- Workers offline
- DLQ not empty
- Jobs stuck processing

**Channels**: Webhook, Email, Slack

**Issues**:
- 🟢 **LOW**: No alerts for security events (failed logins, unauthorized access)

---

## 10. SPECIFIC ENDPOINT VULNERABILITIES

### 10.1 🔴 CRITICAL: opcache_reset.php
**Path**: [api/opcache_reset.php](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/api/opcache_reset.php)  
**Risk**: Cache poisoning during maintenance window

**Issue**:
- Accessible with `X-Internal-Secret` header **during maintenance mode**
- If maintenance.flag leaked/guessed, attacker could reset cache repeatedly (DoS)

**Recommendations**:
- **URGENT**: Restrict to localhost only
- Add IP whitelist check
- Rate limit this endpoint aggressively

---

### 10.2 🟠 HIGH: Debug Mode Exposure
**Evidence**: [api/report_results.php#L312](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/api/report_results.php#L312)

**Issue**:
```php
$debug = isset($_GET['debug']) && $_GET['debug']==='1';
echo json_encode($debug ? ['error'=>'server_error','detail'=>$e->getMessage()] : ['error'=>'server_error']);
```

**Risk**: Stack traces may reveal:
- File paths
- Database schema details
- Internal logic

**Recommendations**:
- **Remove debug mode from production** OR
- Gate debug mode behind admin auth + IP whitelist

---

### 10.3 🟡 MEDIUM: latest.php ETag Weakness
**Path**: [api/latest.php](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/api/latest.php)  
**Issue**: ETag based on file mtime (predictable)

**Recommendation**:
- Use SHA-256 of file content as ETag (stronger cache validation)

---

## 11. DEPLOYMENT SECURITY

### 11.1 Maintenance Mode ✅ IMPLEMENTED
**Evidence**: [docs/RUNBOOK.md#L157-L161](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/docs/RUNBOOK.md#L157-L161)

**Mechanism**: `maintenance.flag` file existence check

**Strengths**:
- ✅ Simple, effective
- ✅ Graceful degradation (shows static HTML)

**Issues**:
- 🟢 **LOW**: No authentication bypass for admin during maintenance (acceptable)

---

### 11.2 File Permissions ℹ️ NEEDS VERIFICATION
**Current State**: `chmod 777` used in [config/db.php#L2](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/config/db.php#L2)

**Issue**:
- 🟠 **HIGH**: Overly permissive (world-writable)

**Recommendations**:
- **Set `storage/` to 750** (owner RWX, group RX, no world access)
- **Set `app.sqlite` to 600** (owner RW only)
- Use webserver user/group ownership

---

## 12. OWASP TOP 10 (2021) CHECKLIST

| Risk | Status | Notes |
|------|--------|-------|
| **A01: Broken Access Control** | 🟡 PARTIAL | RBAC exists, needs row-level security |
| **A02: Cryptographic Failures** | 🔴 HIGH | No encryption at rest |
| **A03: Injection** | ✅ GOOD | PDO prevents SQL injection |
| **A04: Insecure Design** | 🟢 LOW | Architecture sound |
| **A05: Security Misconfiguration** | 🟠 MODERATE | Debug mode, file permissions |
| **A06: Vulnerable Components** | 🟡 MEDIUM | Need npm audit |
| **A07: Authentication Failures** | 🟠 MODERATE | No login rate limiting |
| **A08: Data Integrity Failures** | ✅ GOOD | HMAC signatures |
| **A09: Logging Failures** | 🟡 MEDIUM | Partial logging |
| **A10: SSRF** | ✅ N/A | No user-controlled URLs |

---

## IMMEDIATE ACTION ITEMS (Priority Order)

### Week 0 (Blockers)
1. 🔴 **Encrypt SQLite database** (SQLCipher or filesystem encryption)
2. 🔴 **Restrict opcache_reset.php** to localhost only
3. 🔴 **Implement login rate limiting** (5 attempts per IP per 15min)
4. 🟠 **Fix file permissions** (`storage/` to 750, `.sqlite` to 600)

### Week 1 (High Priority)
5. 🟠 **Remove debug mode** from production endpoints
6. 🟠 **Enable global rate limiting** (`rate_limit_basic=1`)
7. 🟠 **Audit XSS exposure** in all PHP pages
8. 🟠 **Move secrets to environment variables** (not DB)

### Week 2 (Medium Priority)
9. 🟡 **Enable CSP phase-1** (`csp_phase1_enforced=1`)
10. 🟡 **Implement audit logging** for auth events
11. 🟡 **Run npm audit** on worker dependencies
12. 🟡 **Add session timeout** enforcement (server-side)

### Continuous
13. **Quarterly dependency updates** (npm, OS packages)
14. **Annual penetration testing**
15. **Quarterly access review** (users, roles)

---

## COMPLIANCE CONSIDERATIONS

### GDPR (If EU users present)
- ✅ Data minimization (only phone, name, city)
- ⚠️ Right to erasure (need automated deletion endpoint)
- ⚠️ Encryption at rest (GDPR Art. 32)
- ⚠️ Breach notification plan (72h requirement)

### PCI DSS (If storing payment data)
- ❌ **NOT APPLICABLE** (no payment data detected)

---

## CONCLUSION

**Security Maturity Level**: **3/5 (Moderate)**

**Strengths**:
- Strong cryptographic foundations (HMAC, password hashing)
- Well-architected authentication system
- SQL injection fully mitigated

**Critical Gaps**:
- Encryption at rest
- Rate limiting not fully deployed
- File permissions too permissive
- Debug modes in production

**Estimated Remediation Effort**: 40-60 hours (2 engineers, 3-4 weeks)

**Risk Acceptance**: If immediate fixes cannot be deployed, implement:
- 24/7 monitoring on `auth_attempts` table
- Automated backups to secure location
- Network-level firewall rules (allow only HTTPS)
