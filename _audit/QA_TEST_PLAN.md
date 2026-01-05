# QA_TEST_PLAN.md
> خطة الاختبارات وضمان الجودة
> تاريخ الإنشاء: 2026-01-05

---

## 1. نظرة عامة

### 1.1 الحالة الحالية

| المشروع | Unit Tests | Integration | E2E | Coverage |
|---------|------------|-------------|-----|----------|
| OP-Target | 62 ✅ | 0 | 0 | غير مؤكد |
| Forge | 0 | 0 | 0 | 0% |

### 1.2 الأهداف

| النوع | الهدف | الأولوية |
|-------|-------|----------|
| Unit Tests | 80% coverage | P1 |
| Integration | Critical paths | P1 |
| E2E | Happy paths | P2 |
| Performance | Load testing | P3 |

---

## 2. Unit Tests

### 2.1 OP-Target-Sales-Hub-1

#### الملفات الموجودة
```
tests/
├── normalizeReport.test.ts    # 28 tests ✅
├── schema.test.ts             # 2 tests ✅
└── unit/
    ├── auth.test.ts           # 15 tests ✅
    └── schemas.test.ts        # 17 tests ✅
```

#### الملفات المطلوبة
| الملف | الوظيفة | الأولوية |
|-------|---------|----------|
| `tests/unit/leads.test.ts` | Leads CRUD logic | P1 |
| `tests/unit/users.test.ts` | User management | P1 |
| `tests/unit/reports.test.ts` | Report generation | P1 |
| `tests/unit/encryption.test.ts` | Encryption service | P1 |
| `tests/unit/rateLimit.test.ts` | Rate limiting | P1 |

#### أمر التشغيل
```powershell
cd d:\projects\دمج\OP-Target-Sales-Hub-1
npm run test
```

### 2.2 Forge (PHP)

#### الملفات المطلوبة
| الملف | الوظيفة | الأولوية |
|-------|---------|----------|
| `tests/AuthTest.php` | Authentication | P1 |
| `tests/LeadsTest.php` | Leads CRUD | P1 |
| `tests/SecurityTest.php` | HMAC, CSRF | P1 |
| `tests/ClassifyTest.php` | Lead classification | P2 |

#### إعداد PHPUnit
```bash
composer require --dev phpunit/phpunit
```

---

## 3. Integration Tests

### 3.1 OP-Target API Tests

| Test Case | Endpoint | Method | Expected |
|-----------|----------|--------|----------|
| Login Success | `/api/auth` | POST | 200 + cookie |
| Login Fail | `/api/auth` | POST | 401 |
| Login Rate Limit | `/api/auth` | POST x6 | 429 |
| Get Leads Auth | `/api/leads` | GET | 200 |
| Get Leads NoAuth | `/api/leads` | GET | 401 |
| Create Lead | `/api/leads` | POST | 200 |
| Delete Lead | `/api/leads?id=X` | DELETE | 200 |
| Get Report | `/api/reports` | GET | 200 |

### 3.2 Forge API Tests

| Test Case | Endpoint | Method | Expected |
|-----------|----------|--------|----------|
| Login | `/v1/api/auth/login.php` | POST | 200 + token |
| Get Leads | `/v1/api/leads/` | GET | 200 |
| WhatsApp Send | `/v1/api/whatsapp/send.php` | POST | 200/400 |
| Worker Pull | `/api/pull_job.php` | GET | 200/204 |
| Worker Report | `/api/report_results.php` | POST | 200 |

### 3.3 Cross-Project Integration

| Test Case | Flow | Expected |
|-----------|------|----------|
| Auth Bridge | OP-Target → Forge | Token exchange works |
| Lead Sync | Forge → OP-Target | Lead data transfers |
| WhatsApp from Report | OP-Target → Forge | Message sent |

---

## 4. E2E Tests (Playwright)

### 4.1 إعداد Playwright

```powershell
cd d:\projects\دمج\OP-Target-Sales-Hub-1
npx playwright install
```

### 4.2 سيناريوهات أساسية

#### TC-E2E-001: Login Flow
```typescript
test('user can login', async ({ page }) => {
  await page.goto('/');
  await page.fill('[name="email"]', 'admin@example.com');
  await page.fill('[name="password"]', 'password');
  await page.click('button[type="submit"]');
  await expect(page).toHaveURL('/dashboard');
});
```

#### TC-E2E-002: Create Lead
```typescript
test('user can create lead', async ({ page }) => {
  // Login first
  await login(page);
  
  await page.click('text=إضافة عميل');
  await page.fill('[name="companyName"]', 'شركة تجريبية');
  await page.fill('[name="phone"]', '0501234567');
  await page.click('button[type="submit"]');
  
  await expect(page.locator('text=شركة تجريبية')).toBeVisible();
});
```

