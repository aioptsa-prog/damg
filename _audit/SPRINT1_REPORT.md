# Sprint 1 Report: Security Hardening
> تاريخ: 2026-01-05

---

## ملخص الإنجازات

| المهمة | الحالة | الملفات |
|--------|--------|---------|
| 1.1 Auth/RBAC + Secrets | ✅ | `lib/api_auth.php` |
| 1.2 Input Validation | ✅ | `lib/validation.php` |
| 1.3 Rate Limit تعميم | ✅ | `v1/lib/rate_limit.php` |
| 1.4 Security Headers | ✅ | `lib/security_headers.php` |

---

## 1.1 Auth/RBAC + Secrets

### الملفات المُنشأة/المُعدلة

| الملف | الوصف |
|-------|-------|
| `forge.op-tg.com/lib/api_auth.php` | **جديد** - Auth موحد مع RBAC |
| `forge.op-tg.com/v1/api/bootstrap_api.php` | **معدل** - يستخدم api_auth |
| `forge.op-tg.com/v1/api/campaigns/create.php` | **معدل** - مثال تطبيقي |

### الأدوار المدعومة

```php
// lib/api_auth.php:24-28
define('ROLE_HIERARCHY', [
    'sales' => 1,
    'agent' => 1,  // alias for sales
    'supervisor' => 2,
    'admin' => 3,
]);
```

### الدوال الرئيسية

| الدالة | الوظيفة |
|--------|---------|
| `get_api_user()` | جلب المستخدم من أي طريقة auth |
| `require_api_user()` | يتطلب مصادقة (401 إذا فشل) |
| `require_min_role($role)` | يتطلب دور معين (403 إذا فشل) |
| `has_role($user, $role)` | فحص الدور |
| `can_access_resource($user, $ownerId)` | فحص الوصول للمورد |
| `get_secret($name)` | جلب سر من ENV فقط |
| `mask_secret($secret)` | إخفاء السر للـ logs |
| `safe_log($msg)` | logging آمن بدون أسرار |

### Evidence: اختبار 401

```bash
curl -i -X POST "http://localhost:8081/v1/api/campaigns/create.php" \
  -H "Origin: http://localhost:3000" \
  -H "Content-Type: application/json" \
  -d "{}"
```

**النتيجة:**
```
HTTP/1.1 401 Unauthorized
{"ok":false,"error":"UNAUTHORIZED","message":"Authentication required"}
```

### حماية المفاتيح

```php
// lib/api_auth.php - safe_log()
// يزيل تلقائياً:
// - API keys (AIza..., sk-...)
// - Bearer tokens
// - أي حقل يحتوي: password, token, secret, key, api_key, auth
```

---

## 1.2 Input Validation + Limits

### الملف المُنشأ

`forge.op-tg.com/lib/validation.php`

### الميزات

| الميزة | الوصف |
|--------|-------|
| `check_payload_size()` | حد أقصى 1MB للـ payload |
| `parse_json_input()` | تحليل JSON مع معالجة الأخطاء |
| `validate_schema()` | validation شامل بـ schema |
| `validate_required()` | فحص الحقول المطلوبة |
| `validate_id()` | فحص ID (int أو UUID) |

### أنواع البيانات المدعومة

```php
// validation.php - validate_type()
'string', 'int', 'float', 'bool', 'array', 'email', 'phone', 'url'
```

### مثال Schema

```php
// v1/api/campaigns/create.php:42-50
$validated = validate_schema($input, [
    'name' => ['type' => 'string', 'required' => true, 'min' => 2, 'max' => 100],
    'city' => ['type' => 'string', 'required' => true, 'min' => 2, 'max' => 50],
    'query' => ['type' => 'string', 'required' => false, 'max' => 200],
    'target' => ['type' => 'int', 'required' => false, 'min' => 1, 'max' => 1000, 'default' => 100],
]);
```

### Evidence: Validation Error

