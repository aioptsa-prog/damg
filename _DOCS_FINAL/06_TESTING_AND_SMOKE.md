# 06_TESTING_AND_SMOKE - الاختبارات

---

## ✅ Smoke Test Suite

### 1. Authentication

```bash
# Login - expect 200 + cookie
curl -X POST http://localhost:3000/api/auth \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com","password":"xxx"}' \
  -c cookies.txt -v
# Check: Set-Cookie header contains HttpOnly

# Me - expect 401 without cookie
curl http://localhost:3000/api/me
# Expected: 401

# Me - expect 200 with cookie
curl http://localhost:3000/api/me -b cookies.txt
# Expected: 200 + user object
```

### 2. RBAC (403 Tests)

```bash
# Login as SALES_REP first, then:

# Users list - expect 403
curl http://localhost:3000/api/users -b cookies.txt
# Expected: 403

# Settings - expect 403
curl http://localhost:3000/api/settings/ai -b cookies.txt
# Expected: 403

# Audit logs - expect 403
curl http://localhost:3000/api/logs/audit -b cookies.txt
# Expected: 403
```

### 3. IDOR Prevention

```bash
# Try to access another user's lead
curl http://localhost:3000/api/leads/OTHER_LEAD_ID -b cookies.txt
# Expected: 403 if not owner

# Try to delete
curl -X DELETE "http://localhost:3000/api/leads?id=OTHER_LEAD_ID" -b cookies.txt
# Expected: 403
```

---

## 🔑 mustChangePassword Flow

### Test Scenario
1. Admin resets user password
2. User logs in → `mustChangePassword: true`
3. User changes password
4. User logs in again → `mustChangePassword: false`

```bash
# Step 1: Admin reset
curl -X POST http://localhost:3000/api/reset-password \
  -H "Content-Type: application/json" \
  -d '{"userId":"USER_ID"}' \
  -b admin_cookies.txt

# Step 2: User login (check response)
# Should have: mustChangePassword: true

# Step 3: User change password
curl -X POST http://localhost:3000/api/change-password \
  -H "Content-Type: application/json" \
  -d '{"currentPassword":"temp","newPassword":"new123456"}' \
  -b user_cookies.txt
```

---

## 🔒 Production Flags Check

```bash
# Search build for secrets
findstr /s /i "API_KEY" dist/
findstr /s /i "postgresql://" dist/
findstr /s /i "admin123" dist/
# Expected: No matches
```

---

## 📊 Test Coverage

| Area | Status |
|------|--------|
| Auth (login/logout) | ⚠️ Manual |
| RBAC (401/403) | ⚠️ Manual |
| IDOR | ⚠️ Manual |
| Password flow | ⚠️ Manual |
| Unit tests | ❌ Minimal |