#### TC-E2E-003: Generate Report
```typescript
test('user can generate AI report', async ({ page }) => {
  await login(page);
  await page.click('text=التقارير');
  await page.click('text=إنشاء تقرير');
  
  // Wait for AI generation
  await expect(page.locator('.report-content')).toBeVisible({ timeout: 30000 });
});
```

### 4.3 أمر التشغيل
```powershell
npm run test:e2e
```

---

## 5. Regression Checklist

### 5.1 قبل أي Release

#### Authentication
- [ ] Login يعمل بشكل صحيح
- [ ] Logout يمسح الـ session
- [ ] Rate limiting يعمل
- [ ] Password change يعمل

#### Leads
- [ ] عرض قائمة العملاء
- [ ] إنشاء عميل جديد
- [ ] تعديل عميل
- [ ] حذف عميل
- [ ] البحث والفلترة

#### Reports
- [ ] إنشاء تقرير AI
- [ ] عرض التقارير السابقة
- [ ] تصدير التقرير

#### WhatsApp
- [ ] إرسال رسالة نصية
- [ ] إرسال صورة
- [ ] سجل الرسائل

#### Admin
- [ ] إدارة المستخدمين
- [ ] الإعدادات
- [ ] Audit logs

### 5.2 قبل أي Deployment

- [ ] `npm run build` ناجح
- [ ] `npm run test` ناجح
- [ ] لا أخطاء في Console
- [ ] الـ API endpoints تستجيب
- [ ] Database migrations مطبقة

---

## 6. Performance Tests

### 6.1 Load Testing (k6)

```javascript
// load-test.js
import http from 'k6/http';
import { check, sleep } from 'k6';

export let options = {
  vus: 50,
  duration: '30s',
};

export default function () {
  let res = http.get('http://localhost:3000/api/leads');
  check(res, {
    'status is 200': (r) => r.status === 200,
    'response time < 500ms': (r) => r.timings.duration < 500,
  });
  sleep(1);
}
```

### 6.2 معايير الأداء

| Metric | Target | Critical |
|--------|--------|----------|
| Response Time (p95) | < 500ms | < 2000ms |
| Error Rate | < 1% | < 5% |
| Concurrent Users | 100 | 50 |
| Requests/sec | 50 | 20 |

---

## 7. Security Tests

### 7.1 OWASP Top 10 Checklist

- [ ] A01: Broken Access Control
- [ ] A02: Cryptographic Failures
- [ ] A03: Injection
- [ ] A04: Insecure Design
- [ ] A05: Security Misconfiguration
- [ ] A06: Vulnerable Components
- [ ] A07: Authentication Failures
- [ ] A08: Software Integrity Failures
- [ ] A09: Logging Failures
- [ ] A10: SSRF

### 7.2 أدوات الفحص

| الأداة | الغرض |
|--------|-------|
| OWASP ZAP | Vulnerability scanning |
| npm audit | Dependency vulnerabilities |
| Snyk | Security scanning |

---

## 8. Test Data

### 8.1 بيانات تجريبية

```sql
-- Test Users
INSERT INTO users (id, email, name, role, password_hash) VALUES
('test-admin', 'admin@test.com', 'Admin', 'SUPER_ADMIN', '$2b$10$...'),
('test-manager', 'manager@test.com', 'Manager', 'MANAGER', '$2b$10$...'),
('test-sales', 'sales@test.com', 'Sales', 'SALES_REP', '$2b$10$...');

-- Test Leads
INSERT INTO leads (id, company_name, phone, status, owner_user_id) VALUES
('lead-1', 'شركة تجريبية 1', '0501234567', 'NEW', 'test-sales'),
('lead-2', 'شركة تجريبية 2', '0507654321', 'CONTACTED', 'test-sales');
```

### 8.2 Seed Script

```powershell
npm run db:seed
```

---

## 9. CI/CD Integration

### 9.1 GitHub Actions

```yaml
# .github/workflows/test.yml
name: Tests
on: [push, pull_request]
jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      - uses: actions/setup-node@v3
        with:
          node-version: '20'
      - run: npm ci
      - run: npm run test
      - run: npm run build
```

### 9.2 Pre-commit Hooks

```json
// package.json
{
  "husky": {
    "hooks": {
      "pre-commit": "npm run test"
    }
  }
}
```

---

## 10. Bug Tracking

### 10.1 قالب تقرير Bug

```markdown
## وصف المشكلة
[وصف واضح]

## خطوات إعادة الإنتاج
1. ...
2. ...
3. ...

## النتيجة المتوقعة
[ماذا يجب أن يحدث]

## النتيجة الفعلية
[ماذا حدث فعلاً]

## بيئة التشغيل
- Browser: 
- OS: 
- Version: 

## لقطات شاشة
[إن وجدت]
```

---

> **آخر تحديث**: 2026-01-05 19:56 UTC+3
