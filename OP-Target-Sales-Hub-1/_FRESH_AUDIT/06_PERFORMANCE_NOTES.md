# 06_PERFORMANCE_NOTES - ملاحظات الأداء

**تاريخ التدقيق:** 2026-01-03  
**المنهجية:** Code review + Build analysis

---

## 📦 1. Bundle Size

### الحالة الحالية

**المصدر:** `npm run build` output

```
dist/index.html                  2.37 kB │ gzip:   0.99 kB
dist/assets/index-BeamBO1s.js  984.6 kB │ gzip: 282.71 kB
```

### 🔴 مشكلة

| البند | القيمة | الحد المقبول |
|-------|--------|--------------|
| Bundle size | 984.6 KB | < 500 KB |
| Gzipped | 282.71 KB | < 150 KB |

**Vite warning:**
```
Some chunks are larger than 500 kB after minification.
Consider using dynamic import() to code-split the application
```

### تحليل الـ Dependencies

**المصدر:** `package.json`

| Package | Estimated Size | Notes |
|---------|---------------|-------|
| react + react-dom | ~140 KB | Core |
| recharts | ~300 KB | Charts library |
| lucide-react | ~200 KB | Icons (all loaded) |
| @google/genai | ~100 KB | AI SDK |
| pg | ~50 KB | PostgreSQL client |

### التوصيات

#### 1. Code Splitting (P2)

```typescript
// App.tsx - Lazy load heavy components
const ReportView = React.lazy(() => import('./components/ReportView'));
const Leaderboard = React.lazy(() => import('./components/Leaderboard'));
const SettingsPanel = React.lazy(() => import('./components/SettingsPanel'));

// Usage with Suspense
<Suspense fallback={<LoadingSpinner />}>
  {currentPage === 'report' && <ReportView ... />}
</Suspense>
```

#### 2. Tree-shake Lucide Icons

```typescript
// بدلاً من:
import { LayoutDashboard, Users, Settings, ... } from 'lucide-react';

// استخدم imports فردية:
import LayoutDashboard from 'lucide-react/dist/esm/icons/layout-dashboard';
```

#### 3. Vite Manual Chunks

```typescript
// vite.config.ts
build: {
  rollupOptions: {
    output: {
      manualChunks: {
        'vendor-react': ['react', 'react-dom'],
        'vendor-charts': ['recharts'],
        'vendor-icons': ['lucide-react'],
      }
    }
  }
}
```

---

## 🔄 2. API Performance

### 2.1 No Pagination

**المشكلة:** كل الـ GET endpoints تجلب كل البيانات

**المصدر:** `api/leads.ts:17`

```typescript
leadsRes = await query('SELECT * FROM leads ORDER BY created_at DESC');
// لا يوجد LIMIT
```

**التأثير:**
- Memory usage عالي مع بيانات كبيرة
- Response time بطيء
- Network bandwidth مهدور

**الحل:**

```typescript
// api/leads.ts
const limit = Math.min(parseInt(queryParams.limit) || 50, 100);
const offset = parseInt(queryParams.offset) || 0;

leadsRes = await query(
  'SELECT * FROM leads ORDER BY created_at DESC LIMIT $1 OFFSET $2',
  [limit, offset]
);

// Return with pagination metadata
return res.status(200).json({
  data: leads,
  pagination: {
    limit,
    offset,
    total: totalCount
  }
});
```

### 2.2 N+1 Queries

**المشكلة:** `canAccessLead()` يعمل query منفصل لكل lead

**المصدر:** `api/_auth.ts:111-147`

```typescript
export async function canAccessLead(user: AuthUser, leadId: string): Promise<boolean> {
  // Query for each lead check
  const result = await query(
    'SELECT owner_user_id, team_id FROM leads WHERE id = $1',
    [leadId]
  );
  // ...
}
```

**التأثير:** إذا عندك 100 lead، يصير 100 query إضافي

**الحل:** Batch check أو JOIN في الـ query الأصلي

```typescript
// بدلاً من check لكل lead
// استخدم WHERE clause في الـ query الأصلي
if (user.role === 'SALES_REP') {
  leadsRes = await query(
    'SELECT * FROM leads WHERE owner_user_id = $1',
    [user.id]
  );
}
```

### 2.3 No Caching

**المشكلة:** لا يوجد caching للـ static data

**أمثلة:**
- AI Settings (نادراً ما تتغير)
- Scoring Settings
- Teams list

**الحل البسيط:**

```typescript
// In-memory cache with TTL
const cache = new Map<string, { data: any; expiry: number }>();

async function getCached<T>(key: string, fetcher: () => Promise<T>, ttlMs = 60000): Promise<T> {
  const cached = cache.get(key);
  if (cached && cached.expiry > Date.now()) {
    return cached.data;
  }
  const data = await fetcher();
  cache.set(key, { data, expiry: Date.now() + ttlMs });
  return data;
}
```

---

## 🗄️ 3. Database Performance

