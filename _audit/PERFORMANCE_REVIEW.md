# PERFORMANCE_REVIEW.md
> مراجعة الأداء والتحسينات
> تاريخ الإنشاء: 2026-01-05

---

## 1. ملخص تنفيذي

| المجال | الحالة | الأولوية |
|--------|--------|----------|
| **Bundle Size** | ❌ 918KB (كبير جداً) | P1 |
| **Database Queries** | ⚠️ يحتاج مراجعة | P2 |
| **Caching** | ❌ غير موجود | P2 |
| **API Response Time** | ✅ جيد (محلياً) | P3 |
| **Lazy Loading** | ⚠️ جزئي | P2 |

---

## 2. Frontend Performance (OP-Target)

### 2.1 Bundle Analysis

| الملف | الحجم | الحجم (gzip) | الملاحظة |
|-------|-------|--------------|----------|
| `index-DsVzjjw5.js` | 918.46 KB | 266.43 KB | ❌ كبير جداً |
| `ForgeIntelTab-BZ1yYjVV.js` | 27.53 KB | 6.67 KB | ✅ منفصل |
| `index-Y9OxWz8h.css` | 46.55 KB | 7.86 KB | ✅ جيد |

**الدليل**:
```
Run/Log: npm run build
Output: dist/assets/index-DsVzjjw5.js 918.46 kB │ gzip: 266.43 kB
Warning: Some chunks are larger than 500 kB after minification
```

### 2.2 مصادر الحجم الكبير (تقدير)

| المكتبة | الحجم التقديري | الملاحظة |
|---------|----------------|----------|
| `lucide-react` | ~200KB | 2451 icon |
| `recharts` | ~150KB | Charts library |
| `react-router-dom` | ~50KB | Routing |
| `zod` | ~50KB | Validation |
| Application code | ~400KB | Components |

### 2.3 خطة التحسين

#### أ) Code Splitting

```typescript
// vite.config.ts
export default defineConfig({
  build: {
    rollupOptions: {
      output: {
        manualChunks: {
          'vendor-react': ['react', 'react-dom', 'react-router-dom'],
          'vendor-charts': ['recharts'],
          'vendor-icons': ['lucide-react'],
        }
      }
    }
  }
});
```

#### ب) Lazy Loading للـ Routes

```typescript
// routes.tsx
const Dashboard = lazy(() => import('./components/Dashboard'));
const LeadDetails = lazy(() => import('./components/LeadDetails'));
const ReportView = lazy(() => import('./components/ReportView'));
const ForgeIntelTab = lazy(() => import('./components/ForgeIntelTab'));
```

#### ج) Tree Shaking للـ Icons

```typescript
// بدلاً من
import { Home, User, Settings, ... } from 'lucide-react';

// استخدم
import Home from 'lucide-react/dist/esm/icons/home';
```

### 2.4 الهدف

| Metric | الحالي | الهدف |
|--------|--------|-------|
| Main bundle | 918 KB | < 300 KB |
| Total JS | 946 KB | < 500 KB |
| First Load | غير مقاس | < 3s (3G) |
| TTI | غير مقاس | < 5s (3G) |

---

## 3. Database Performance

### 3.1 OP-Target (PostgreSQL)

#### استعلامات محتملة البطء

| الاستعلام | الموقع | المشكلة |
|-----------|--------|---------|
| `SELECT * FROM leads ORDER BY created_at DESC` | `api/leads.ts:17` | لا يوجد LIMIT |
| `SELECT * FROM leads WHERE team_id = $1` | `api/leads.ts:23` | يحتاج INDEX |

#### Indexes المطلوبة

```sql
-- leads table
CREATE INDEX IF NOT EXISTS idx_leads_owner ON leads(owner_user_id);
CREATE INDEX IF NOT EXISTS idx_leads_team ON leads(team_id);
CREATE INDEX IF NOT EXISTS idx_leads_created ON leads(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_leads_status ON leads(status);

-- audit_logs table
CREATE INDEX IF NOT EXISTS idx_audit_actor ON audit_logs(actor_user_id);
CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_logs(created_at DESC);
```

#### إضافة Pagination

```typescript
// api/leads.ts
const page = parseInt(queryParams.page) || 1;
const limit = Math.min(parseInt(queryParams.limit) || 20, 100);
const offset = (page - 1) * limit;

const leadsRes = await query(
  'SELECT * FROM leads WHERE owner_user_id = $1 ORDER BY created_at DESC LIMIT $2 OFFSET $3',
  [user.id, limit, offset]
);
```

### 3.2 Forge (SQLite)

#### الحالة الحالية

| الجدول | Indexes | الملاحظة |
|--------|---------|----------|
| `leads` | ✅ عديدة | `config/db.php:119-123` |
| `internal_jobs` | ✅ عديدة | `config/db.php:253-262` |
| `categories` | ✅ عديدة | `config/db.php:155-158` |

**التقييم**: ✅ جيد - Indexes موجودة

#### تحسينات SQLite

```php
// config/db.php - موجود بالفعل
$pdo->exec('PRAGMA journal_mode=WAL;');
$pdo->exec('PRAGMA synchronous=NORMAL;');
$pdo->exec('PRAGMA cache_size=-8000;');
```

