# Performance Optimization Guide

## Sprint 4D: Performance recommendations for production

---

## 1. Database Indexes

### SQLite (Forge)

```sql
-- Jobs table indexes
CREATE INDEX IF NOT EXISTS idx_jobs_status ON internal_jobs(status);
CREATE INDEX IF NOT EXISTS idx_jobs_created ON internal_jobs(created_at);
CREATE INDEX IF NOT EXISTS idx_jobs_worker ON internal_jobs(worker_id);
CREATE INDEX IF NOT EXISTS idx_jobs_status_created ON internal_jobs(status, created_at);

-- Leads table indexes
CREATE INDEX IF NOT EXISTS idx_leads_phone ON leads(phone);
CREATE INDEX IF NOT EXISTS idx_leads_created ON leads(created_at);
CREATE INDEX IF NOT EXISTS idx_leads_city ON leads(city);
CREATE INDEX IF NOT EXISTS idx_leads_category ON leads(category_id);

-- Rate limits
CREATE INDEX IF NOT EXISTS idx_rate_key ON rate_limits(key);
CREATE INDEX IF NOT EXISTS idx_rate_window ON rate_limits(window_start);

-- Google Web Cache
CREATE INDEX IF NOT EXISTS idx_gwc_hash ON google_web_cache(query_hash);
CREATE INDEX IF NOT EXISTS idx_gwc_expires ON google_web_cache(expires_at);
```

### PostgreSQL (OP-Target/Neon)

```sql
-- Users
CREATE INDEX IF NOT EXISTS idx_users_email ON users(email);

-- Leads
CREATE INDEX IF NOT EXISTS idx_leads_user ON leads(user_id);
CREATE INDEX IF NOT EXISTS idx_leads_status ON leads(status);

-- Rate limits
CREATE INDEX IF NOT EXISTS idx_rate_limits_key ON rate_limits(key);
```

---

## 2. Query Optimization

### Avoid N+1 Queries

```php
// BAD: N+1 query
foreach ($jobs as $job) {
    $leads = db()->query("SELECT * FROM leads WHERE job_id = ?", [$job['id']]);
}

// GOOD: Single query with JOIN
$results = db()->query("
    SELECT j.*, l.* 
    FROM internal_jobs j
    LEFT JOIN leads l ON l.job_id = j.id
    WHERE j.status = 'done'
");
```

### Use LIMIT for Large Tables

```php
// Always paginate large result sets
$stmt = $pdo->prepare("
    SELECT * FROM leads 
    ORDER BY created_at DESC 
    LIMIT ? OFFSET ?
");
$stmt->execute([$limit, $offset]);
```

---

## 3. Caching Strategy

### Google Web Cache TTL

```php
// Cache search results for 24 hours
$expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
```

### PHP OPcache

```ini
; php.ini settings for production
opcache.enable=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=8
opcache.max_accelerated_files=4000
opcache.revalidate_freq=60
opcache.fast_shutdown=1
```

---

## 4. Rate Limiting Cleanup

Run periodically to prevent table bloat:

```php
// Clean old rate limit entries (older than 1 hour)
$pdo->exec("
    DELETE FROM rate_limits 
    WHERE window_start < datetime('now', '-1 hour')
");
```

Add to cron:
```bash
0 * * * * php /path/to/forge/ops/cleanup_rate_limits.php
```

---

## 5. Connection Pooling

### Neon PostgreSQL

Use pooled connection string for API requests:
```
postgresql://user:pass@host.neon.tech:5432/db?sslmode=require
```

Use unpooled for migrations:
```
postgresql://user:pass@host-pooler.neon.tech:5432/db?sslmode=require
```

---

## 6. Response Compression

### Enable gzip in PHP

```php
// At the start of API responses
if (extension_loaded('zlib')) {
    ob_start('ob_gzhandler');
}
```

### Nginx config

```nginx
gzip on;
gzip_types application/json text/plain text/css application/javascript;
gzip_min_length 1000;
```

---

## 7. Monitoring Queries

### Slow Query Detection

```sql
-- Find jobs taking too long
SELECT id, query, 
       (julianday(finished_at) - julianday(claimed_at)) * 86400 as duration_sec
FROM internal_jobs
WHERE status = 'done'
AND duration_sec > 300
ORDER BY duration_sec DESC
LIMIT 10;
```

### Queue Health

```sql
-- Jobs stuck in processing
SELECT id, query, worker_id, claimed_at
FROM internal_jobs
WHERE status = 'processing'
AND claimed_at < datetime('now', '-30 minutes');
```

---

## 8. Worker Performance

### Batch Reporting

```javascript
// Report results in batches of 10
const REPORT_BATCH_SIZE = 10;
if (results.length >= REPORT_BATCH_SIZE) {
    await reportResults(results);
    results = [];
}
```

### Retry with Backoff

```javascript
const backoff = Math.min(30000, 1000 * Math.pow(2, attempt));
await sleep(backoff);
```

---

## 9. Frontend Performance

### Lazy Loading

```tsx
// Lazy load heavy components
const ReportViewer = lazy(() => import('./ReportViewer'));
```

### API Response Caching

```typescript
// Cache API responses in memory
const cache = new Map();
const CACHE_TTL = 60000; // 1 minute

async function fetchWithCache(url: string) {
    const cached = cache.get(url);
    if (cached && Date.now() - cached.time < CACHE_TTL) {
        return cached.data;
    }
    const data = await fetch(url).then(r => r.json());
    cache.set(url, { data, time: Date.now() });
    return data;
}
```

---

## 10. Production Checklist

- [ ] All indexes created
- [ ] OPcache enabled
- [ ] Gzip compression enabled
- [ ] Rate limit cleanup cron scheduled
- [ ] Connection pooling configured
- [ ] Slow query monitoring enabled
- [ ] Error logging configured (no secrets!)
- [ ] Health endpoints accessible
- [ ] Backup cron scheduled

---

> Last updated: 2026-01-05
