# 02_SECURITY_REVIEW - مراجعة الأمان

**تاريخ التدقيق:** 2026-01-03  
**المنهجية:** Code review + OWASP Top 10 mapping

---

## 🔐 1. Authentication Model

### 1.1 Login Flow

**المصدر:** `api/auth.ts`

```
User → POST /api/auth (email, password)
     → Server validates with bcrypt.compare()
     → Generate JWT (24h expiry)
     → Set httpOnly cookie
     → Return user (no password_hash)
```

**✅ إيجابيات:**
- bcrypt للتحقق من كلمة المرور (`api/auth.ts:120`)
- Generic error message لا يكشف وجود الإيميل (`api/auth.ts:96`)
- Audit log لمحاولات الدخول الفاشلة (`api/auth.ts:99-103`)

**⚠️ سلبيات:**
- لا يوجد password strength validation (فقط min 8 chars في change-password)

### 1.2 Cookie Configuration

**المصدر:** `api/auth.ts:141-143`

```typescript
`auth_token=${token}; HttpOnly; ${isProduction ? 'Secure;' : ''} SameSite=Strict; Path=/; Max-Age=${24 * 60 * 60}`
```

| Flag | القيمة | الحالة |
|------|--------|--------|
| HttpOnly | ✅ Yes | يمنع XSS من قراءة الـ token |
| Secure | ⚠️ Production only | HTTPS فقط في production |
| SameSite | Strict | يمنع CSRF |
| Max-Age | 86400 (24h) | ✅ معقول |
| Path | / | ✅ صحيح |

### 1.3 JWT Implementation

**المصدر:** `api/auth.ts:14-37` و `api/_auth.ts:22-52`

**🔴 مشكلة حرجة (P0):**

```typescript
// api/_auth.ts:41-46
const signatureInput = `${parts[0]}.${parts[1]}`;
const expectedSignature = btoa(signatureInput + secret).replace(/[+/=]/g, '');
```

**المشكلة:** هذا ليس HMAC-SHA256 حقيقي. إنه Base64 concatenation فقط.

**الأثر:** Token forgery ممكن نظرياً إذا تم تسريب الـ secret.

**الحل:**
```typescript
import { createHmac } from 'crypto';
const signature = createHmac('sha256', secret)
  .update(signatureInput)
  .digest('base64url');
```

### 1.4 Logout

**المصدر:** `api/logout.ts`

- ✅ يمسح الـ cookie
- ✅ يسجل audit log
- ⚠️ لا يوجد token blacklist (الـ token يبقى صالحاً حتى انتهاء صلاحيته)

### 1.5 mustChangePassword

**المصدر:** `api/auth.ts:130-131`

```typescript
const mustChangePassword = user.mustChangePassword || false;
const token = generateToken(user.id, user.role, mustChangePassword);
```

**🔴 مشكلة (P0):**
- الـ flag موجود في JWT payload (`mcp`)
- **لكن Frontend لا يفرضه!**
- `App.tsx` و `Login.tsx` لا يتحققان من `mustChangePassword`
- المستخدم يمكنه تجاوز تغيير كلمة المرور

**الحل:** إضافة check في `App.tsx`:
```typescript
if (currentUser?.mustChangePassword) {
  return <ChangePasswordScreen />;
}
```

---

## 👥 2. RBAC / IDOR

### 2.1 Role Hierarchy

| Role | Leads | Users | Settings | Analytics |
|------|-------|-------|----------|-----------|
| SUPER_ADMIN | All | All | ✅ | All |
| MANAGER | Team | ❌ | ❌ | Team |
| SALES_REP | Own | ❌ | ❌ | Own |

### 2.2 RBAC Implementation

**المصدر:** `api/_auth.ts`

| Function | Purpose | Used In |
|----------|---------|---------|
| `requireAuth()` | 401 if not logged in | All endpoints |
| `requireRole()` | 403 if wrong role | users, settings, logs |
| `canAccessLead()` | IDOR check for leads | leads, reports, tasks |
| `canAccessUser()` | IDOR check for users | users |

**✅ تغطية كاملة:**
- كل الـ 16 endpoints تستخدم `requireAuth` أو `requireRole`
- IDOR protection على leads, reports, tasks, activities

### 2.3 IDOR Verification

**المصدر:** `api/_auth.ts:111-147`

```typescript
export async function canAccessLead(user: AuthUser, leadId: string): Promise<boolean> {
    if (user.role === 'SUPER_ADMIN') return true;
    
    // SALES_REP: own leads only
    if (user.role === 'SALES_REP') {
        return lead.owner_user_id === user.id;
    }
    
    // MANAGER: team leads
    if (user.role === 'MANAGER') {
        return lead.team_id === teamResult.rows[0].team_id;
    }
}
```

**✅ صحيح:** التحقق يتم على مستوى الـ database، ليس client-side.

---

## 🌱 3. Seed Endpoint Policy

**المصدر:** `api/seed.ts`

### Current Implementation:

