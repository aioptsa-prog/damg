# SECURITY_REVIEW.md
> مراجعة الأمان الشاملة
> تاريخ الإنشاء: 2026-01-05

---

## 1. ملخص تنفيذي

| الفئة | الحالة | الملاحظات |
|-------|--------|----------|
| **Authentication** | ⚠️ جيد مع ملاحظات | JWT + bcrypt، يحتاج تحسين Rate Limiting |
| **Authorization** | ✅ جيد | RBAC مطبق بشكل صحيح |
| **Data Protection** | ⚠️ متوسط | تشفير موجود، يحتاج مراجعة |
| **Input Validation** | ⚠️ متوسط | Zod في OP-Target، ضعيف في Forge |
| **CORS** | ❌ ضعيف | مفتوح في Forge |
| **CSRF** | ⚠️ جزئي | موجود في Forge، غائب في OP-Target |
| **Rate Limiting** | ❌ ضعيف | Client-side في OP-Target |

---

## 2. Authentication (المصادقة)

### 2.1 OP-Target-Sales-Hub-1

#### الآلية
| البند | القيمة | الدليل |
|-------|--------|--------|
| **Algorithm** | JWT HMAC-SHA256 | `api/auth.ts:38` |
| **Password Hashing** | bcrypt | `api/auth.ts:208` |
| **Token Storage** | HttpOnly Cookie | `api/auth.ts:229-231` |
| **Token Expiry** | 24 hours | `api/auth.ts:29` |

#### نقاط القوة ✅
- HMAC-SHA256 توقيع صحيح
- bcrypt لتشفير كلمات المرور
- HttpOnly cookie يمنع XSS
- SameSite=Strict يمنع CSRF جزئياً

#### نقاط الضعف ❌
- **Issue**: Rate limiting في الذاكرة فقط
  - File: `api/auth.ts:46`
  - Symbol: `loginAttempts: Map`
  - Impact: يُفقد عند إعادة التشغيل
  - Fix: استخدام Redis أو Database

### 2.2 forge.op-tg.com

#### الآلية
| البند | القيمة | الدليل |
|-------|--------|--------|
| **Session** | PHP Session | `lib/auth.php:51-55` |
| **Token** | SHA256 hash | `lib/auth.php:91` |
| **Remember** | Cookie + DB | `lib/auth.php:101-104` |
| **Worker Auth** | HMAC-SHA256 | `lib/security.php:19-26` |

#### نقاط القوة ✅
- Session regeneration عند Login
- HttpOnly cookies
- HMAC للـ Worker authentication
- Replay protection

#### نقاط الضعف ❌
- **Issue**: لا يوجد password policy
  - Impact: كلمات مرور ضعيفة ممكنة
  - Fix: إضافة validation للقوة

---

## 3. Authorization (الصلاحيات)

### 3.1 OP-Target RBAC

| الدور | الصلاحيات | الدليل |
|-------|----------|--------|
| SUPER_ADMIN | كل شيء | `api/_auth.ts:117` |
| MANAGER | فريقه فقط | `api/_auth.ts:137-144` |
| SALES_REP | عملاؤه فقط | `api/_auth.ts:133-134` |

**التقييم**: ✅ جيد - RBAC مطبق بشكل صحيح

### 3.2 Forge Roles

| الدور | الصلاحيات | الدليل |
|-------|----------|--------|
| admin | كل شيء | `lib/auth.php:142-152` |
| agent | محدود | نفس الملف |

**التقييم**: ✅ جيد - فصل واضح للأدوار

---

## 4. OWASP Top 10 Analysis

### A01: Broken Access Control

| الفحص | OP-Target | Forge |
|-------|-----------|-------|
| RBAC | ✅ | ✅ |
| Resource-level access | ✅ | ✅ |
| CORS | ⚠️ | ❌ |

**Forge CORS Issue**:
- File: `v1/api/whatsapp/send.php:7`
- Code: `header('Access-Control-Allow-Origin: *');`
- Fix: تقييد للـ origins المسموحة

### A02: Cryptographic Failures

| الفحص | OP-Target | Forge |
|-------|-----------|-------|
| Password hashing | ✅ bcrypt | ✅ password_hash |
| Token signing | ✅ HMAC-SHA256 | ✅ HMAC-SHA256 |
| Secrets in env | ✅ | ✅ |
| HTTPS | ⚠️ Production only | ⚠️ Production only |

**ملاحظة**: `.env` يحتوي أسرار - تأكد من عدم commit

### A03: Injection

| الفحص | OP-Target | Forge |
|-------|-----------|-------|
| SQL Injection | ✅ Parameterized | ✅ Prepared Statements |
| XSS | ⚠️ React escapes | ⚠️ يحتاج مراجعة |
| Command Injection | ✅ لا يوجد | ✅ لا يوجد |

**OP-Target SQL**:
```typescript
// api/leads.ts:103 - آمن
const saveRes = await query(insertQuery, values);
```

**Forge SQL**:
```php
// lib/auth.php:78 - آمن
$st = db()->prepare("SELECT * FROM users WHERE mobile=?");
$st->execute([$mobile]);
```

### A04: Insecure Design

| الفحص | الحالة |
|-------|--------|
| Rate Limiting | ❌ ضعيف |
| Input Validation | ⚠️ جزئي |
| Error Handling | ⚠️ يحتاج تحسين |

### A05: Security Misconfiguration

| الفحص | OP-Target | Forge |
|-------|-----------|-------|
| Debug mode | ⚠️ تحقق | ✅ معطل |
| Default credentials | ⚠️ تحقق | ⚠️ تحقق |
| Security headers | ❌ غائبة | ⚠️ جزئية |