---

## 4. API Performance

### 4.1 قياسات محلية

| Endpoint | Method | Response Time | الملاحظة |
|----------|--------|---------------|----------|
| `/api/auth` | POST | ~50ms | ✅ جيد |
| `/api/leads` | GET | ~30ms | ✅ جيد |
| `/api/reports` | POST | ~2-10s | ⚠️ AI generation |

**ملاحظة**: القياسات محلية، الإنتاج قد يختلف.

### 4.2 نقاط البطء المحتملة

| النقطة | السبب | الحل |
|--------|-------|------|
| AI Report Generation | External API call | Async + Queue |
| Large Lead Lists | No pagination | Add pagination |
| File Uploads | Sync processing | Async processing |

---

## 5. Caching Strategy

### 5.1 الحالة الحالية

| النوع | OP-Target | Forge |
|-------|-----------|-------|
| Browser Cache | ⚠️ Default | ⚠️ Default |
| API Cache | ❌ لا يوجد | ❌ لا يوجد |
| Database Cache | ❌ لا يوجد | ❌ لا يوجد |

### 5.2 التوصيات

#### أ) Static Assets (Vite)

```typescript
// vite.config.ts - موجود تلقائياً
// Vite يضيف hash للملفات = cache busting
```

#### ب) API Response Cache

```typescript
// api/settings.ts - مثال
res.setHeader('Cache-Control', 'private, max-age=300'); // 5 minutes
```

#### ج) Database Query Cache

```typescript
// services/cacheService.ts
const cache = new Map<string, { data: any; expires: number }>();

export function getCached<T>(key: string, ttlMs: number, fetcher: () => Promise<T>): Promise<T> {
  const cached = cache.get(key);
  if (cached && cached.expires > Date.now()) {
    return Promise.resolve(cached.data);
  }
  return fetcher().then(data => {
    cache.set(key, { data, expires: Date.now() + ttlMs });
    return data;
  });
}
```

---

## 6. N+1 Query Analysis

### 6.1 المشاكل المحتملة

| الموقع | الوصف | الحل |
|--------|-------|------|
| Leads with Users | جلب user لكل lead | JOIN |
| Reports with Leads | جلب lead لكل report | JOIN |

### 6.2 مثال الإصلاح

```typescript
// بدلاً من
const leads = await query('SELECT * FROM leads');
for (const lead of leads) {
  const user = await query('SELECT * FROM users WHERE id = $1', [lead.owner_user_id]);
}

// استخدم
const leads = await query(`
  SELECT l.*, u.name as owner_name, u.email as owner_email
  FROM leads l
  LEFT JOIN users u ON l.owner_user_id = u.id
`);
```

---

## 7. Worker Performance (Forge)

### 7.1 الإعدادات الحالية

| الإعداد | القيمة | الدليل |
|---------|--------|--------|
| `LEASE_SEC_DEFAULT` | 180s | `.env` |
| `PULL_INTERVAL_SEC` | 30s | `.env` |
| `worker_item_delay_ms` | 800ms | `config/db.php:300` |
| `worker_max_pages` | 5 | `config/db.php:300` |

### 7.2 تحسينات مقترحة

| الإعداد | الحالي | المقترح | السبب |
|---------|--------|---------|-------|
| `PULL_INTERVAL_SEC` | 30 | 15-20 | استجابة أسرع |
| `worker_report_batch_size` | 10 | 20 | تقليل HTTP calls |

---

## 8. Monitoring Recommendations

### 8.1 Metrics المطلوبة

| Metric | الأداة | الأولوية |
|--------|--------|----------|
| Response Time (p95) | Vercel Analytics | P1 |
| Error Rate | Sentry | P1 |
| Database Query Time | Custom logging | P2 |
| Bundle Load Time | Lighthouse | P2 |

### 8.2 Lighthouse Targets

| Metric | Target |
|--------|--------|
| Performance | > 80 |
| Accessibility | > 90 |
| Best Practices | > 90 |
| SEO | > 80 |

---

## 9. خطة التنفيذ

### الأسبوع 1: Bundle Optimization

- [ ] إضافة `manualChunks` في vite.config.ts
- [ ] تطبيق Lazy Loading للـ routes
- [ ] قياس الحجم الجديد

### الأسبوع 2: Database Optimization

- [ ] إضافة Indexes المفقودة
- [ ] تطبيق Pagination
- [ ] إصلاح N+1 queries

### الأسبوع 3: Caching

- [ ] إضافة Cache headers
- [ ] تطبيق Query cache
- [ ] اختبار الأداء

---

## 10. أوامر القياس

```powershell
# قياس حجم Bundle
cd d:\projects\دمج\OP-Target-Sales-Hub-1
npm run build
# انظر الـ output

# Lighthouse (Chrome DevTools)
# F12 → Lighthouse → Generate report

# Database query time (PostgreSQL)
# EXPLAIN ANALYZE SELECT * FROM leads WHERE owner_user_id = 'xxx';

# API Response time
Measure-Command { Invoke-WebRequest http://localhost:3000/api/leads }
```

---

> **آخر تحديث**: 2026-01-05 19:56 UTC+3
