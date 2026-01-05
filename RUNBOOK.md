# دمج Monorepo - RUNBOOK
> آخر تحديث: 2026-01-05

---

## هيكل المشروع

```
دمج/
├── forge.op-tg.com/     # PHP Backend (Forge)
│   ├── api/             # Worker APIs (pull_job, report_results)
│   ├── v1/api/          # Public APIs (WhatsApp, campaigns, etc.)
│   ├── lib/             # Shared libraries (auth, validation, cors)
│   ├── worker/          # Node.js Playwright worker
│   └── config/          # Database config
│
├── OP-Target-Sales-Hub-1/  # React + Vercel (OP-Target)
│   ├── api/             # Vercel Serverless Functions
│   ├── components/      # React components
│   ├── services/        # Business logic
│   └── database/        # PostgreSQL migrations
│
└── _audit/              # Sprint reports and documentation
```

---

## ملاحظات مهمة

### 1. Monorepo Structure

هذا المشروع **monorepo** يحتوي على مشروعين:
- **Forge** (`forge.op-tg.com/`): PHP backend + SQLite
- **OP-Target** (`OP-Target-Sales-Hub-1/`): React + Vercel + PostgreSQL

تم تحويل OP-Target من submodule إلى **git subtree** في 2026-01-05.

### 2. OP-Target API Runtime

ملفات `OP-Target-Sales-Hub-1/api/*.ts` تعمل كـ **Vercel Serverless Functions**:
- ليست bundled للمتصفح
- تعمل على Node.js في Vercel Edge
- تستخدم PostgreSQL (Neon) للـ database

```json
// vercel.json
{
  "rewrites": [
    { "source": "/api/(.*)", "destination": "/api/$1" }
  ]
}
```

### 3. Subtree Sync (اختياري)

لسحب تحديثات من الريبو الأصلي:

```bash
git subtree pull --prefix=OP-Target-Sales-Hub-1 \
  https://github.com/opthupsa-alt/OP-Target-Sales-Hub.git main --squash
```

لدفع تغييرات للريبو الأصلي:

```bash
git subtree push --prefix=OP-Target-Sales-Hub-1 \
  https://github.com/opthupsa-alt/OP-Target-Sales-Hub.git main
```

---

## Environment Variables

### ⚠️ Security Warning

**NEVER commit `.env` files to git!** They contain secrets.

If you accidentally committed `.env`:
1. **Rotate ALL keys immediately** (Gemini, OpenAI, WhatsApp, JWT, DB)
2. Run `git rm --cached .env` to stop tracking
3. Consider using `git filter-repo` to clean history

### Setup Instructions

1. Copy the example files:
   ```bash
   cp forge.op-tg.com/.env.example forge.op-tg.com/.env
   cp OP-Target-Sales-Hub-1/.env.example OP-Target-Sales-Hub-1/.env
   cp forge.op-tg.com/worker/.env.example forge.op-tg.com/worker/.env
   ```

2. Fill in your values (see `.env.example` for documentation)

3. For Vercel deployment:
   - Add environment variables in Vercel Dashboard → Settings → Environment Variables
   - Required: `DATABASE_URL`, `JWT_SECRET`, `ENCRYPTION_SECRET`

4. For VPS deployment:
   - Copy `.env` files to server
   - Ensure proper file permissions: `chmod 600 .env`

### Required Variables

| Project | Variable | Description |
|---------|----------|-------------|
| Forge | `INTERNAL_SECRET` | Worker HMAC auth |
| Forge | `ALLOWED_ORIGINS` | CORS allowlist |
| Forge | `WHATSAPP_API_TOKEN` | WhatsApp Business API |
| OP-Target | `DATABASE_URL` | PostgreSQL connection |
| OP-Target | `JWT_SECRET` | JWT signing key |
| OP-Target | `ENCRYPTION_SECRET` | Data encryption |
| Worker | `BASE_URL` | Forge API URL |
| Worker | `INTERNAL_SECRET` | Must match Forge |

---

## Feature Flags

الـ flags مخزنة في جدول `settings` في Forge:

| Flag | الوظيفة |
|------|---------|
| `integration_auth_bridge` | Auth bridge بين المشروعين |
| `integration_survey_from_lead` | توليد استبيانات من Leads |
| `integration_send_from_report` | إرسال WhatsApp من التقارير |
| `integration_unified_lead_view` | عرض موحد للـ Leads |
| `integration_worker_enabled` | تكامل Worker |

لتفعيل/تعطيل:

```bash
php ops/enable_integration_flags.php
```

---

## Development

### Forge (PHP)

```bash
cd forge.op-tg.com
php -S localhost:8081
```

### OP-Target (React)

```bash
cd OP-Target-Sales-Hub-1
npm install
npm run dev
```

### Worker (Node.js)

```bash
cd forge.op-tg.com/worker
npm install
node index.js
```

---

## Testing

### Forge API

```bash
# Health check
curl http://localhost:8081/api/health.php

# Rate limit test
curl -i -X POST "http://localhost:8081/v1/api/whatsapp/send.php" \
  -H "Origin: http://localhost:3000" \
  -H "Content-Type: application/json" \
  -d '{"phone": "966500000000", "message": "test"}'
```

### OP-Target

```bash
cd OP-Target-Sales-Hub-1
npm run test        # Unit tests
npm run test:e2e    # Playwright E2E
```

---

## Sprint History

| Sprint | التاريخ | الإنجازات |
|--------|---------|-----------|
| 0 | 2026-01-05 | Git init, CORS allowlist, Rate limiting |
| 1 | 2026-01-05 | Auth/RBAC, Validation, Security headers |
| 2 | 2026-01-05 | Core flow docs, Server rate limit, Feature flags |

---

## Troubleshooting

### "fatal: no submodule mapping found"

هذا الخطأ يظهر إذا كان هناك gitlink بدون `.gitmodules`. تم إصلاحه بتحويل إلى subtree.

### CORS errors

تأكد من أن `ALLOWED_ORIGINS` يحتوي على الـ origin الصحيح.

### Rate limit 429

انتظر انتهاء النافذة الزمنية أو زد الحد في `.env`.

---

> **المسؤول**: فريق التطوير
> **آخر مراجعة**: 2026-01-05
