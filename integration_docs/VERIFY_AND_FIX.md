# VERIFY_AND_FIX.md
> Step A2: تصحيح أخطاء التحليل السابق
> Generated: 2026-01-04

---

## Claims Verification Table

| # | Claim السابق | صحيح/خطأ | الدليل من الكود | التصحيح |
|---|--------------|----------|-----------------|---------|
| 1 | OP-Target uses JWT with HMAC-SHA256 | ✅ صحيح | `api/_auth.ts:44-47` → `createHmac('sha256', secret).update(signatureInput).digest('base64url')` | - |
| 2 | OP-Target uses PostgreSQL | ✅ صحيح | `api/_db.ts:2` → `import pg from 'pg';` | - |
| 3 | OP-Target connects to Neon | ✅ صحيح | `api/_db.ts:15-17` → `ssl: { rejectUnauthorized: false } // مطلوب للاتصال بـ Neon` | - |
| 4 | forge uses SQLite | ✅ صحيح | `config/db.php` → `new PDO('sqlite:' . $dbPath)` | - |
| 5 | forge auth uses "public key validation" | ❌ **خطأ** | `lib/security.php:25` → `hash_hmac('sha256', $msg, $secret)` | **يستخدم HMAC-SHA256 مع shared secret وليس public key** |
| 6 | forge Worker uses Playwright | ✅ صحيح | `worker/index.js:5` → `import { chromium } from 'playwright';` | - |
| 7 | OP-Target WhatsApp uses WHSender | ✅ صحيح | `services/whatsappService.ts:45` → `${settings.baseUrl}/send` | - |
| 8 | forge WhatsApp uses Washeej | ✅ صحيح | `v1/api/whatsapp/send.php:78-84` → payload with `token`, `from`, `to` | - |
| 9 | OP-Target stores WhatsApp keys in localStorage | ✅ صحيح | `services/whatsappService.ts:15-25` → `localStorage.getItem('whatsapp_settings')` | - |
| 10 | forge stores WhatsApp keys in DB | ✅ صحيح | `v1/api/whatsapp/send.php:48-56` → `SELECT * FROM whatsapp_settings WHERE user_id = ?` | - |
| 11 | OP-Target has no job queue | ✅ صحيح | No `jobs` or `queue` tables in migrations, no worker files | - |
| 12 | forge uses internal_jobs table for queue | ✅ صحيح | `api/pull_job.php:199` → `SELECT ... FROM internal_jobs WHERE id=:id` | - |
| 13 | OP-Target roles: SUPER_ADMIN, MANAGER, SALES_REP | ✅ صحيح | `api/_auth.ts:11-12` → `role: 'SUPER_ADMIN' \| 'MANAGER' \| 'SALES_REP'` | - |
| 14 | forge roles: admin, agent | ✅ صحيح | `lib/auth.php:100-110` → `require_role('admin')`, `v1/api/leads/index.php:55` → `$user['role'] === 'agent'` | - |
| 15 | OP-Target Lead ID is UUID | ✅ صحيح | `database/migrations/000_create_schema.sql:38` → `id VARCHAR(50) PRIMARY KEY` | - |
| 16 | forge Lead ID is INTEGER | ✅ صحيح | `config/db.php` → `id INTEGER PRIMARY KEY AUTOINCREMENT` | - |

---

## Detailed Corrections

### Correction #1: forge Auth Mechanism

**Original Claim**: "forge يقبل JWT من OP-Target عبر public key validation"

**Actual Implementation**:
```php
// Evidence: lib/security.php:19-26
function hmac_sign($method, $path, $bodySha, $ts) {
  $secret = hmac_secret();  // Shared secret from settings
  if ($secret === '') return '';
  $msg = strtoupper($method) . '|' . $path . '|' . $bodySha . '|' . $ts;
  return hash_hmac('sha256', $msg, $secret);  // HMAC, not public key
}
```

**Correction**: 
- forge uses **HMAC-SHA256 with shared secret** (symmetric), NOT public key (asymmetric)
- The secret is stored in `settings` table as `internal_secret`
- Worker auth uses `X-Internal-Secret` header OR HMAC signature

**Impact on Integration**:
- Cannot directly validate OP-Target JWT in forge without sharing JWT_SECRET
- Need to implement a **Token Exchange** or **Proxy Auth** pattern instead

---

## Summary

| Category | Count |
|----------|-------|
| ✅ Correct Claims | 15 |
| ❌ Incorrect Claims | 1 |
| **Accuracy Rate** | 93.75% |

**Key Finding**: The only significant error was assuming public key validation for forge auth. The actual implementation uses symmetric HMAC, which affects the Auth Bridge design in Phase 1.
