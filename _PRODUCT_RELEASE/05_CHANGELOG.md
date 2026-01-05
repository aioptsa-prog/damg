# Changelog

**آخر تحديث:** 2026-01-03

---

## [Sprint 2] - Testing & DB Discipline

### DB Discipline

#### Strict UNPOOLED requirement for migrations
- **الملف:** `database/run-migrations.js`
- **السبب:** DDL operations تحتاج direct connection
- **التغيير:**
  - يرفض التشغيل بدون `DATABASE_URL_UNPOOLED`
  - يتحقق من عدم وجود `-pooler` في الـ URL
- **الدليل:** `npm run db:migrate` يعمل مع UNPOOLED فقط

#### Added DATABASE_URL_UNPOOLED to .env
- **الملف:** `.env`
- **التغيير:** إضافة `DATABASE_URL_UNPOOLED` للـ migrations

---

### Testing Setup

#### Vitest Configuration
- **الملف:** `vitest.config.ts`
- **التغيير:** إعداد Vitest مع environment: node

#### Unit Tests (34 tests)
- **الملفات:**
  - `tests/unit/auth.test.ts` - 15 اختبار
  - `tests/unit/schemas.test.ts` - 17 اختبار
  - `tests/schema.test.ts` - 2 اختبار
- **الدليل:** `npm run test` → 34 passed

#### Playwright E2E Setup
- **الملفات:**
  - `playwright.config.ts`
  - `tests/e2e/auth.spec.ts`
  - `tests/e2e/password-change.spec.ts`
  - `tests/e2e/rbac.spec.ts`
- **التغيير:** إعداد Playwright مع vercel dev

---

### CI/CD

#### GitHub Actions Workflow
- **الملف:** `.github/workflows/ci.yml`
- **التغيير:** Build + Unit tests على كل push/PR

---

### Scripts

#### New npm scripts
- `npm run test:e2e` - Playwright tests
- `npm run test:e2e:ui` - Playwright UI mode
- `npm run test:all` - Unit + E2E
- `npm run db:migrate` - Run migrations

---

## [Sprint 1] - Foundation Production-Ready

### P0-UX Fixes

#### Fix: 404 /vite.svg
- **الملف:** `index.html`, `public/favicon.svg`
- **السبب:** الـ favicon reference كان يشير لملف غير موجود
- **التغيير:** 
  - أنشأنا `public/favicon.svg` بشعار OP
  - غيرنا `href="/vite.svg"` إلى `href="/favicon.svg"`
- **الدليل:** Build passes, no 404 in network tab

#### Fix: checkSession 401 logging
- **الملف:** `services/authService.ts`
- **السبب:** 401 response كان يُعامل كـ error ويُطبع في console
- **التغيير:** 
  - أضفنا `else` branch للتعامل مع non-ok responses
  - 401 الآن يُعامل كـ Guest state (expected)
- **الدليل:** No console errors on page load for guest

#### Fix: Remove debug log
- **الملف:** `services/db.ts`
- **السبب:** `console.log('Production: Database check performed.')` يظهر في production
- **التغيير:** حذفنا الـ log واستبدلناه بـ comment
- **الدليل:** No debug logs in production console

---

### P0-Seed Policy

#### Fix: /api/seed returns 404 in Production
- **الملف:** `api/seed.ts`
- **السبب:** كان يرجع 403 في production (يكشف وجود الـ endpoint)
- **التغيير:**
  - أضفنا check لـ `VERCEL_ENV === 'production'`
  - يرجع 404 بدلاً من 403
- **الدليل:** `curl -X POST .../api/seed` → 404

#### New: Bootstrap script for Production
- **الملف:** `scripts/bootstrap-admin.js`
- **السبب:** نحتاج طريقة آمنة لإنشاء admin في production بدون endpoint
- **التغيير:**
  - أنشأنا script يتصل مباشرة بـ Neon
  - يستخدم `DATABASE_URL_UNPOOLED`
  - Idempotent (آمن للتشغيل عدة مرات)
  - يُنشئ admin مع `mustChangePassword=true`
- **الدليل:** Script runs successfully, creates admin

---

### Foundation

#### Improvement: Migrations tracking
- **الملف:** `database/run-migrations.js`
- **السبب:** لم يكن هناك تتبع للـ migrations المنفذة
- **التغيير:**
  - أضفنا جدول `_migrations` للتتبع
  - يستخدم `DATABASE_URL_UNPOOLED` للـ DDL
  - يسجل كل migration بعد تنفيذه
- **الدليل:** `SELECT * FROM _migrations` يعرض الـ migrations

#### Improvement: Unified error schema
- **الملف:** `api/schemas.ts`
- **السبب:** الـ error responses لم تكن موحدة
- **التغيير:**
  - أضفنا `APIError` interface
  - أضفنا `createErrorResponse()` helper
  - أضفنا `ErrorCodes` constants
- **الدليل:** Build passes, types available

---

### Documentation

#### New: /_PRODUCT_RELEASE/ folder
- `00_CURRENT_STATUS.md` - حالة المشروع الحالية
- `01_SPRINT1_PLAN.md` - خطة Sprint 1
- `02_SEED_AND_BOOTSTRAP.md` - دليل Seed و Bootstrap
- `03_MIGRATIONS_SYSTEM.md` - دليل نظام Migrations
- `04_TESTING_SMOKE.md` - دليل الاختبارات
- `05_CHANGELOG.md` - سجل التغييرات (هذا الملف)

---

## Commits

```
a19c69b - docs: add product documentation (PRD, Architecture, Backlog, DoD)
532621e - fix(P0): add .js extension to ESM imports for Vercel serverless
37f5eae - fix(P0): remove jsxDEV from production bundle + install Tailwind locally
```

---

## Files Changed Summary

### New Files
- `public/favicon.svg`
- `scripts/bootstrap-admin.js`
- `_PRODUCT_RELEASE/00_CURRENT_STATUS.md`
- `_PRODUCT_RELEASE/01_SPRINT1_PLAN.md`
- `_PRODUCT_RELEASE/02_SEED_AND_BOOTSTRAP.md`
- `_PRODUCT_RELEASE/03_MIGRATIONS_SYSTEM.md`
- `_PRODUCT_RELEASE/04_TESTING_SMOKE.md`
- `_PRODUCT_RELEASE/05_CHANGELOG.md`

### Modified Files
- `index.html` - favicon reference
- `services/authService.ts` - checkSession fix
- `services/db.ts` - remove debug log
- `api/seed.ts` - 404 in production
- `api/schemas.ts` - unified error schema
- `database/run-migrations.js` - migrations tracking

---

## Verification

### Build
```
✓ npm run build passes
✓ No TypeScript errors
✓ Bundle size: 991.98 kB
```

### Manual Smoke (2026-01-03)
```
✓ Page loads without white screen
✓ No 404 assets (favicon.svg works)
✓ No console errors (guest flow)
✓ GET /api/auth returns 401 for guest
✓ POST /api/seed returns 404 in production
✓ POST /api/auth login works
✓ Admin user exists (admin@optarget.com)
✓ mustChangePassword enforced
✓ Migrations tracked in _migrations table
```
