# Sprint 1 Plan - Foundation Production-Ready

**تاريخ البدء:** 2026-01-03  
**المدة المتوقعة:** 2-3 أيام

---

## 🎯 الهدف

إنشاء أساس متين للمنتج يشمل:
- UX نظيف بدون أخطاء console
- نظام seed/bootstrap آمن
- نظام migrations رسمي
- اختبارات أساسية

---

## 📋 المهام

### P0 - UX Baseline (مكتمل ✅)

| المهمة | التقدير | الحالة | الدليل |
|--------|---------|--------|--------|
| Fix 404 /vite.svg | 10m | ✅ | `public/favicon.svg` created |
| Fix checkSession 401 logging | 15m | ✅ | `authService.ts` updated |
| Remove debug logs | 10m | ✅ | `db.ts` cleaned |

### P0 - Seed Policy (مكتمل ✅)

| المهمة | التقدير | الحالة | الدليل |
|--------|---------|--------|--------|
| /api/seed → 404 in Production | 15m | ✅ | `VERCEL_ENV` check added |
| Bootstrap script for Production | 30m | ✅ | `scripts/bootstrap-admin.js` |

### P1 - Foundation

| المهمة | التقدير | الحالة | الدليل |
|--------|---------|--------|--------|
| Migrations system (tracked) | 30m | ✅ | `_migrations` table added |
| Unified error schema | 20m | ✅ | `schemas.ts` updated |
| Zod validation all endpoints | 1h | 🔄 | Schemas exist, need integration |
| Vitest unit tests | 2h | ⏳ | - |
| Playwright smoke tests | 2h | ⏳ | - |

### P2 - Documentation

| المهمة | التقدير | الحالة |
|--------|---------|--------|
| 00_CURRENT_STATUS.md | 20m | ✅ |
| 01_SPRINT1_PLAN.md | 15m | ✅ |
| 02_SEED_AND_BOOTSTRAP.md | 20m | ⏳ |
| 03_MIGRATIONS_SYSTEM.md | 15m | ⏳ |
| 04_TESTING_SMOKE.md | 15m | ⏳ |
| 05_CHANGELOG.md | 15m | ⏳ |

---

## ✅ معايير النجاح

### UX
- [ ] الصفحة تفتح بدون console errors
- [ ] لا 404 assets
- [ ] Guest flow نظيف (401 لا يُعامل كخطأ)

### Security
- [ ] /api/seed يرجع 404 في Production
- [ ] Bootstrap script يعمل مرة واحدة
- [ ] Admin user موجود مع mustChangePassword=true

### Database
- [ ] Migrations tracked في `_migrations` table
- [ ] Schema كامل ومفهرس

### Testing
- [ ] `npm run test` يمر
- [ ] Smoke tests تعمل

### Documentation
- [ ] جميع ملفات `/_PRODUCT_RELEASE/` مكتملة

---

## 📊 التقدم

```
P0 UX:        ████████████████████ 100%
P0 Seed:      ████████████████████ 100%
P1 Foundation: ████████░░░░░░░░░░░░  40%
P2 Docs:      ████░░░░░░░░░░░░░░░░  20%
─────────────────────────────────────────
Overall:      ████████████░░░░░░░░  60%
```

---

## ⏭️ الخطوات التالية

1. تشغيل migrations على Neon
2. تشغيل bootstrap-admin.js
3. إنشاء Vitest tests
4. إنشاء Playwright smoke tests
5. إكمال التوثيق
6. Deploy + Verify
