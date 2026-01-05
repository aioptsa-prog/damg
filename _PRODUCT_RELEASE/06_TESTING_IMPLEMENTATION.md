# Testing Implementation Guide

**تاريخ:** 2026-01-03  
**Sprint:** 2

---

## 🧪 نظرة عامة

### أنواع الاختبارات

| النوع | الأداة | المجلد | الغرض |
|-------|--------|--------|-------|
| **Unit** | Vitest | `tests/unit/` | اختبار functions منفردة |
| **E2E** | Playwright | `tests/e2e/` | اختبار flows كاملة |
| **Integration** | Vitest | `tests/integration/` | (معطل حالياً) |

---

## 🔧 الإعداد

### المتطلبات
- Node.js 20.x
- npm

### التثبيت
```powershell
# تثبيت dependencies
npm install

# تثبيت Playwright browsers (للـ E2E)
npx playwright install chromium
```

---

## 📋 أوامر التشغيل (Windows PowerShell)

### Unit Tests (Vitest)
```powershell
# تشغيل جميع unit tests
npm run test

# تشغيل مع watch mode
npm run test:watch

# تشغيل مع coverage
npm run test -- --coverage
```

### E2E Tests (Playwright)
```powershell
# تحميل ENV variables أولاً
Get-Content .env | ForEach-Object { 
  if ($_ -match '^([^#][^=]*)=(.*)$') { 
    [System.Environment]::SetEnvironmentVariable($matches[1], $matches[2], 'Process') 
  } 
}

# تشغيل E2E tests (يشغل vercel dev تلقائياً)
npm run test:e2e

# تشغيل مع UI
npm run test:e2e:ui

# تشغيل جميع الاختبارات
npm run test:all
```

### تشغيل يدوي مع vercel dev
```powershell
# Terminal 1: تشغيل السيرفر
Get-Content .env | ForEach-Object { if ($_ -match '^([^#][^=]*)=(.*)$') { [System.Environment]::SetEnvironmentVariable($matches[1], $matches[2], 'Process') } }
npx vercel dev --listen 3000

# Terminal 2: تشغيل الاختبارات
npm run test:e2e
```

---

## ✅ Unit Tests (34 اختبار)

### `tests/unit/auth.test.ts`
| الاختبار | الوصف |
|----------|-------|
| verify valid token | التحقق من token صحيح |
| reject wrong secret | رفض token بـ secret خاطئ |
| reject expired token | رفض token منتهي الصلاحية |
| reject malformed token | رفض token غير صالح |
| reject tampered payload | رفض token معدّل |
| RBAC role checks | التحقق من صلاحيات الأدوار |
| RBAC lead access | التحقق من الوصول للـ leads |
| RBAC user access | التحقق من الوصول للمستخدمين |

### `tests/unit/schemas.test.ts`
| الاختبار | الوصف |
|----------|-------|
| login schema validation | التحقق من بيانات تسجيل الدخول |
| password complexity | التحقق من تعقيد كلمة المرور |
| lead schema validation | التحقق من بيانات العميل |
| user role enum | التحقق من أدوار المستخدمين |
| error codes | التحقق من رموز الأخطاء |

---

## 🎭 E2E Tests (Playwright)

### `tests/e2e/auth.spec.ts`
| الاختبار | الوصف |
|----------|-------|
| (A) Login → redirect | تسجيل الدخول والتوجيه |
| Guest sees login | الزائر يرى صفحة الدخول |
| Invalid credentials | بيانات خاطئة تظهر خطأ |
| Homepage loads | الصفحة تفتح بدون أخطاء |
| Favicon loads | الأيقونة تعمل |
| API auth 401 | API يرجع 401 للزائر |

### `tests/e2e/password-change.spec.ts`
| الاختبار | الوصف |
|----------|-------|
| (B) mustChangePassword | إجبار تغيير كلمة المرور |
| Password complexity | التحقق من تعقيد كلمة المرور |

### `tests/e2e/rbac.spec.ts`
| الاختبار | الوصف |
|----------|-------|
| (C) Admin sees leads | المسؤول يرى جميع العملاء |
| Admin user management | المسؤول يدير المستخدمين |
| API RBAC leads | API يحمي الـ leads |
| API RBAC users | API يحمي المستخدمين |

---

## 📊 CI/CD

### GitHub Actions (`.github/workflows/ci.yml`)
```yaml
on: [push, pull_request]

jobs:
  build-and-test:
    - npm ci
    - npm run build
    - npm run test
```

### Pre-merge Requirements
- ✅ Build passes
- ✅ Unit tests pass (34/34)
- ⏳ E2E tests (manual for now)

---

## 🔍 تعريف النجاح/الفشل

### النجاح ✅
```
npm run test → 34 passed
npm run build → exit 0
npm run test:e2e → all specs pass
```

### الفشل ❌
- أي unit test يفشل
- Build يفشل
- E2E test يفشل
- Console errors في الصفحة

---

## 🛠️ استكشاف الأخطاء

### Vitest لا يجد الاختبارات
```powershell
# تأكد من وجود vitest.config.ts
# تأكد من أن الملفات في tests/**/*.test.ts
```

### Playwright لا يعمل
```powershell
# تثبيت browsers
npx playwright install chromium

# تأكد من أن vercel dev يعمل
npx vercel dev --listen 3000
```

### E2E يفشل بسبب timeout
```powershell
# زيادة timeout في playwright.config.ts
# أو تشغيل vercel dev يدوياً أولاً
```

---

## 📝 إضافة اختبار جديد

### Unit Test
```typescript
// tests/unit/my-feature.test.ts
import { describe, it, expect } from 'vitest';

describe('My Feature', () => {
  it('should work correctly', () => {
    expect(true).toBe(true);
  });
});
```

### E2E Test
```typescript
// tests/e2e/my-flow.spec.ts
import { test, expect } from '@playwright/test';

test('my flow works', async ({ page }) => {
  await page.goto('/');
  await expect(page).toHaveTitle(/الهدف/);
});
```
