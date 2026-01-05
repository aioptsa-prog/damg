# 07_BACKLOG_AND_PLAN - قائمة المهام وخطة التنفيذ

**تاريخ التدقيق:** 2026-01-03  
**المنهجية:** Fresh Audit findings → Prioritized backlog

---

## 🚨 P0 - Blockers للإنتاج

### P0-1: إضافة Production Guard لـ Seed Endpoint

| البند | القيمة |
|-------|--------|
| **الوصف** | الـ `/api/seed` endpoint متاح في production ويمكن brute-force الـ SEED_SECRET |
| **المكان** | `api/seed.ts:65-88` |
| **معيار النجاح** | الـ endpoint يرجع 403 في production |
| **تقدير الوقت** | 15 دقيقة |
| **المخاطر** | لا يوجد - تغيير بسيط |
| **Rollback** | حذف الـ check |

**الحل:**
```typescript
// api/seed.ts - أول سطر في handler
if (process.env.NODE_ENV === 'production') {
  return res.status(403).json({ 
    error: 'Seed disabled in production',
    message: 'هذا الـ endpoint معطل في بيئة الإنتاج'
  });
}
```

---

### P0-2: إصلاح JWT Signature

| البند | القيمة |
|-------|--------|
| **الوصف** | الـ JWT signature يستخدم Base64 concatenation بدل HMAC-SHA256 |
| **المكان** | `api/_auth.ts:41-46` و `api/auth.ts:30-34` |
| **معيار النجاح** | الـ signature يستخدم crypto.createHmac |
| **تقدير الوقت** | 1 ساعة |
| **المخاطر** | كل الـ sessions الحالية ستنتهي صلاحيتها |
| **Rollback** | الرجوع للـ implementation القديم |

**الحل:**
```typescript
// api/auth.ts - generateToken function
import { createHmac } from 'crypto';

function generateToken(userId: string, role: string, mustChangePassword: boolean = false): string {
  const header = { alg: 'HS256', typ: 'JWT' };
  const payload = { sub: userId, role, mcp: mustChangePassword, iat: now, exp: now + 86400 };
  
  const base64Header = Buffer.from(JSON.stringify(header)).toString('base64url');
  const base64Payload = Buffer.from(JSON.stringify(payload)).toString('base64url');
  
  const signature = createHmac('sha256', secret)
    .update(`${base64Header}.${base64Payload}`)
    .digest('base64url');
  
  return `${base64Header}.${base64Payload}.${signature}`;
}
```

---

### P0-3: تطبيق mustChangePassword في Frontend

| البند | القيمة |
|-------|--------|
| **الوصف** | الـ Frontend لا يفرض تغيير كلمة المرور عند `mustChangePassword = true` |
| **المكان** | `App.tsx:88-90` |
| **معيار النجاح** | المستخدم يُجبر على تغيير كلمة المرور قبل الوصول للنظام |
| **تقدير الوقت** | 2 ساعة |
| **المخاطر** | يحتاج إنشاء component جديد |
| **Rollback** | حذف الـ check |

**الحل:**
```typescript
// App.tsx - بعد check الـ authentication
if (currentUser.mustChangePassword) {
  return <ForceChangePassword 
    user={currentUser}
    onSuccess={(updatedUser) => {
      setCurrentUser(updatedUser);
      showToast('تم تغيير كلمة المرور بنجاح', 'success');
    }}
  />;
}
```

---

## ⚠️ P1 - استقرار وأمان إضافي

### P1-1: إضافة Input Validation (Zod)

| البند | القيمة |
|-------|--------|
| **الوصف** | لا يوجد validation على الـ API inputs |
| **المكان** | كل الـ API endpoints |
| **معيار النجاح** | كل endpoint يتحقق من الـ input قبل المعالجة |
| **تقدير الوقت** | 4 ساعات |
| **المخاطر** | قد يكسر clients موجودين إذا كانوا يرسلون data غير صحيحة |
| **Rollback** | حذف الـ validation |

