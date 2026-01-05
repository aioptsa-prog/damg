# Verification Steps - Console and Crash Fixes

**Date:** 2026-01-04  
**Purpose:** خطوات التحقق من إصلاح الأخطاء

---

## Prerequisites

```bash
# Ensure you have the latest code
git pull origin main

# Install dependencies
npm install

# Ensure environment variables are set
cp .env.example .env
# Edit .env with your DATABASE_URL
```

---

## Step 1: Build Verification

```bash
cd d:\projects\OP-Target-Sales-Hub-1
npm run build
```

**Expected Output:**
```
✓ 2356 modules transformed.
dist/index.html                   0.79 kB
dist/assets/index-*.css          ~42 kB
dist/assets/index-*.js           ~788 kB
✓ built in ~6s
```

**Success Criteria:**
- Exit code: 0
- No TypeScript errors
- No build warnings (except chunk size)

---

## Step 2: Local Development Test

```bash
# Start local dev server
vercel dev

# Or use Vite directly
npm run dev
```

**Test Scenario:**
1. Open http://localhost:3000
2. Login with `admin@optarget.com` / `Admin@123456`
3. Click "تقرير جديد" (New Report)
4. Enter:
   - اسم الشركة: شركة الراجحي
   - النشاط: خدمات مالية
5. Click "إنشاء التقرير"

**Expected Behavior:**
- Loading overlay appears
- Console shows: "Starting Actual Research (Enrichment Engine)..."
- **NO** `Uncaught TypeError` errors
- Report displays (even with partial data)

**Console Check:**
```javascript
// Open DevTools Console (F12)
// Filter by "error" level
// Expected: No red errors from our code
// Acceptable: zustand deprecation (Vercel external)
```

---

## Step 3: Production Deployment

```bash
# Deploy to production
git add -A
git commit -m "fix: Console crashes and add quality gates"
git push origin main
npx vercel --prod
```

**Expected Output:**
```
✅ Production: https://op-target-sales-hub.vercel.app
```

---

## Step 4: Production Verification

1. Open https://op-target-sales-hub.vercel.app
2. Open DevTools Console (F12)
3. Login with `admin@optarget.com` / `Admin@123456`
4. Navigate to "تقرير جديد"
5. Create a new report

**Console Checklist:**

| Check | Expected | Status |
|-------|----------|--------|
| `Uncaught TypeError` | None | ☐ |
| `Cannot read properties of undefined` | None | ☐ |
| `h.map is not a function` | None | ☐ |
| Report loads | Yes | ☐ |
| Error Boundary triggered | No | ☐ |

---

## Step 5: Edge Case Testing

### Test 1: Empty AI Response

Simulate by temporarily returning empty object from AI:

```typescript
// In services/aiService.ts, temporarily add:
return { data: {}, usage: { inputTokens: 0, outputTokens: 0, cost: 0, latencyMs: 0 } };
```

**Expected:** Report displays with "غير متوفر" placeholders, no crash.

### Test 2: Missing Sector Confidence

```typescript
// Simulate response without sector.confidence
return { 
  data: { sector: { primary: 'retail' } }, // No confidence field
  usage: {...}
};
```

**Expected:** Shows "0% دقة" instead of crash.

### Test 3: Error Boundary Test

```typescript
// In any component, temporarily add:
throw new Error('Test error');
```

**Expected:** Error Boundary UI appears with retry button.

---

## Step 6: Unit Test (Optional)

```bash
# Run unit tests
npm run test

# Run specific schema tests
npm run test -- --grep "schema"
```

**Test File:** `tests/schemas.test.ts`

```typescript
import { describe, it, expect } from 'vitest';
import { parseReportOutput, parseEvidencePack } from '../domain/schemas';

describe('Schema Parsing', () => {
  it('should handle missing confidence', () => {
    const result = parseReportOutput({ sector: { primary: 'retail' } });
    expect(result.sector.confidence).toBe(0);
  });

  it('should handle empty object', () => {
    const result = parseReportOutput({});
    expect(result.sector.primary).toBe('other');
    expect(result.follow_up_plan).toEqual([]);
  });

  it('should handle null input', () => {
    const result = parseReportOutput(null);
    expect(result).toBeDefined();
  });
});
```

---

## Verification Checklist

### Must Pass ✅

- [ ] `npm run build` succeeds
- [ ] No `Uncaught TypeError` in console
- [ ] Report creation flow completes
- [ ] Error Boundary exists and works
- [ ] Zod schemas validate correctly

### Nice to Have ⭐

- [ ] Unit tests pass
- [ ] E2E tests pass
- [ ] No console warnings from our code

---

## Rollback Plan

If issues persist after deployment:

```bash
# Revert to previous commit
git revert HEAD
git push origin main
npx vercel --prod
```

---

## Monitoring

After deployment, monitor for 24 hours:

1. **Vercel Dashboard:** Check function logs for errors
2. **Browser Console:** Test on multiple browsers
3. **User Reports:** Monitor support channels

---

## Sign-off

| Role | Name | Date | Signature |
|------|------|------|-----------|
| Principal Engineer | - | 2026-01-04 | ✅ |
| QA | - | - | ☐ |
| Product Owner | - | - | ☐ |
