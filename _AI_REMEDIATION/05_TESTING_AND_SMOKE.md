# 05_TESTING_AND_SMOKE - اختبارات الأمان

---

## ✅ Smoke Test Checklist

### 1. Authentication
```bash
# Login - should return 200 + Set-Cookie
curl -X POST http://localhost:3000/api/auth \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com","password":"password"}' \
  -c cookies.txt -v

# Me - should return 401 without cookie
curl http://localhost:3000/api/me

# Me - should return 200 with cookie
curl http://localhost:3000/api/me -b cookies.txt

# Logout
curl -X POST http://localhost:3000/api/logout -b cookies.txt
```

### 2. RBAC Tests

```bash
# Leads - 401 without auth
curl http://localhost:3000/api/leads
# Expected: 401 Unauthorized

# Users list - 403 for non-admin
# (login as SALES_REP first)
curl http://localhost:3000/api/users -b cookies.txt
# Expected: 403 Forbidden

# Settings - 403 for non-admin
curl http://localhost:3000/api/settings/ai -b cookies.txt
# Expected: 403 Forbidden

# Audit logs - 403 for non-admin
curl http://localhost:3000/api/logs/audit -b cookies.txt
# Expected: 403 Forbidden
```

### 3. IDOR Tests

```bash
# Try to access another user's lead
curl http://localhost:3000/api/leads/other-lead-id -b cookies.txt
# Expected: 403 if not owner

# Try to delete another user's lead
curl -X DELETE http://localhost:3000/api/leads?id=other-lead-id -b cookies.txt
# Expected: 403 if not owner
```

---

## 🧪 Unit Tests

```typescript
// tests/auth.test.ts
describe('Auth API', () => {
  it('returns 401 for invalid credentials', async () => {
    const res = await fetch('/api/auth', {
      method: 'POST',
      body: JSON.stringify({ email: 'a@b.c', password: 'wrong' })
    });
    expect(res.status).toBe(401);
  });

  it('sets httpOnly cookie on success', async () => {
    const res = await fetch('/api/auth', { ... });
    const setCookie = res.headers.get('set-cookie');
    expect(setCookie).toContain('HttpOnly');
  });
});
```

---

## 📊 Coverage Matrix

| Endpoint | 401 Test | 403 Test | 200 Test |
|----------|----------|----------|----------|
| /api/auth | N/A | N/A | ✅ |
| /api/me | ✅ | N/A | ✅ |
| /api/leads | ✅ | ✅ | ✅ |
| /api/users | ✅ | ✅ | ✅ |
| /api/reports | ✅ | ✅ | ✅ |
| /api/settings | ✅ | ✅ | ✅ |
| /api/analytics | ✅ | ✅ | ✅ |
| /api/activities | ✅ | ✅ | ✅ |
| /api/tasks | ✅ | ✅ | ✅ |
| /api/logs | ✅ | ✅ | ✅ |