```typescript
// Requires SEED_SECRET
if (providedSecret !== seedSecret) {
    return res.status(403).json({ error: 'Invalid seed secret' });
}

// Only creates if no admin exists
const existingAdmin = await query(
    "SELECT id FROM users WHERE role = 'SUPER_ADMIN' LIMIT 1"
);
if (existingAdmin.rows.length > 0) {
    return { created: false, message: 'SUPER_ADMIN already exists' };
}
```

**✅ إيجابيات:**
- محمي بـ SEED_SECRET
- لا يُنشئ admin إذا موجود

**🔴 مشكلة (P0):**
- **لا يوجد production guard!**
- الـ endpoint متاح في production
- يمكن brute-force الـ SEED_SECRET

**الحل المطلوب:**
```typescript
if (process.env.NODE_ENV === 'production') {
    return res.status(403).json({ error: 'Seed disabled in production' });
}
```

---

## 🔑 4. Password Hashing

### 4.1 Hashing

**المصدر:** `api/seed.ts:42`, `api/reset-password.ts:58`, `api/change-password.ts:55`

```typescript
const BCRYPT_ROUNDS = 10;
const passwordHash = await bcrypt.hash(password, BCRYPT_ROUNDS);
```

**✅ صحيح:** bcrypt مع 10 rounds (معقول)

### 4.2 Comparison

**المصدر:** `api/auth.ts:120`

```typescript
const isValid = await bcrypt.compare(password, user.passwordHash);
```

**✅ صحيح:** bcrypt.compare للتحقق

### 4.3 Reset Flow

**المصدر:** `api/reset-password.ts`

- ✅ Admin only (`requireRole(['SUPER_ADMIN'])`)
- ✅ Sets `must_change_password = true`
- ✅ Generates random 12-char password
- ✅ Audit log

### 4.4 Change Flow

**المصدر:** `api/change-password.ts`

- ✅ Requires current password
- ✅ Min 8 chars validation
- ✅ Clears `must_change_password`
- ✅ Audit log

**⚠️ ملاحظة:** لا يوجد password strength validation (uppercase, numbers, symbols)

---

## 🔒 5. Secrets Management

| Secret | Storage | Exposure Risk |
|--------|---------|---------------|
| DATABASE_URL | ENV | ✅ Safe |
| JWT_SECRET | ENV | ✅ Safe |
| ENCRYPTION_SECRET | ENV | ✅ Safe |
| SEED_SECRET | ENV | ✅ Safe |
| AI API Keys | Database (settings table) | ⚠️ Masked in response |

**المصدر:** `api/settings.ts:26-34`

```typescript
// Mask API keys - only show last 4 chars
if (settings.geminiApiKey) {
  settings.geminiApiKey = '***' + settings.geminiApiKey.slice(-4);
}
```

**✅ جيد:** API keys مخفية في الـ response

---

## 🛡️ 6. OWASP Top 10 Coverage

| # | Vulnerability | Status | Evidence |
|---|---------------|--------|----------|
| A01 | Broken Access Control | ✅ Covered | RBAC + IDOR checks |
| A02 | Cryptographic Failures | ⚠️ Partial | bcrypt ✅, JWT signature ❌ |
| A03 | Injection | ✅ Covered | Parameterized queries |
| A04 | Insecure Design | ⚠️ Partial | mustChangePassword not enforced |
| A05 | Security Misconfiguration | ⚠️ Partial | No CORS, no CSP |
| A06 | Vulnerable Components | ✅ OK | 0 npm vulnerabilities |
| A07 | Auth Failures | ✅ Covered | Rate limiting, bcrypt |
| A08 | Data Integrity Failures | ⚠️ Partial | No input validation |
| A09 | Logging Failures | ✅ Covered | Audit logs exist |
| A10 | SSRF | ✅ N/A | No external URL fetching |

---

## 📋 ملخص المشاكل الأمنية

### P0 - Critical

| # | المشكلة | الملف | السطر |
|---|---------|-------|-------|
| 1 | Seed endpoint مفتوح في production | `api/seed.ts` | 65-88 |
| 2 | JWT signature ضعيف (Base64 not HMAC) | `api/_auth.ts` | 41-46 |
| 3 | mustChangePassword غير مُطبق في Frontend | `App.tsx` | - |

### P1 - High

| # | المشكلة | الملف |
|---|---------|-------|
| 4 | لا يوجد input validation | All API endpoints |
| 5 | Rate limit في memory فقط | `api/auth.ts` |
| 6 | لا يوجد CORS configuration | `vite.config.ts` |
| 7 | لا يوجد CSP headers | - |
| 8 | Password strength validation ضعيف | `api/change-password.ts` |

### P2 - Medium

| # | المشكلة | الملف |
|---|---------|-------|
| 9 | لا يوجد token blacklist | - |
| 10 | bcrypt rounds hardcoded | `api/auth.ts` |
| 11 | Encryption service ضعيف | `services/encryptionService.ts` |