**الحل:**
```typescript
// api/schemas.ts
import { z } from 'zod';

export const loginSchema = z.object({
  email: z.string().email(),
  password: z.string().min(1)
});

export const leadSchema = z.object({
  companyName: z.string().min(1).max(255),
  activity: z.string().optional(),
  status: z.enum(['NEW', 'CONTACTED', 'FOLLOW_UP', 'INTERESTED', 'WON', 'LOST']).optional(),
  // ...
});

// api/auth.ts
const parsed = loginSchema.safeParse(req.body);
if (!parsed.success) {
  return res.status(400).json({ error: 'Invalid input', details: parsed.error.issues });
}
```

---

### P1-2: Rate Limiting مع Redis

| البند | القيمة |
|-------|--------|
| **الوصف** | الـ rate limiting يُفقد عند restart لأنه في memory |
| **المكان** | `api/auth.ts:40-60` |
| **معيار النجاح** | الـ rate limits تبقى بعد restart |
| **تقدير الوقت** | 2 ساعة |
| **المخاطر** | يحتاج Redis instance |
| **Rollback** | الرجوع لـ in-memory |

**الحل البسيط (بدون Redis):**
```typescript
// استخدام Upstash Redis (serverless)
import { Ratelimit } from '@upstash/ratelimit';
import { Redis } from '@upstash/redis';

const ratelimit = new Ratelimit({
  redis: Redis.fromEnv(),
  limiter: Ratelimit.slidingWindow(5, '15 m'),
});
```

---

### P1-3: إضافة CORS Configuration

| البند | القيمة |
|-------|--------|
| **الوصف** | لا يوجد CORS headers محددة |
| **المكان** | `vite.config.ts` أو API middleware |
| **معيار النجاح** | CORS headers موجودة ومحددة |
| **تقدير الوقت** | 30 دقيقة |
| **المخاطر** | قد يمنع requests من domains مسموحة |
| **Rollback** | حذف الـ CORS config |

---

### P1-4: إضافة Database Indexes

| البند | القيمة |
|-------|--------|
| **الوصف** | لا يوجد indexes على الـ columns المستخدمة في WHERE/ORDER BY |
| **المكان** | Neon PostgreSQL |
| **معيار النجاح** | Query performance محسّن |
| **تقدير الوقت** | 15 دقيقة |
| **المخاطر** | لا يوجد |
| **Rollback** | DROP INDEX |

**الحل:**
```sql
CREATE INDEX CONCURRENTLY idx_leads_owner ON leads(owner_user_id);
CREATE INDEX CONCURRENTLY idx_leads_team ON leads(team_id);
CREATE INDEX CONCURRENTLY idx_leads_status ON leads(status);
CREATE INDEX CONCURRENTLY idx_leads_created ON leads(created_at DESC);
CREATE INDEX CONCURRENTLY idx_activities_lead ON activities(lead_id);
CREATE INDEX CONCURRENTLY idx_tasks_lead ON tasks(lead_id);
```

---

### P1-5: استخدام RoleGuard في Frontend

| البند | القيمة |
|-------|--------|
| **الوصف** | `RoleGuard.tsx` موجود لكن غير مستخدم |
| **المكان** | `App.tsx` |
| **معيار النجاح** | الـ pages المحمية تستخدم RoleGuard |
| **تقدير الوقت** | 30 دقيقة |
| **المخاطر** | لا يوجد |
| **Rollback** | حذف الـ RoleGuard usage |

---

### P1-6: Password Strength Validation

| البند | القيمة |
|-------|--------|
| **الوصف** | فقط min 8 chars، لا يوجد complexity requirements |
| **المكان** | `api/change-password.ts:31-32` |
| **معيار النجاح** | كلمة المرور تحتوي على uppercase, lowercase, number |
| **تقدير الوقت** | 30 دقيقة |
| **المخاطر** | قد يرفض كلمات مرور موجودة |
| **Rollback** | الرجوع لـ min 8 فقط |

---

## 📈 P2 - تحسينات

### P2-1: Code Splitting

| البند | القيمة |
|-------|--------|
| **الوصف** | Bundle size كبير (984KB) |
| **المكان** | `App.tsx` |
| **معيار النجاح** | Initial bundle < 500KB |
| **تقدير الوقت** | 2 ساعة |

---

### P2-2: API Pagination

