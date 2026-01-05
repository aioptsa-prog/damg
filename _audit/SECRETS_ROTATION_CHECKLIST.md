# Secrets Rotation Checklist
> تاريخ التسريب المكتشف: 2026-01-05
> سبب التسريب: `.env` files were committed to git history

---

## ⚠️ CRITICAL: All secrets below MUST be rotated before production!

---

## 1. OP-Target (Vercel)

### Location: Vercel Dashboard → Settings → Environment Variables

| Secret | Status | New Value Generated |
|--------|--------|---------------------|
| `DATABASE_URL` | ⏳ Pending | [ ] |
| `JWT_SECRET` | ⏳ Pending | [ ] |
| `ENCRYPTION_SECRET` | ⏳ Pending | [ ] |
| `SEED_SECRET` | ⏳ Pending | [ ] |
| `GEMINI_API_KEY` | ⏳ Pending | [ ] |
| `OPENAI_API_KEY` | ⏳ Pending | [ ] |
| `INTEGRATION_SHARED_SECRET` | ⏳ Pending | [ ] |

### How to generate new secrets:
```bash
# For JWT_SECRET, ENCRYPTION_SECRET, SEED_SECRET:
openssl rand -base64 32

# For INTEGRATION_SHARED_SECRET:
openssl rand -hex 32
```

### Neon Database Password:
1. Go to https://console.neon.tech/
2. Select your project
3. Go to Settings → Connection Details
4. Click "Reset Password"
5. Update `DATABASE_URL` in Vercel

---

## 2. Forge (VPS/Local)

### Location: `forge.op-tg.com/.env`

| Secret | Status | New Value Generated |
|--------|--------|---------------------|
| `INTERNAL_SECRET` | ⏳ Pending | [ ] |
| `WHATSAPP_API_TOKEN` | ⏳ Pending | [ ] |
| `WHATSAPP_PHONE_ID` | ⏳ Pending | [ ] |
| `GEMINI_API_KEY` | ⏳ Pending | [ ] |
| `OPENAI_API_KEY` | ⏳ Pending | [ ] |
| `SERPAPI_KEY` | ⏳ Pending | [ ] |
| `INTEGRATION_SHARED_SECRET` | ⏳ Pending | [ ] |

### How to rotate:
```bash
# Generate new INTERNAL_SECRET:
openssl rand -hex 32

# WhatsApp: Go to Meta Business Suite → WhatsApp → API Setup → Generate new token

# Gemini: Go to https://aistudio.google.com/apikey → Create new key → Delete old

# OpenAI: Go to https://platform.openai.com/api-keys → Create new → Revoke old

# SerpAPI: Go to https://serpapi.com/manage-api-key → Regenerate
```

---

## 3. Worker

### Location: `forge.op-tg.com/worker/.env` or `worker.env`

| Secret | Status | New Value Generated |
|--------|--------|---------------------|
| `INTERNAL_SECRET` | ⏳ Pending | [ ] |
| `BASE_URL` | N/A (not a secret) | - |

**Note:** `INTERNAL_SECRET` must match Forge's `INTERNAL_SECRET`

---

## 4. Webhook Secrets (if applicable)

| Service | Secret | Status |
|---------|--------|--------|
| WhatsApp Webhook | Verify Token | ⏳ Pending |
| GitHub Webhooks | Secret | ⏳ Pending |

---

## Verification Steps

After rotating all secrets:

1. [ ] Test OP-Target login
2. [ ] Test Forge API health
3. [ ] Test Worker job pull
4. [ ] Test WhatsApp send (sandbox)
5. [ ] Test AI report generation

---

## Post-Rotation

- [ ] Delete this file after all secrets are rotated
- [ ] Update team members with new access
- [ ] Monitor logs for any auth failures

---

> **Completed by:** _______________
> **Date:** _______________
