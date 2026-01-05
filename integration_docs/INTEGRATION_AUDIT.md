# INTEGRATION_AUDIT.md
> Step A: Project Discovery with Evidence
> Generated: 2026-01-04

---

## A) Project Inventory

### Project A: OP-Target-Sales-Hub-1 (Survey/Analysis System)

#### Tech Stack

| Component | Value | Evidence |
|-----------|-------|----------|
| **Frontend Framework** | React 19.0.0 | `package.json:17` → `"react": "^19.0.0"` |
| **Build Tool** | Vite 6.0.7 | `package.json:30` → `"vite": "^6.0.7"` |
| **Language** | TypeScript 5.6.3 | `package.json:29` → `"typescript": "~5.6.3"` |
| **Runtime** | Node.js | `package.json:6` → `"dev": "vite"` |
| **Styling** | TailwindCSS 3.4.17 | `package.json:28` → `"tailwindcss": "^3.4.17"` |
| **UI Components** | Radix UI | `package.json:7-14` → multiple `@radix-ui/*` packages |

#### Run/Build/Test Commands

| Command | Script | Evidence |
|---------|--------|----------|
| **Dev Server** | `npm run dev` | `package.json:6` → `"dev": "vite"` |
| **Build** | `npm run build` | `package.json:7` → `"build": "vite build"` |
| **Preview** | `npm run preview` | `package.json:8` → `"preview": "vite preview"` |
| **Unit Tests** | `npm run test` | `package.json:9` → `"test": "vitest"` |
| **E2E Tests** | `npm run test:e2e` | `package.json:10` → `"test:e2e": "playwright test"` |
| **DB Migrate** | `npm run db:migrate` | `package.json:11` → `"db:migrate": "node scripts/migrate.js"` |

#### Database

| Property | Value | Evidence |
|----------|-------|----------|
| **Driver** | PostgreSQL (pg) | `api/_db.ts:2` → `import pg from 'pg';` |
| **Connection** | Pool via DATABASE_URL | `api/_db.ts:7-18` → `const connectionString = process.env.DATABASE_URL;` |
| **Provider** | Neon (cloud PostgreSQL) | `api/_db.ts:15-17` → `ssl: { rejectUnauthorized: false } // مطلوب للاتصال بـ Neon` |
| **Tables** | users, leads, reports, tasks, activities, audit_logs, usage_logs, settings, teams | `database/migrations/000_create_schema.sql:8-145` |

```sql
-- Evidence: database/migrations/000_create_schema.sql:37-63
CREATE TABLE IF NOT EXISTS leads (
    id VARCHAR(50) PRIMARY KEY,
    company_name VARCHAR(255) NOT NULL,
    activity TEXT,
    city VARCHAR(100),
    phone VARCHAR(50),
    status VARCHAR(20) DEFAULT 'NEW',
    owner_user_id VARCHAR(50) REFERENCES users(id),
    ...
);
```

#### Authentication

| Property | Value | Evidence |
|----------|-------|----------|
| **Method** | JWT (JSON Web Token) | `api/_auth.ts:24` → `function verifyToken(token: string)` |
| **Signing** | HMAC-SHA256 | `api/_auth.ts:44-47` → `createHmac('sha256', secret).update(signatureInput).digest('base64url')` |
| **Storage** | HttpOnly Cookie | `api/_auth.ts:63-64` → `cookies.match(/auth_token=([^;]+)/)` |
| **Secret** | JWT_SECRET env var | `api/_auth.ts:25` → `const secret = process.env.JWT_SECRET;` |
| **Roles** | SUPER_ADMIN, MANAGER, SALES_REP | `api/_auth.ts:11-12` → `role: 'SUPER_ADMIN' \| 'MANAGER' \| 'SALES_REP'` |

```typescript
// Evidence: api/_auth.ts:44-51
const signatureInput = `${parts[0]}.${parts[1]}`;
const expectedSignature = createHmac('sha256', secret)
    .update(signatureInput)
    .digest('base64url');

if (parts[2] !== expectedSignature) {
    return null;
}
```

