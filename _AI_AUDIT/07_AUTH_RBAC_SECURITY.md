# 07_AUTH_RBAC_SECURITY - المصادقة والصلاحيات والأمان

## ما تم فحصه
- ✅ `services/authService.ts`
- ✅ `services/rateLimitService.ts`
- ✅ `services/encryptionService.ts`
- ✅ `components/Login.tsx`, `RoleGuard.tsx`
- ✅ جميع ملفات الـ API

---

## 🔐 آلية تسجيل الدخول

### التدفق الحالي:

```typescript
// services/authService.ts

1. المستخدم يدخل email + password
        │
        ▼
2. rateLimitService.check() ← 5 محاولات / 15 دقيقة
        │                      ⚠️ يعتمد على localStorage!
        ▼
3. db.getUsers() ← جلب كل المستخدمين من API
        │
        ▼
4. users.find(u => u.email === email)
        │
        ▼
5. password === 'admin123'? 🔴 كلمة مرور ثابتة!
        │
        ▼
6. localStorage.setItem(SESSION_KEY, JSON.stringify(user))
                              │
                              ▼
7. authService.currentUser = user ← حالة في الذاكرة
```

### مشاكل حرجة:

| المشكلة | الخطورة | الموقع |
|---------|---------|--------|
| **كلمة مرور ثابتة `admin123`** | 🔴 حرجة | `authService.ts:29` |
| **Session في localStorage** | 🔴 حرجة | `authService.ts:44` |
| **لا JWT/tokens** | 🔴 حرجة | - |
| **لا password hashing** | 🔴 حرجة | `types.ts:18` فيه `passwordHash` لكن لا يُستخدم |
| **لا session expiry** | 🔴 حرجة | الجلسة لا تنتهي أبداً |

---

## 👥 نظام الصلاحيات (RBAC)

### الأدوار المُعرّفة (`types.ts:2-6`):

```typescript
export enum UserRole {
  SUPER_ADMIN = 'SUPER_ADMIN',  // وصول كامل
  MANAGER = 'MANAGER',          // إدارة الفريق
  SALES_REP = 'SALES_REP'       // مندوب مبيعات
}
```

### التطبيق الحالي:

| الوظيفة | SUPER_ADMIN | MANAGER | SALES_REP | التحقق |
|---------|-------------|---------|-----------|--------|
| Dashboard | ✅ | ✅ | ✅ | Frontend |
| عملائي | ✅ | ✅ | ✅ | Frontend + API Query |
| كل العملاء | ✅ | ❌ | ❌ | Frontend فقط ⚠️ |
| إدارة المستخدمين | ✅ | ❌ | ❌ | Frontend فقط ⚠️ |
| الإعدادات | ✅ | ❌ | ❌ | Frontend فقط ⚠️ |

### الفجوة الأمنية:

```typescript
// App.tsx:108-111 - يتحقق على Frontend فقط
{ id: 'users', label: 'المستخدمين', icon: UserCog, adminOnly: true },
{ id: 'settings', label: 'الإعدادات', icon: Settings, adminOnly: true },

// لكن الـ API لا يتحقق!
// api/users.ts - أي شخص يمكنه الوصول
export default async function handler(req: any, res: any) {
  // ❌ لا يوجد التحقق من الصلاحيات
  const usersRes = await query('SELECT * FROM users');
  return res.status(200).json(usersRes.rows);
}
```

---

## 🛡️ فحص OWASP Top 10

### A01:2021 – Broken Access Control 🔴 فشل

| الثغرة | الحالة | الدليل |
|--------|--------|--------|
| IDOR (Insecure Direct Object Reference) | 🔴 موجود | أي userId في query string |
| Missing function-level access control | 🔴 موجود | API لا يتحقق من الأدوار |
| Metadata manipulation | 🔴 موجود | يمكن تغيير ownerUserId |

**مثال على الاستغلال:**
```bash
# أي مستخدم يمكنه جلب بيانات مستخدم آخر
curl 'http://app/api/leads?userId=admin_user_id'
```

---

### A02:2021 – Cryptographic Failures 🔴 فشل

| الثغرة | الحالة | الدليل |
|--------|--------|--------|
| Hardcoded secrets | 🔴 موجود | `encryptionService.ts:8` |
| Weak encryption (Base64) | 🔴 موجود | `encryptionService.ts:10-15` |
| Secrets in localStorage | 🔴 موجود | WhatsApp API Key |