| البند | القيمة |
|-------|--------|
| **الوصف** | كل الـ GET endpoints تجلب كل البيانات |
| **المكان** | `api/leads.ts`, `api/tasks.ts`, etc. |
| **معيار النجاح** | كل endpoint يدعم limit/offset |
| **تقدير الوقت** | 3 ساعات |

---

### P2-3: استبدال alert() بـ Toast

| البند | القيمة |
|-------|--------|
| **الوصف** | `UserManagement.tsx` يستخدم `alert()` |
| **المكان** | `components/UserManagement.tsx:67, 75` |
| **معيار النجاح** | كل الـ notifications تستخدم Toast |
| **تقدير الوقت** | 15 دقيقة |

---

### P2-4: Tables Responsive

| البند | القيمة |
|-------|--------|
| **الوصف** | Tables تتجاوز الشاشة على mobile |
| **المكان** | `UserManagement.tsx`, `LeadList.tsx` |
| **معيار النجاح** | Tables scrollable على mobile |
| **تقدير الوقت** | 30 دقيقة |

---

### P2-5: Integration Tests

| البند | القيمة |
|-------|--------|
| **الوصف** | Test coverage ضعيف (2 tests فقط) |
| **المكان** | `tests/` |
| **معيار النجاح** | Coverage > 60% |
| **تقدير الوقت** | 8 ساعات |

---

### P2-6: Local TailwindCSS

| البند | القيمة |
|-------|--------|
| **الوصف** | TailwindCSS يُحمّل من CDN |
| **المكان** | `index.html:12` |
| **معيار النجاح** | TailwindCSS مُدمج في الـ build |
| **تقدير الوقت** | 1 ساعة |

---

## 📅 خطة التنفيذ المقترحة

### Sprint 1 (أسبوع 1) - Security Critical

| # | المهمة | الوقت | المسؤول |
|---|--------|-------|---------|
| 1 | P0-1: Production seed guard | 15 min | Backend |
| 2 | P0-2: Fix JWT signature | 1 hr | Backend |
| 3 | P0-3: mustChangePassword frontend | 2 hr | Frontend |
| 4 | P1-4: Database indexes | 15 min | DBA |

**المجموع:** ~4 ساعات

---

### Sprint 2 (أسبوع 2) - Stability

| # | المهمة | الوقت | المسؤول |
|---|--------|-------|---------|
| 1 | P1-1: Input validation (Zod) | 4 hr | Backend |
| 2 | P1-3: CORS configuration | 30 min | Backend |
| 3 | P1-5: RoleGuard usage | 30 min | Frontend |
| 4 | P1-6: Password strength | 30 min | Backend |

**المجموع:** ~6 ساعات

---

### Sprint 3 (أسبوع 3) - Performance

| # | المهمة | الوقت | المسؤول |
|---|--------|-------|---------|
| 1 | P2-1: Code splitting | 2 hr | Frontend |
| 2 | P2-2: API pagination | 3 hr | Backend |
| 3 | P2-3: Replace alert() | 15 min | Frontend |
| 4 | P2-4: Tables responsive | 30 min | Frontend |

**المجموع:** ~6 ساعات

---

### Sprint 4 (أسبوع 4) - Quality

| # | المهمة | الوقت | المسؤول |
|---|--------|-------|---------|
| 1 | P1-2: Redis rate limiting | 2 hr | Backend |
| 2 | P2-5: Integration tests | 8 hr | QA |
| 3 | P2-6: Local TailwindCSS | 1 hr | Frontend |

**المجموع:** ~11 ساعات

---

## 📊 ملخص

| Priority | عدد المهام | الوقت الإجمالي |
|----------|-----------|----------------|
| P0 | 3 | ~4 ساعات |
| P1 | 6 | ~8 ساعات |
| P2 | 6 | ~15 ساعة |
| **المجموع** | **15** | **~27 ساعة** |

---

## ✅ Definition of Done

لكل مهمة:
1. ✅ الكود مكتوب ومراجع
2. ✅ Tests موجودة (إن أمكن)
3. ✅ Documentation محدّث
4. ✅ لا يوجد regressions
5. ✅ Deployed to staging
6. ✅ Smoke test passed