#### WhatsApp Integration

| Property | Value | Evidence |
|----------|-------|----------|
| **Provider** | WHSender API | `services/whatsappService.ts:45` → `${settings.baseUrl}/send` |
| **Auth Storage** | localStorage (encrypted) | `services/whatsappService.ts:15-25` → `localStorage.getItem('whatsapp_settings')` |
| **Fallback** | wa.me link | `services/whatsappService.ts:70-75` → `window.open(\`https://wa.me/...\`)` |

```typescript
// Evidence: services/whatsappService.ts:40-55
const response = await fetch(`${settings.baseUrl}/send`, {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'Authorization': `Bearer ${decryptedApiKey}`
  },
  body: JSON.stringify({
    to: phone,
    message: message,
    sender_id: settings.senderId
  })
});
```

#### Workers/Jobs/Queues

| Property | Value |
|----------|-------|
| **Status** | لا يوجد نظام workers/jobs في هذا المشروع |

---

### Project B: forge.op-tg.com (Search + WhatsApp System)

#### Tech Stack

| Component | Value | Evidence |
|-----------|-------|----------|
| **Backend** | PHP 7.4+ | `bootstrap.php:1` → `<?php` |
| **Frontend (Admin)** | PHP Server-rendered | `admin/*.php` files |
| **Frontend (Public)** | React SPA | `saudi-lead-iq-main/package.json` → React + Vite |
| **Worker** | Node.js + Playwright | `worker/package.json:12` → `"playwright": "^1.49.1"` |

#### Run/Build/Test Commands

| Command | Script | Evidence |
|---------|--------|----------|
| **PHP Dev Server** | `php -S localhost:8080` | Standard PHP built-in server |
| **Worker Start** | `npm start` (in worker/) | `worker/package.json:6` → `"start": "node index.js"` |
| **Worker Health** | `npm run health` | `worker/package.json:7` → `"health": "node -e \"fetch('http://127.0.0.1:4499/status')..."` |
| **Install Chromium** | `npm run postinstall` | `worker/package.json:8` → `"postinstall": "npx playwright install chromium"` |

#### Database

| Property | Value | Evidence |
|----------|-------|----------|
| **Driver** | SQLite | `config/db.php:5-10` → `new PDO('sqlite:' . $dbPath)` |
| **Location** | `data/app.db` | `config/db.php` → default path |
| **Tables** | users, leads, sessions, assignments, settings, internal_jobs, categories, washeej_logs, audit_logs, internal_workers, job_attempts | `config/db.php:50-464` |

```php
// Evidence: config/db.php (structure)
// Tables created:
// - users (id, username, mobile, password_hash, role, ...)
// - leads (id, phone, phone_norm, name, city, category_id, ...)
// - internal_jobs (id, query, ll, status, worker_id, lease_expires_at, ...)
// - washeej_logs (id, agent_id, phone, message, status, ...)
```

#### Authentication

| Property | Value | Evidence |
|----------|-------|----------|
| **Method** | Session + Bearer Token | `lib/auth.php:20-50` → `$_SESSION['user_id']` + `Authorization: Bearer` |
| **Worker Auth** | HMAC-SHA256 | `lib/security.php:19-26` → `hash_hmac('sha256', $msg, $secret)` |
| **Remember Cookie** | SHA256 token in DB | `lib/auth.php:60-80` → `REMEMBER_COOKIE` |
| **Roles** | admin, agent | `lib/auth.php:100-110` → `require_role('admin')` |

```php
// Evidence: lib/security.php:19-26
function hmac_sign($method, $path, $bodySha, $ts) {
  $secret = hmac_secret();
  if ($secret === '') return '';
  $msg = strtoupper($method) . '|' . $path . '|' . $bodySha . '|' . $ts;
  return hash_hmac('sha256', $msg, $secret);
}
```

