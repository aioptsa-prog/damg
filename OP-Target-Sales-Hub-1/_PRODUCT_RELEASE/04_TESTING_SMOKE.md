# Testing & Smoke Guide

**تاريخ:** 2026-01-03

---

## 🧪 Unit Tests (Vitest)

### الإعداد
```bash
npm install -D vitest @vitest/coverage-v8
```

### التشغيل
```bash
# تشغيل جميع الاختبارات
npm run test

# تشغيل مع watch mode
npm run test:watch

# تشغيل مع coverage
npm run test -- --coverage
```

### الاختبارات المطلوبة

#### Auth Helpers (`api/_auth.ts`)
```typescript
// tests/auth.test.ts
describe('Auth Helpers', () => {
  test('verifyToken returns null for invalid token');
  test('verifyToken returns user for valid token');
  test('getAuthFromRequest extracts token from cookie');
  test('requireAuth returns 401 for unauthenticated');
  test('requireRole returns 403 for wrong role');
});
```

#### RBAC (`api/_auth.ts`)
```typescript
// tests/rbac.test.ts
describe('RBAC', () => {
  test('SUPER_ADMIN can access all resources');
  test('MANAGER can access team resources');
  test('SALES_REP can access own resources only');
  test('canAccessLead checks ownership');
  test('canAccessUser checks permissions');
});
```

#### Validation (`api/schemas.ts`)
```typescript
// tests/validation.test.ts
describe('Validation Schemas', () => {
  test('loginSchema validates email format');
  test('loginSchema rejects empty password');
  test('changePasswordSchema enforces complexity');
  test('leadSchema validates required fields');
});
```

---

## 🔥 Smoke Tests (Playwright)

### الإعداد
```bash
npm install -D @playwright/test
npx playwright install
```

### التشغيل
```bash
# تشغيل smoke tests
npx playwright test tests/smoke/

# تشغيل مع UI
npx playwright test --ui

# تشغيل على browser معين
npx playwright test --project=chromium
```

### Smoke Test Scenarios

#### 1. Page Load
```typescript
// tests/smoke/page-load.spec.ts
test('homepage loads without errors', async ({ page }) => {
  await page.goto('/');
  await expect(page).toHaveTitle(/الهدف الأمثل/);
  
  // No console errors
  const errors: string[] = [];
  page.on('console', msg => {
    if (msg.type() === 'error') errors.push(msg.text());
  });
  
  await page.waitForLoadState('networkidle');
  expect(errors).toHaveLength(0);
});
```

#### 2. Login Flow
```typescript
// tests/smoke/auth.spec.ts
test('login flow works', async ({ page }) => {
  await page.goto('/');
  
  // Fill login form
  await page.fill('[name="email"]', 'admin@optarget.sa');
  await page.fill('[name="password"]', 'TestPassword123!');
  await page.click('button[type="submit"]');
  
  // Should redirect to dashboard
  await expect(page).toHaveURL(/dashboard/);
});

test('logout works', async ({ page }) => {
  // ... login first
  await page.click('[data-testid="logout-button"]');
  await expect(page).toHaveURL('/');
});
```

#### 3. RBAC
```typescript
// tests/smoke/rbac.spec.ts
test('admin sees user management', async ({ page }) => {
  // Login as admin
  await page.click('[data-testid="users-nav"]');
  await expect(page.locator('h1')).toContainText('إدارة المستخدمين');
});

test('sales rep cannot see user management', async ({ page }) => {
  // Login as sales rep
  await expect(page.locator('[data-testid="users-nav"]')).not.toBeVisible();
});
```

#### 4. Must Change Password
```typescript
// tests/smoke/password.spec.ts
test('enforces password change on first login', async ({ page }) => {
  // Login with mustChangePassword=true user
  await expect(page.locator('[data-testid="change-password-modal"]')).toBeVisible();
});
```

---

## ✅ Manual Smoke Checklist

### قبل كل Deploy

- [ ] **Page Load**
  - [ ] الصفحة تفتح بدون white screen
  - [ ] لا 404 assets (favicon, etc.)
  - [ ] لا console errors

- [ ] **Auth**
  - [ ] Login يعمل
  - [ ] Logout يعمل
  - [ ] GET /api/auth يرجع 401 للـ guest

- [ ] **RBAC**
  - [ ] Admin يرى كل شيء
  - [ ] Sales Rep يرى بياناته فقط

- [ ] **Password**
  - [ ] mustChangePassword يُفرض
  - [ ] تغيير كلمة المرور يعمل

- [ ] **API**
  - [ ] /api/seed يرجع 404 في Production
  - [ ] لا 500 errors

---

## 📊 تعريف النجاح/الفشل

### النجاح ✅
- جميع unit tests تمر
- جميع smoke tests تمر
- لا console errors في الصفحة
- لا 404 assets
- لا 500 API errors

### الفشل ❌
- أي unit test يفشل
- أي smoke test يفشل
- console error في الصفحة
- 404 asset
- 500 API error
- White screen

---

## 🔧 CI Integration

### GitHub Actions
```yaml
# .github/workflows/test.yml
name: Tests
on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with:
          node-version: '20'
      - run: npm ci
      - run: npm run build
      - run: npm run test
      
  e2e:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with:
          node-version: '20'
      - run: npm ci
      - run: npx playwright install --with-deps
      - run: npm run build
      - run: npx playwright test
```

---

## 📝 ملاحظات

### الحالة الحالية
- Vitest مُثبت لكن الاختبارات تحتاج تحديث
- Playwright غير مُثبت بعد
- Manual smoke checklist هو الأساس حالياً

### الخطوات التالية
1. إصلاح Vitest tests الموجودة
2. تثبيت Playwright
3. كتابة smoke tests
4. إضافة CI workflow