```bash
# طلب بدون الحقول المطلوبة (بعد auth)
# النتيجة المتوقعة:
HTTP/1.1 400 Bad Request
{"ok":false,"error":"VALIDATION_ERROR","message":"Input validation failed","errors":["Field 'name' is required","Field 'city' is required"]}
```

---

## 1.3 Rate Limit تعميم + Cleanup

### الملف المُعدل

`forge.op-tg.com/v1/lib/rate_limit.php`

### التحسينات

| الميزة | الوصف |
|--------|-------|
| `ensure_rate_limit_table()` | إنشاء الجدول مرة واحدة |
| `rate_limit_cleanup()` | تنظيف تلقائي (1 من كل 100 طلب) |
| Index على `window_start` | تحسين أداء الاستعلامات |

### الدوال المُعدة مسبقاً

```php
// v1/lib/rate_limit.php:160-205
rate_limit_whatsapp($pdo, $userId)  // 30/min
rate_limit_jobs($pdo, $userId)      // 10/min
rate_limit_login($pdo)              // 5/15min per IP
rate_limit_api($pdo, $userId)       // 100/min
rate_limit_search($pdo, $userId)    // 20/min
```

### Evidence: Rate Limit على campaigns/create

```php
// v1/api/campaigns/create.php:37
rate_limit_jobs($pdo, $user['id']);
```

**اختبار (بعد تجاوز الحد):**
```
HTTP/1.1 429 Too Many Requests
Retry-After: 45
{"error":"Too Many Requests","message":"تم تجاوز الحد المسموح من الطلبات","retry_after_seconds":45}
```

---

## 1.4 Security Headers

### الملف المُنشأ

`forge.op-tg.com/lib/security_headers.php`

### Headers المُطبقة

| Header | القيمة |
|--------|--------|
| `X-Frame-Options` | DENY |
| `X-Content-Type-Options` | nosniff |
| `X-XSS-Protection` | 1; mode=block |
| `Referrer-Policy` | strict-origin-when-cross-origin |
| `Permissions-Policy` | geolocation=(), microphone=(), camera=() |
| `HSTS` | (production only) |

### Evidence: Headers في Response

```bash
curl -i -X POST "http://localhost:8081/v1/api/campaigns/create.php" ...
```

**النتيجة:**
```
X-XSS-Protection: 1; mode=block
X-Content-Type-Options: nosniff
X-Frame-Options: SAMEORIGIN
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: geolocation=(), microphone=(), camera=()
```

### قرار CSRF

**القرار: CSRF غير مطلوب**

**السبب:**
- Auth عبر Bearer token في Authorization header (ليس cookies)
- Session cookies تستخدم SameSite=Lax
- CORS Allowlist يمنع requests من origins غير مصرح بها

**الدليل:**
```php
// lib/auth.php:36-48 - Bearer token check
// lib/auth.php:6-13 - SameSite=Lax cookies
// lib/cors.php - CORS Allowlist
```

---

## Git Commits

```
8166dcc fix: rename require_role to require_min_role to avoid conflict
c55a544 feat(sprint1): Auth/RBAC + Validation + Rate Limit + Security Headers
fbe7552 fix(security): CORS allowlist + server-side rate limiting - Critical #2 & #3
23dd0e3 chore: init repo for merged workspace (frontend+backend) - baseline before security fixes
```

---

## الملفات المُنشأة في Sprint 1

| الملف | الحجم | الوظيفة |
|-------|-------|---------|
| `lib/api_auth.php` | 6.5KB | Auth موحد + RBAC + Secrets |
| `lib/validation.php` | 8.2KB | Input validation + Schema |
| `lib/security_headers.php` | 4.1KB | Security headers + XSS |
| `lib/cors.php` | 1.8KB | CORS Allowlist (Sprint 0) |
| `v1/lib/rate_limit.php` | 6.0KB | Rate limiting + Cleanup |

---

## الخطوة التالية: Sprint 2

**Core Flow:**
- Jobs + Leads + Evidence
- Reports + Google Search provider
- LLM report generation

---

> **آخر تحديث**: 2026-01-05 20:45 UTC+3
