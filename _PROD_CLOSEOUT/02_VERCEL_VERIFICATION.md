# Vercel Verification Guide

**Production URL:** https://op-target-sales-hub.vercel.app

---

## Pre-Verification Checklist

- [ ] Code pushed to GitHub `main` branch
- [ ] Vercel deployment completed successfully
- [ ] No build errors in Vercel logs

---

## Verification Steps

### 1. Login Flow
```
1. Open: https://op-target-sales-hub.vercel.app/login
2. Enter credentials:
   - Email: admin@optarget.com
   - Password: Admin@123456
3. Click "تسجيل الدخول"

Expected:
- ✅ Redirects to /dashboard
- ✅ URL changes to /dashboard
- ✅ Sidebar shows user info
- ✅ No console errors
```

### 2. URL Routing Test
```
1. Click "العملاء" in sidebar
   Expected: URL changes to /leads

2. Click "تقرير جديد" in sidebar
   Expected: URL changes to /leads/new

3. Click "الإعدادات" in sidebar
   Expected: URL changes to /settings

4. Manually type: /leaderboard in browser
   Expected: Page loads without white screen
```

### 3. Deep Link Test
```
1. Copy URL: https://op-target-sales-hub.vercel.app/dashboard
2. Open new incognito window
3. Paste URL and press Enter

Expected:
- ✅ Redirects to /login (not authenticated)
- ✅ After login, returns to /dashboard
```

### 4. 404 Page Test
```
1. Navigate to: https://op-target-sales-hub.vercel.app/nonexistent-page

Expected:
- ✅ Shows 404 page with Arabic text
- ✅ "العودة للرئيسية" button works
- ✅ No white screen or crash
```

### 5. API Endpoints Test
```bash
# Login and get cookie
curl -X POST "https://op-target-sales-hub.vercel.app/api/auth?action=login" \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@optarget.com","password":"Admin@123456"}' \
  -c cookies.txt -v

# Expected: 200 OK, Set-Cookie header

# Get current user
curl "https://op-target-sales-hub.vercel.app/api/auth?action=me" \
  -b cookies.txt

# Expected: 200 OK, user JSON

# Get leads
curl "https://op-target-sales-hub.vercel.app/api/leads" \
  -b cookies.txt

# Expected: 200 OK, leads array

# Test tasks validation (should return 400, not 500)
curl -X POST "https://op-target-sales-hub.vercel.app/api/tasks" \
  -H "Content-Type: application/json" \
  -b cookies.txt \
  -d '[{"invalid":"data"}]'

# Expected: 400 Bad Request with validation error
```

### 6. Console Error Check
```
1. Open browser DevTools (F12)
2. Go to Console tab
3. Navigate through all pages:
   - /dashboard
   - /leads
   - /leads/new
   - /settings
   - /users

Expected:
- ✅ 0 Uncaught TypeError
- ✅ 0 "Cannot read property of undefined"
- ⚠️ zustand deprecation warning is OK (external library)
```

### 7. Mobile Responsive Test
```
1. Open DevTools → Toggle device toolbar (Ctrl+Shift+M)
2. Select iPhone 12 Pro
3. Navigate through all pages

Expected:
- ✅ Sidebar collapses properly
- ✅ Content is readable
- ✅ Buttons are tappable
- ✅ RTL layout correct
```

---

## Known Issues (Not Blocking)

| Issue | Severity | Notes |
|-------|----------|-------|
| zustand deprecation warning | Low | External library, not our code |
| GEMINI_API_KEY missing | Medium | Add in Vercel env vars for AI |
| Large bundle size | Low | Can optimize with code splitting |

---

## Verification Results

| Test | Status | Notes |
|------|--------|-------|
| Login Flow | ⏳ | |
| URL Routing | ⏳ | |
| Deep Links | ⏳ | |
| 404 Page | ⏳ | |
| API Endpoints | ⏳ | |
| Console Errors | ⏳ | |
| Mobile Responsive | ⏳ | |

---

## Sign-off

**Verified by:** _________________  
**Date:** _________________  
**Status:** ⏳ Pending Verification

---

## Troubleshooting

### White Screen on Route
- Check Vercel logs for build errors
- Verify `vercel.json` rewrites are correct
- Clear browser cache

### 500 on API
- Check Vercel Function logs
- Verify DATABASE_URL is set
- Check JWT_SECRET is set

### Login Fails
- Verify user exists in database
- Check password hash
- Verify JWT_SECRET matches
