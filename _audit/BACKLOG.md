# BACKLOG.md
> قائمة المهام والأولويات
> تاريخ الإنشاء: 2026-01-05

---

## Sprint 1: Critical Fixes (الأسبوع 1)

### US-001: إعداد Git Repository
- **الوصف**: تهيئة Git للمشروعين مع .gitignore مناسب
- **الحجم**: S
- **الأولوية**: P0 - Critical
- **Acceptance Criteria**:
  - [ ] `git init` في كلا المشروعين
  - [ ] `.gitignore` يستثني node_modules, .env, storage/
  - [ ] Initial commit مع رسالة واضحة
  - [ ] Branch protection rules (إن أمكن)

### US-002: إصلاح CORS في Forge WhatsApp API
- **الوصف**: تقييد CORS للـ origins المسموحة فقط
- **الحجم**: S
- **الأولوية**: P0 - Critical
- **Acceptance Criteria**:
  - [ ] قائمة بيضاء للـ origins
  - [ ] رفض requests من origins غير معروفة
  - [ ] اختبار من domain مختلف يفشل

### US-003: نقل Rate Limiting للـ Server
- **الوصف**: Rate limiting حقيقي على الخادم بدلاً من localStorage
- **الحجم**: M
- **الأولوية**: P0 - Critical
- **Acceptance Criteria**:
  - [ ] Rate limit يُخزن في Database أو Redis
  - [ ] يستمر بعد إعادة تشغيل الخادم
  - [ ] 5 محاولات login خلال 15 دقيقة
  - [ ] رسالة خطأ واضحة عند التجاوز

---

## Sprint 2: Security Hardening (الأسبوع 2)

### US-004: إضافة CSRF Protection
- **الوصف**: حماية من هجمات CSRF
- **الحجم**: M
- **الأولوية**: P1 - High
- **Acceptance Criteria**:
  - [ ] CSRF token في كل form
  - [ ] التحقق من Token في POST/PUT/DELETE
  - [ ] رفض requests بدون token صحيح

### US-005: تحسين Input Validation في Forge
- **الوصف**: إضافة validation شامل للمدخلات
- **الحجم**: M
- **الأولوية**: P1 - High
- **Acceptance Criteria**:
  - [ ] Validation لكل endpoint
  - [ ] رسائل خطأ واضحة
  - [ ] تنظيف المدخلات من XSS

### US-006: إضافة Security Headers
- **الوصف**: إضافة headers أمنية للـ responses
- **الحجم**: S
- **الأولوية**: P1 - High
- **Acceptance Criteria**:
  - [ ] Content-Security-Policy
  - [ ] X-Content-Type-Options
  - [ ] X-Frame-Options
  - [ ] Strict-Transport-Security (production)

---

## Sprint 3: Performance & Monitoring (الأسبوع 3)

### US-007: تقليل حجم Bundle
- **الوصف**: تقسيم الـ bundle لتحسين الأداء
- **الحجم**: M
- **الأولوية**: P1 - High
- **Acceptance Criteria**:
  - [ ] حجم الـ bundle الرئيسي < 500KB
  - [ ] Code splitting للـ routes
  - [ ] Lazy loading للمكونات الكبيرة

### US-008: إضافة Health Check شامل
- **الوصف**: endpoint لفحص صحة النظام
- **الحجم**: S
- **الأولوية**: P1 - High
- **Acceptance Criteria**:
  - [ ] فحص اتصال Database
  - [ ] فحص الخدمات الخارجية
  - [ ] Response time < 1s

### US-009: إعداد Logging مركزي
- **الوصف**: نظام logging منظم ومركزي
- **الحجم**: M
- **الأولوية**: P2 - Medium
- **Acceptance Criteria**:
  - [ ] مستويات log (debug, info, warn, error)
  - [ ] تنسيق JSON للـ logs
  - [ ] لا تسريب للأسرار في الـ logs

---

## Sprint 4: Data & Integration (الأسبوع 4)

### US-010: إضافة Pagination للـ APIs
- **الوصف**: Pagination لجميع الـ list endpoints
- **الحجم**: M
- **الأولوية**: P2 - Medium
- **Acceptance Criteria**:
  - [ ] LIMIT/OFFSET أو cursor-based
  - [ ] Default page size = 20
  - [ ] Max page size = 100

### US-011: تفعيل Integration Auth Bridge
- **الوصف**: تفعيل جسر المصادقة بين المشروعين
- **الحجم**: L
- **الأولوية**: P2 - Medium
- **Acceptance Criteria**:
  - [ ] Token exchange يعمل
  - [ ] SSO بين المشروعين
  - [ ] Audit logging للـ cross-auth

### US-012: إعداد Backup Strategy
- **الوصف**: نسخ احتياطي تلقائي للبيانات
- **الحجم**: M
- **الأولوية**: P2 - Medium
- **Acceptance Criteria**:
  - [ ] Backup يومي لـ PostgreSQL
  - [ ] Backup يومي لـ SQLite
  - [ ] اختبار استعادة ناجح

---

## Sprint 5: Testing & Documentation (الأسبوع 5)

### US-013: إضافة E2E Tests
- **الوصف**: اختبارات شاملة للسيناريوهات الأساسية
- **الحجم**: L
- **الأولوية**: P2 - Medium
- **Acceptance Criteria**:
  - [ ] Login/Logout flow
  - [ ] Leads CRUD
  - [ ] Report generation
  - [ ] WhatsApp send (mock)

### US-014: إكمال OpenAPI Documentation
- **الوصف**: توثيق كامل للـ API
- **الحجم**: M
- **الأولوية**: P2 - Medium
- **Acceptance Criteria**:
  - [ ] جميع الـ endpoints موثقة
  - [ ] Request/Response schemas
  - [ ] Swagger UI يعمل

### US-015: تحسين Error Handling
- **الوصف**: معالجة أخطاء شاملة ومتسقة
- **الحجم**: M
- **الأولوية**: P3 - Low
- **Acceptance Criteria**:
  - [ ] Error codes موحدة
  - [ ] رسائل عربية واضحة
  - [ ] Stack traces في development فقط

---

## Backlog (غير مجدول)

### US-016: تحسين RTL/Responsive
- **الحجم**: M
- **الأولوية**: P3 - Low

### US-017: إضافة Dark Mode
- **الحجم**: S
- **الأولوية**: P4 - Nice to have

### US-018: تحسين Accessibility
- **الحجم**: M
- **الأولوية**: P3 - Low

### US-019: إضافة Notifications System
- **الحجم**: L
- **الأولوية**: P3 - Low

### US-020: Dashboard Analytics
- **الحجم**: L
- **الأولوية**: P3 - Low

---

## ملخص الحجم

| الحجم | العدد | الوصف |
|-------|-------|-------|
| S | 5 | < 4 ساعات |
| M | 11 | 4-16 ساعة |
| L | 4 | > 16 ساعة |

---

## Velocity المتوقع

- Sprint 1: 3 stories (S+S+M) ≈ 12 ساعة
- Sprint 2: 3 stories (M+M+S) ≈ 16 ساعة
- Sprint 3: 3 stories (M+S+M) ≈ 16 ساعة
- Sprint 4: 3 stories (M+L+M) ≈ 24 ساعة
- Sprint 5: 3 stories (L+M+M) ≈ 24 ساعة

---

> **آخر تحديث**: 2026-01-05 19:56 UTC+3