### 3.1 Missing Indexes

**المصدر:** استنتاج من الـ queries

| Table | Column | Query Pattern | Index Needed |
|-------|--------|---------------|--------------|
| leads | owner_user_id | WHERE owner_user_id = $1 | ✅ Yes |
| leads | team_id | WHERE team_id = $1 | ✅ Yes |
| leads | status | GROUP BY status | ✅ Yes |
| leads | created_at | ORDER BY created_at DESC | ✅ Yes |
| activities | lead_id | WHERE lead_id = $1 | ✅ Yes |
| tasks | lead_id | WHERE lead_id = $1 | ✅ Yes |
| audit_logs | created_at | ORDER BY created_at DESC | ✅ Yes |

**الحل:**

```sql
CREATE INDEX CONCURRENTLY idx_leads_owner ON leads(owner_user_id);
CREATE INDEX CONCURRENTLY idx_leads_team ON leads(team_id);
CREATE INDEX CONCURRENTLY idx_leads_status ON leads(status);
CREATE INDEX CONCURRENTLY idx_leads_created ON leads(created_at DESC);
CREATE INDEX CONCURRENTLY idx_activities_lead ON activities(lead_id);
CREATE INDEX CONCURRENTLY idx_tasks_lead ON tasks(lead_id);
CREATE INDEX CONCURRENTLY idx_audit_logs_created ON audit_logs(created_at DESC);
```

### 3.2 Connection Pool

**المصدر:** `api/_db.ts`

```typescript
const pool = new Pool({
  connectionString: connectionString,
  ssl: { rejectUnauthorized: false }
  // لا يوجد pool configuration
});
```

**التوصية:**

```typescript
const pool = new Pool({
  connectionString,
  ssl: { rejectUnauthorized: false },
  max: 10,                        // Max connections
  min: 2,                         // Min connections
  idleTimeoutMillis: 30000,       // Close idle connections after 30s
  connectionTimeoutMillis: 5000,  // Fail if can't connect in 5s
});
```

---

## 🌐 4. Frontend Performance

### 4.1 TailwindCSS via CDN

**المصدر:** `index.html:12`

```html
<script src="https://cdn.tailwindcss.com"></script>
```

**المشكلة:**
- CDN adds latency
- No tree-shaking (full Tailwind loaded)
- Runtime compilation

**التوصية للـ Production:**

```bash
npm install -D tailwindcss postcss autoprefixer
npx tailwindcss init -p
```

```javascript
// tailwind.config.js
module.exports = {
  content: ['./**/*.{tsx,ts,html}'],
  // ...
}
```

### 4.2 Google Fonts

**المصدر:** `index.html:9-11`

```html
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@200;300;400;500;700;800;900&display=swap" rel="stylesheet">
```

**✅ جيد:** `preconnect` موجود

**التوصية:** استخدام `font-display: swap` (موجود في الـ URL)

### 4.3 Images

**المصدر:** `UserManagement.tsx:161`

```tsx
<img src={u.avatar} className="w-12 h-12 rounded-full" alt="" />
```

**المشكلة:**
- External images (picsum.photos) بدون optimization
- No lazy loading
- No placeholder

**التوصية:**

```tsx
<img 
  src={u.avatar} 
  loading="lazy"
  decoding="async"
  className="w-12 h-12 rounded-full" 
  alt={u.name}
/>
```

---

## 📊 5. ملخص التوصيات

### P1 - مهم

| # | التوصية | التأثير المتوقع |
|---|---------|-----------------|
| 1 | إضافة Pagination للـ APIs | -50% response time |
| 2 | إضافة Database indexes | -70% query time |
| 3 | إصلاح N+1 queries | -80% queries |

### P2 - تحسينات

| # | التوصية | التأثير المتوقع |
|---|---------|-----------------|
| 4 | Code splitting | -40% initial load |
| 5 | Tree-shake icons | -100KB bundle |
| 6 | Local TailwindCSS | -200ms TTFB |
| 7 | Connection pool config | Better stability |
| 8 | API caching | -90% for static data |

---

## 🎯 Quick Wins

### 1. إضافة Pagination (30 دقيقة)

```typescript
// api/leads.ts
const limit = Math.min(parseInt(queryParams.limit) || 50, 100);
const offset = parseInt(queryParams.offset) || 0;
```

### 2. إضافة Indexes (5 دقائق)

```sql
CREATE INDEX idx_leads_owner ON leads(owner_user_id);
CREATE INDEX idx_leads_team ON leads(team_id);
```

### 3. Lazy Load Components (15 دقيقة)

```typescript
const ReportView = React.lazy(() => import('./components/ReportView'));
```

---

## 📈 Metrics to Track

| Metric | Current | Target |
|--------|---------|--------|
| Bundle size | 984 KB | < 500 KB |
| Initial load | ~3s | < 1.5s |
| API response (leads) | Unknown | < 200ms |
| Database queries | N+1 | Single query |
