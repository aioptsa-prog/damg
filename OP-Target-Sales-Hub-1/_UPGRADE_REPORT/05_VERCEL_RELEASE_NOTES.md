# 05_VERCEL_RELEASE_NOTES - ملاحظات النشر

**تاريخ:** 2026-01-03

---

## ⚙️ Vercel Configuration

### vercel.json
```json
{
  "framework": "vite",
  "buildCommand": "npm run build",
  "outputDirectory": "dist",
  "functions": {
    "api/**/*.ts": {
      "runtime": "@vercel/node@3.2.0"
    }
  }
}
```

### package.json engines
```json
{
  "engines": {
    "node": "20.x"
  }
}
```

---

## 🔐 Environment Variables (Production)

| Variable | الحالة | ملاحظات |
|----------|--------|---------|
| DATABASE_URL | ✅ موجود | Neon PostgreSQL |
| DATABASE_URL_UNPOOLED | ✅ موجود | للـ migrations |
| JWT_SECRET | ✅ موجود | Token signing |
| ENCRYPTION_SECRET | ✅ موجود | Data encryption |
| SEED_SECRET | ✅ موجود | Seed protection |
| ADMIN_EMAIL | ✅ موجود | Initial admin |
| ADMIN_PASSWORD | ✅ موجود | Initial admin |
| NODE_ENV | ⚠️ موجود | Vercel يحدده تلقائياً (يُفضل حذفه) |

---

## 🚀 Build Settings

| Setting | Value |
|---------|-------|
| Framework | Vite |
| Build Command | `npm run build` |
| Output Directory | `dist` |
| Install Command | `npm install` |
| Node.js Version | 20.x |

---

## 📁 API Functions

| Function | Path | Runtime |
|----------|------|---------|
| auth | `/api/auth` | @vercel/node@3.2.0 |
| me | `/api/me` | @vercel/node@3.2.0 |
| leads | `/api/leads` | @vercel/node@3.2.0 |
| users | `/api/users` | @vercel/node@3.2.0 |
| reports | `/api/reports` | @vercel/node@3.2.0 |
| tasks | `/api/tasks` | @vercel/node@3.2.0 |
| activities | `/api/activities` | @vercel/node@3.2.0 |
| analytics | `/api/analytics` | @vercel/node@3.2.0 |
| settings | `/api/settings` | @vercel/node@3.2.0 |
| logs | `/api/logs` | @vercel/node@3.2.0 |
| seed | `/api/seed` | @vercel/node@3.2.0 |
| logout | `/api/logout` | @vercel/node@3.2.0 |
| change-password | `/api/change-password` | @vercel/node@3.2.0 |
| reset-password | `/api/reset-password` | @vercel/node@3.2.0 |

---

## 🔒 Security Notes

### Seed Endpoint
- **Production:** محظور (`NODE_ENV === 'production'` → 403)
- **Preview:** يعمل مع `SEED_SECRET`

### ADMIN Credentials
- تُستخدم مرة واحدة للـ seed
- يُفضل حذفها بعد إنشاء admin user
- أو تغييرها لقيم مختلفة

---

## ✅ Pre-Deploy Checklist

- [x] `npm run build` يعمل
- [x] `engines.node` = 20.x
- [x] `vercel.json` configured
- [x] Environment variables set
- [x] No secrets in code
- [x] Seed endpoint blocked in production

---

## 🎯 Post-Deploy Actions

1. **Verify deployment:**
   ```bash
   curl https://op-target-sales-hub.vercel.app/api/me
   # Expected: 401 Unauthorized
   ```

2. **Seed admin (Preview only):**
   ```bash
   curl -X POST https://[preview-url]/api/seed \
     -H "Content-Type: application/json" \
     -d '{"secret":"YOUR_SEED_SECRET"}'
   ```

3. **Test login:**
   ```bash
   curl -X POST https://op-target-sales-hub.vercel.app/api/auth \
     -H "Content-Type: application/json" \
     -d '{"email":"admin@optarget.com","password":"..."}'
   ```