```php
// Evidence: lib/security.php:72-88
function verify_worker_auth(?string $workerId, string $method, string $path): bool {
  // Try HMAC first
  $hmacOk = hmac_verify_request($method, $path);
  // If HMAC not provided or failed, try legacy X-Internal-Secret
  if (!$hmacOk) {
    $secretHeader = header_get('X-Internal-Secret') ?? '';
    $internalSecret = get_setting('internal_secret', '');
    if ($secretHeader !== '' && $internalSecret !== '' && hash_equals($internalSecret, $secretHeader)) {
      $hmacOk = true;
    }
  }
  ...
}
```

#### WhatsApp Integration

| Property | Value | Evidence |
|----------|-------|----------|
| **Provider** | Washeej API | `v1/api/whatsapp/send.php:78-84` → payload structure |
| **Auth** | Token from DB settings | `v1/api/whatsapp/send.php:48-56` → `whatsapp_settings` table |
| **Logging** | washeej_logs table | `v1/api/whatsapp/send.php:150-151` → INSERT INTO whatsapp_logs |

```php
// Evidence: v1/api/whatsapp/send.php:78-108
$payload = [
    'requestType' => 'POST',
    'token' => $settings['auth_token'],
    'from' => $settings['sender_number'],
    'to' => $recipient_number,
    'messageType' => $content_type
];
switch ($content_type) {
    case 'text':
        $payload['text'] = $message_text;
        break;
    case 'image':
        $payload['imageUrl'] = $media_url;
        ...
}
```

#### Workers/Jobs/Queues

| Property | Value | Evidence |
|----------|-------|----------|
| **Job Table** | internal_jobs | `api/pull_job.php:199` → `SELECT ... FROM internal_jobs` |
| **Pull Mechanism** | HTTP polling | `worker/index.js:283-299` → `async function pullJob()` |
| **Lease System** | lease_expires_at column | `api/pull_job.php:71-74` → `$leaseSec`, `$leaseUntil` |
| **Worker Registration** | internal_workers table | `api/pull_job.php:58` → `workers_upsert_seen()` |
| **Scraping Engine** | Playwright Chromium | `worker/index.js:5` → `import { chromium } from 'playwright';` |

```javascript
// Evidence: worker/index.js:283-299
async function pullJob() {
  const url = `${getBase()}/api/pull_job.php?lease_sec=${LEASE_SEC}`;
  const headers = authHeaders('GET', '/api/pull_job.php', '');
  const { status, ok, text } = await fetchText(url, { headers });
  ...
}
```

---

## B) Top 20 Key Files (Per Project)

### Project A: OP-Target-Sales-Hub-1

| # | Path | Purpose |
|---|------|---------|
| 1 | `api/_auth.ts` | JWT authentication middleware, RBAC |
| 2 | `api/_db.ts` | PostgreSQL connection pool |
| 3 | `api/leads.ts` | Leads CRUD API with RBAC |
| 4 | `api/reports.ts` | AI report generation, website enrichment |
| 5 | `api/auth.ts` | Login/logout endpoints |
| 6 | `services/whatsappService.ts` | WhatsApp message sending |
| 7 | `types.ts` | TypeScript interfaces (Lead, User, Report, etc.) |
| 8 | `database/migrations/000_create_schema.sql` | Database schema |
| 9 | `package.json` | Dependencies and scripts |
| 10 | `.env.example` | Environment variables template |
| 11 | `api/users.ts` | User management API |
| 12 | `api/tasks.ts` | Tasks API |
| 13 | `api/activities.ts` | Activity logging API |
| 14 | `api/settings.ts` | Settings API |
| 15 | `vite.config.ts` | Vite configuration |
| 16 | `App.tsx` | Main React application |
| 17 | `services/aiService.ts` | AI provider integration |
| 18 | `services/enrichmentService.ts` | Website data enrichment |
| 19 | `components/LeadDetail.tsx` | Lead detail view |
| 20 | `components/ReportView.tsx` | Report display component |

