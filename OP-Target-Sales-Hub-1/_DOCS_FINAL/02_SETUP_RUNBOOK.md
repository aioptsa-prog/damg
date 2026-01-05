# 02_SETUP_RUNBOOK - دليل التشغيل

---

## 🚀 التشغيل السريع

```bash
# 1. Clone & Install
git clone <repo>
cd OP-Target-Sales-Hub-1
npm install

# 2. Configure Environment
copy .env.example .env
# Edit .env with your values

# 3. Run Development
npm run dev

# 4. Seed Admin (first time only)
curl -X POST http://localhost:3000/api/seed \
  -H "Content-Type: application/json" \
  -d '{"secret":"YOUR_SEED_SECRET"}'
```

---

## ⚙️ Environment Variables

### Required

| Variable | Description | Example |
|----------|-------------|---------|
| `DATABASE_URL` | Neon PostgreSQL (pooled) | `postgresql://user:***@ep-xxx.neon.tech/db?sslmode=require` |
| `JWT_SECRET` | JWT signing key (32+ chars) | `openssl rand -base64 32` |
| `ENCRYPTION_SECRET` | Data encryption key | `openssl rand -base64 32` |
| `SEED_SECRET` | Protect seed endpoint | Any secret string |
| `ADMIN_EMAIL` | Initial admin email | `admin@example.com` |
| `ADMIN_PASSWORD` | Initial admin password | Strong password |

### Optional

| Variable | Description | Default |
|----------|-------------|---------|
| `NODE_ENV` | Environment | `development` |
| `GEMINI_API_KEY` | Gemini API | Set via UI |
| `OPENAI_API_KEY` | OpenAI API | Set via UI |

---

## 🐘 Database Setup

### Option 1: Neon (Recommended)
1. Create account at [neon.tech](https://neon.tech)
2. Create new database
3. Copy connection string to `DATABASE_URL`

### Option 2: Docker (Local)
```bash
docker-compose -f deployment/docker-compose.yml up -d db
DATABASE_URL=postgresql://opt_user:password@localhost:5432/op_target
```

---

## 🔧 Troubleshooting

| Issue | Solution |
|-------|----------|
| `DATABASE_URL required` | Add to .env |
| `JWT_SECRET required` | Add to .env |
| `401 on all requests` | Check cookie is set |
| `Seed failed` | Check SEED_SECRET matches |
| `bcrypt error` | Run `npm rebuild bcrypt` |

---

## 📋 Commands

| Command | Description |
|---------|-------------|
| `npm run dev` | Development server (3000) |
| `npm run build` | Production build |
| `npm run preview` | Preview production |
| `npm test` | Run tests |
