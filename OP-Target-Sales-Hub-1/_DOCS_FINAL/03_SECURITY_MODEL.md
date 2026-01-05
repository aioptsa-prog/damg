# 03_SECURITY_MODEL - نموذج الأمان

---

## 🔐 Authentication Flow

```
1. User submits email/password
   └─→ POST /api/auth

2. Server validates
   └─→ bcrypt.compare(password, hash)

3. On success
   └─→ Generate JWT (24h expiry)
   └─→ Set httpOnly cookie
   └─→ Return user (no password_hash)

4. Subsequent requests
   └─→ Cookie sent automatically
   └─→ _auth.ts extracts JWT
   └─→ Validates signature + expiry
```

---

## 🍪 Cookie Configuration

**Source:** `api/auth.ts:128-131`

```typescript
auth_token=${token}; HttpOnly; Secure; SameSite=Strict; Path=/; Max-Age=86400
```

| Flag | Purpose |
|------|---------|
| `HttpOnly` | Prevents XSS from reading token |
| `Secure` | HTTPS only (production) |
| `SameSite=Strict` | Prevents CSRF |
| `Max-Age=86400` | 24 hour expiry |

---

## 👥 RBAC Matrix

**Source:** `api/_auth.ts`

| Endpoint | SUPER_ADMIN | MANAGER | SALES_REP |
|----------|-------------|---------|-----------|
| GET /leads | ✅ all | ✅ team | ✅ own |
| POST /leads | ✅ | ✅ | ✅ own |
| DELETE /leads | ✅ | ✅ team | ✅ own |
| GET /users | ✅ | ❌ | ❌ |
| POST /users | ✅ | ❌ | ❌ |
| GET /settings | ✅ | ❌ | ❌ |
| GET /logs/audit | ✅ | ❌ | ❌ |
| GET /analytics | ✅ all | ✅ team | ✅ own |

---

## 🔑 Password Management

### At Login
```typescript
// api/auth.ts
const isValid = await bcrypt.compare(password, user.passwordHash);
```

### Admin Reset
```typescript
// api/reset-password.ts (SUPER_ADMIN only)
// Sets must_change_password = true
```

### User Change
```typescript
// api/change-password.ts
// Requires current password
// Clears must_change_password
```

---

## 🌱 Seed Policy

**Source:** `api/seed.ts`

- Requires `SEED_SECRET` in request body
- Only runs if no SUPER_ADMIN exists
- Reads `ADMIN_EMAIL` and `ADMIN_PASSWORD` from ENV

**⚠️ PRODUCTION GUARD NEEDED:**
```typescript
if (process.env.NODE_ENV === 'production' && !process.env.ALLOW_SEED) {
  return res.status(403).json({ error: 'Seed disabled in production' });
}
```

---

## ⏱️ Rate Limiting

**Source:** `api/auth.ts:36-56`

| Setting | Value |
|---------|-------|
| Window | 15 minutes |
| Max attempts | 5 |
| Storage | In-memory Map |

**⚠️ Limitation:** Resets on server restart. Use Redis for production.

---

## 🔒 Secrets Management

| Secret | Storage | Never In |
|--------|---------|----------|
| Database credentials | ENV | Code, git, frontend |
| JWT secret | ENV | Code, git |
| Encryption secret | ENV | Code, git |
| AI API keys | Database (settings) | Frontend bundle |