**الكود المعيب:**
```typescript
// services/encryptionService.ts
private secret = "OP_TARGET_SERVER_VAULT_KEY_2024"; // 🔴 ثابت!

encrypt(text: string): string {
  // 🔴 هذا ليس تشفير - مجرد Base64!
  const buffer = new TextEncoder().encode(text + ":" + this.secret);
  const b64 = btoa(String.fromCharCode(...buffer));
  return `enc_v1:${b64}`;
}
```

---

### A03:2021 – Injection 🟡 جزئي

| الثغرة | الحالة | الدليل |
|--------|--------|--------|
| SQL Injection | 🟢 محمي | يستخدم parameterized queries |
| XSS | 🟡 محتمل | React يحمي افتراضياً لكن لا validation |
| NoSQL Injection | - | لا يُستخدم NoSQL |

---

### A07:2021 – Identification and Authentication Failures 🔴 فشل

| الثغرة | الحالة | الدليل |
|--------|--------|--------|
| Default credentials | 🔴 موجود | `admin123` لكل المستخدمين |
| Weak password policy | 🔴 موجود | لا policy أصلاً |
| Credential stuffing protection | 🔴 ضعيف | Rate limit على Client |
| Session fixation | 🔴 ممكن | لا تجديد session بعد الدخول |

---

## ⚡ Rate Limiting

### التنفيذ الحالي (`services/rateLimitService.ts`):

```typescript
// ⚠️ يعتمد على localStorage!
const CONFIGS = {
  LOGIN_ATTEMPT: { limit: 5, windowMs: 15 * 60 * 1000 },
  GENERATE_REPORT: { limit: 30, windowMs: 24 * 60 * 60 * 1000 },
  WHATSAPP_SEND: { limit: 100, windowMs: 24 * 60 * 60 * 1000 },
};

check(action, identifier) {
  const key = `rate_limit_${action}_${identifier}`;
  let history = JSON.parse(localStorage.getItem(key) || '[]');
  // ...
}
```

**المشكلة الحرجة:**
```javascript
// يمكن تجاوز كل حدود الاستخدام بسهولة:
localStorage.clear();
// أو فتح Incognito mode
```

---

## 📝 Audit Logs

### التنفيذ الحالي:

```typescript
// services/db.ts:156-158
addAuditLog(log: any) {
  this.fetchAPI('/logs/audit', { method: 'POST', body: JSON.stringify(log) });
}
```

**الأحداث المُسجلة:**
- LOGIN / LOGOUT
- LOGIN_FAILED
- UPDATE_SETTINGS
- UPDATE_WHATSAPP_CONFIG
- UPDATE_SHEETS_CONFIG

**الفجوات:**
- ❌ لا تسجيل لمحاولات IDOR
- ❌ لا تسجيل لعمليات CRUD على العملاء
- ❌ لا تسجيل لتوليد التقارير

---

## 🔒 Encryption (at rest / in transit)

| النوع | الحالة | التفاصيل |
|-------|--------|----------|
| **HTTPS** | ⚠️ غير مؤكد | يعتمد على بيئة النشر |
| **Database encryption** | ⚠️ غير مؤكد | Neon قد يوفر encryption |
| **API Keys encryption** | 🔴 وهمي | Base64 فقط |
| **Password hashing** | 🔴 غير موجود | `admin123` نص صريح |

---

## 🎯 ملخص تقييم الأمان

| المجال | الدرجة | التعليق |
|--------|--------|---------|
| Authentication | 1/10 | كلمة مرور ثابتة، لا JWT |
| Authorization | 2/10 | Frontend فقط، لا Backend |
| Session Management | 2/10 | localStorage، لا expiry |
| Rate Limiting | 2/10 | Client-side، قابل للتجاوز |
| Encryption | 1/10 | Base64 ليس تشفير |
| Audit Logging | 4/10 | موجود لكن ناقص |

**التقييم الإجمالي: 🔴 غير آمن للإنتاج**

---

## ✅ التوصيات العاجلة

1. **استبدال المصادقة بالكامل:**
   - bcrypt لتشفير كلمات المرور
   - JWT مع expiry
   - httpOnly cookies

2. **Authorization middleware للـ API:**
   ```typescript
   function requireRole(allowedRoles: UserRole[]) {
     return (req, res, next) => {
       const user = verifyJWT(req.cookies.token);
       if (!allowedRoles.includes(user.role)) {
         return res.status(403).json({ error: 'Forbidden' });
       }
       next();
     };
   }
   ```

3. **نقل Rate Limiting للـ Server:**
   - استخدام Redis
   - أو Upstash Rate Limit

4. **تشفير حقيقي:**
   - AES-256-GCM
   - Secret من environment variable
   - تشفير على Server فقط، ليس Client