### Project B: forge.op-tg.com

| # | Path | Purpose |
|---|------|---------|
| 1 | `config/db.php` | SQLite connection + migrations |
| 2 | `lib/auth.php` | Session/token authentication |
| 3 | `lib/security.php` | HMAC signing, worker auth |
| 4 | `bootstrap.php` | Application bootstrap |
| 5 | `api/pull_job.php` | Worker job pulling endpoint |
| 6 | `api/report_results.php` | Worker results reporting |
| 7 | `v1/api/auth/login.php` | Public API login |
| 8 | `v1/api/leads/index.php` | Leads list API |
| 9 | `v1/api/whatsapp/send.php` | WhatsApp send API |
| 10 | `v1/api/whatsapp/settings.php` | WhatsApp settings API |
| 11 | `lib/wh_sender.php` | Legacy WhatsApp sender |
| 12 | `admin/leads.php` | Admin leads management |
| 13 | `admin/workers.php` | Workers management UI |
| 14 | `admin/settings.php` | System settings UI |
| 15 | `admin/health.php` | System health dashboard |
| 16 | `worker/index.js` | Playwright scraping worker |
| 17 | `worker/launcher.js` | Worker launcher script |
| 18 | `worker/package.json` | Worker dependencies |
| 19 | `lib/classify.php` | Lead classification logic |
| 20 | `lib/system.php` | System utilities |

---

## C) Comparison Table

| Aspect | OP-Target-Sales-Hub-1 | forge.op-tg.com |
|--------|----------------------|-----------------|
| **Primary Language** | TypeScript/Node.js | PHP |
| **Frontend** | React 19 SPA | PHP + React SPA |
| **Database** | PostgreSQL (Neon) | SQLite |
| **Auth Method** | JWT HMAC-SHA256 | Session + HMAC |
| **Auth Storage** | HttpOnly Cookie | Session + Cookie |
| **Roles** | SUPER_ADMIN, MANAGER, SALES_REP | admin, agent |
| **WhatsApp Provider** | WHSender | Washeej |
| **WhatsApp Auth** | localStorage (client) | DB settings (server) |
| **Workers** | None | Playwright Node.js |
| **Job Queue** | None | internal_jobs table |
| **Dev Port** | 5173 (Vite) | 8080 (PHP) |
| **Lead ID Type** | VARCHAR(50) UUID | INTEGER AUTO |
| **Lead Phone** | Optional VARCHAR(50) | Required TEXT (normalized) |

---

## D) Integration Surface (نقاط الدمج المحتملة)

### 1. Lead Identity Mapping
- **OP-Target**: `leads.id` (UUID string), `leads.phone` (optional)
- **forge**: `leads.id` (integer), `leads.phone_norm` (E.164 normalized)
- **Proposed**: Use `phone_norm` as canonical identifier for cross-system linking

### 2. Authentication Bridge
- **Challenge**: Different auth mechanisms (JWT vs Session)
- **Proposed**: Token Trust - forge accepts OP-Target JWT via public endpoint

### 3. WhatsApp Unification
- **Challenge**: Different providers (WHSender vs Washeej)
- **Proposed**: Adapter pattern supporting both providers

### 4. Survey/Intel Flow
- **Source**: `api/reports.ts` in OP-Target
- **Target**: Link to leads from forge via phone_norm
- **Proposed**: New endpoint in OP-Target accepting forge lead data

---

## E) Ports and Conflicts

| Service | Default Port | Configurable |
|---------|--------------|--------------|
| OP-Target Vite Dev | 5173 | Yes (vite.config.ts) |
| forge PHP Server | 8080 | Yes (CLI arg) |
| forge Worker UI | 4499 | Yes (env PORT) |
| forge Worker Status | 4499/status | Same as above |

**No port conflicts with default configuration.**