**Headers المطلوبة**:
```
Content-Security-Policy: default-src 'self'
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Strict-Transport-Security: max-age=31536000
```

### A06: Vulnerable Components

| الفحص | الأمر | الحالة |
|-------|-------|--------|
| npm audit | `npm audit` | غير مؤكد |
| composer audit | N/A | لا يوجد composer |

**التوصية**: تشغيل `npm audit` دورياً

### A07: Authentication Failures

| الفحص | OP-Target | Forge |
|-------|-----------|-------|
| Brute force protection | ❌ ضعيف | ✅ موجود |
| Session management | ✅ | ✅ |
| Password policy | ❌ غائب | ❌ غائب |

### A08: Software Integrity Failures

| الفحص | الحالة |
|-------|--------|
| Dependency verification | ⚠️ package-lock موجود |
| CI/CD security | غير مؤكد |

### A09: Logging Failures

| الفحص | OP-Target | Forge |
|-------|-----------|-------|
| Auth logging | ✅ audit_logs | ✅ audit_logs |
| Error logging | ⚠️ console.error | ✅ error_log |
| Sensitive data in logs | ⚠️ تحقق | ⚠️ تحقق |

### A10: SSRF

| الفحص | الحالة |
|-------|--------|
| External URL validation | ⚠️ يحتاج مراجعة |
| Allowlist for external calls | ❌ غير موجود |

---

## 5. Secrets Management

### 5.1 الأسرار المكتشفة

| الملف | المحتوى | الحالة |
|-------|---------|--------|
| `OP-Target/.env` | JWT_SECRET, DATABASE_URL | ⚠️ يحتوي أسرار |
| `OP-Target/.env.local` | إعدادات محلية | ⚠️ يحتوي أسرار |
| `forge/.env` | INTERNAL_SECRET | ⚠️ يحتوي أسرار |
| `forge/config/.env.php` | مسار DB فقط | ✅ آمن |

### 5.2 التوصيات

- [ ] تأكد من `.gitignore` يستثني `.env*`
- [ ] استخدم environment variables في Production
- [ ] لا تـ commit أسرار أبداً
- [ ] دوّر الأسرار دورياً

---

## 6. Rate Limiting

### 6.1 الحالة الحالية

| Endpoint | OP-Target | Forge |
|----------|-----------|-------|
| Login | ❌ Client-side | ✅ Server-side |
| API General | ❌ لا يوجد | ⚠️ جزئي |
| WhatsApp | ❌ Client-side | ⚠️ جزئي |

### 6.2 التوصيات

```typescript
// OP-Target: استخدام Database
const checkRateLimit = async (key: string, limit: number, windowSec: number) => {
  const result = await query(`
    SELECT COUNT(*) as count FROM rate_limits 
    WHERE key = $1 AND created_at > NOW() - INTERVAL '${windowSec} seconds'
  `, [key]);
  return result.rows[0].count < limit;
};
```

---

## 7. CSRF Protection

### 7.1 الحالة الحالية

| المشروع | الحالة | الدليل |
|---------|--------|--------|
| OP-Target | ⚠️ SameSite فقط | `api/auth.ts:230` |
| Forge | ✅ Token-based | `lib/csrf.php` |

### 7.2 OP-Target Fix

```typescript
// إضافة CSRF token
function generateCsrfToken(): string {
  return crypto.randomBytes(32).toString('hex');
}

// التحقق في كل POST/PUT/DELETE
function verifyCsrfToken(req: any): boolean {
  const token = req.headers['x-csrf-token'];
  const cookie = req.cookies['csrf_token'];
  return token && cookie && token === cookie;
}
```

---

## 8. File Upload Security

### 8.1 الفحص

| الفحص | OP-Target | Forge |
|-------|-----------|-------|
| File type validation | غير مؤكد | غير مؤكد |
| File size limit | غير مؤكد | غير مؤكد |
| Malware scanning | ❌ | ❌ |
| Secure storage path | غير مؤكد | ✅ storage/ |

### 8.2 التوصيات

- [ ] تحقق من نوع الملف (MIME + extension)
- [ ] حدد حجم أقصى (مثلاً 10MB)
- [ ] خزّن خارج webroot
- [ ] أعد تسمية الملفات

---

## 9. خطة الإصلاح

### الأولوية القصوى (هذا الأسبوع)

1. **إصلاح CORS في Forge**
   ```php
   $allowed = ['https://your-domain.com'];
   $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
   if (in_array($origin, $allowed)) {
       header('Access-Control-Allow-Origin: ' . $origin);
   }
   ```

2. **نقل Rate Limiting للـ Server**
   - استخدام Database بدلاً من Map

3. **إضافة Security Headers**
   ```php
   header('X-Content-Type-Options: nosniff');
   header('X-Frame-Options: DENY');
   ```

### الأولوية العالية (الأسبوع القادم)

4. إضافة CSRF protection لـ OP-Target
5. إضافة Password policy
6. تشغيل `npm audit --fix`

### الأولوية المتوسطة

7. إضافة Input validation شامل
8. تحسين Error handling
9. إضافة Security logging

---

## 10. Verification Commands

```powershell
# فحص npm vulnerabilities
cd d:\projects\دمج\OP-Target-Sales-Hub-1
npm audit

# اختبار CORS
curl -H "Origin: https://evil.com" http://localhost:8081/v1/api/whatsapp/send.php -v

# اختبار Rate Limiting
for ($i=1; $i -le 10; $i++) { 
  Invoke-WebRequest -Uri "http://localhost:3000/api/auth" -Method POST -Body '{"email":"test@test.com","password":"wrong"}' -ContentType "application/json"
}

# فحص Headers
curl -I http://localhost:3000
```

---

> **آخر تحديث**: 2026-01-05 19:56 UTC+3
