# ERD — Database Entity Relationship Diagram

> **Evidence**: All schema extracted from [config/db.php](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/config/db.php)

---

## ENTITY RELATIONSHIP DIAGRAM

```mermaid
erDiagram
    users ||--o{ sessions : "authentication"
    users ||--o{ leads : "created_by"
    users ||--o{ assignments : "agent"
    users ||--o{ internal_jobs : "requested_by"
    users ||--o{ categories : "created_by"
    users ||--o{ audit_logs : "performed"
    users ||--o{ job_groups : "created_by"
    
    leads ||--o| assignments : "assigned_to"
    leads }o--|| categories : "classified_as"
    leads }o--o| job_groups : "belongs_to"
    leads }o--o| geo_cities : "located_in"
    leads }o--o| geo_districts : "located_in"
    leads ||--o{ leads_fingerprints : "has"
    leads ||--o{ washeej_logs : "messaging"
    
    internal_jobs }o--|| internal_workers : "claimed_by"
    internal_jobs }o--|| users : "requested_by"
    internal_jobs }o--o| categories : "targets"
    internal_jobs }o--o| job_groups : "grouped_in"
    internal_jobs ||--o{ job_attempts : "attempts"
    internal_jobs ||--o{ dead_letter_jobs : "final_failure"
    internal_jobs ||--o{ idempotency_keys : "dedup"
    
    categories ||--o{ categories : "parent_child"
    categories ||--o{ category_keywords : "keywords"
    categories ||--o{ category_rules : "classification_rules"
    categories ||--o{ category_query_templates : "templates"
    categories ||--o{ category_activity_log : "activity"
    
    internal_workers ||--o{ internal_jobs : "processing"
    internal_workers ||--o{ job_attempts : "executed"
    internal_workers ||--o{ hmac_replay : "requests"
    
    job_groups ||--o{ internal_jobs : "contains"
    job_groups ||--o{ leads : "results"
    
    users {
        INTEGER id PK
        TEXT mobile UK "Phone number"
        TEXT username UK "Optional username"
        TEXT name "Display name"
        TEXT role "admin|agent"
        TEXT password_hash "bcrypt/argon2"
        INTEGER active "0|1"
        INTEGER is_superadmin "0|1"
        TEXT washeej_token "Per-agent WhatsApp token"
        TEXT washeej_sender "Per-agent sender ID"
        TEXT whatsapp_message "Per-agent template"
        TEXT created_at
    }
    
    sessions {
        INTEGER id PK
        INTEGER user_id FK
        TEXT token_hash "SHA-256 of cookie value"
        TEXT expires_at
        TEXT created_at
    }
    
    leads {
        INTEGER id PK
        TEXT phone UK "Primary dedup key"
        TEXT phone_norm "E.164-like normalized"
        TEXT name
        TEXT city
        TEXT country
        INTEGER category_id FK
        INTEGER job_group_id FK
        INTEGER created_by_user_id FK
        REAL rating "0-5 stars"
        TEXT website
        TEXT email
        TEXT gmap_types "Comma-separated"
        TEXT source_url
        TEXT social "JSON"
        REAL lat
        REAL lon
        TEXT geo_country
        TEXT geo_region_code
        INTEGER geo_city_id FK
        INTEGER geo_district_id FK
        REAL geo_confidence "0-1"
        TEXT source "osm|google|foursquare|internal"
        TEXT created_at
    }
    
    assignments {
        INTEGER id PK
        INTEGER lead_id FK UK
        INTEGER agent_id FK
        TEXT status "new|contacted|qualified|..."
        TEXT assigned_at
    }
    
    internal_jobs {
        INTEGER id PK
        INTEGER requested_by_user_id FK
        TEXT role "admin|agent"
        INTEGER agent_id FK
        INTEGER category_id FK
        INTEGER job_group_id FK
        TEXT query "Search query"
        TEXT ll "lat,lng"
        INTEGER radius_km
        TEXT lang "ar|en"
        TEXT region "sa|..."
        TEXT status "queued|processing|done|failed"
        TEXT worker_id FK
        INTEGER attempts "Retry counter"
        TEXT claimed_at
        TEXT finished_at
        INTEGER result_count
        INTEGER target_count "Optional limit"
        INTEGER progress_count
        INTEGER last_cursor
        TEXT last_progress_at
        TEXT lease_expires_at
        TEXT next_retry_at
        TEXT last_error
        TEXT done_reason
        INTEGER max_attempts "Override default"
        INTEGER priority "Higher = first"
        TEXT locked_until
        TEXT queued_at
        TEXT attempt_id "UUID per attempt"
        TEXT city_hint "Multi-location hint"
        TEXT created_at
        TEXT updated_at
    }
    
    internal_workers {
        INTEGER id PK
        TEXT worker_id UK "e.g. worker-001"
        TEXT last_seen "Presence timestamp"
        TEXT info "JSON metadata"
        TEXT host "Hostname"
        TEXT version "Worker version"
        TEXT status "idle|pulling|processing|reporting"
        INTEGER active_job_id FK
        TEXT secret "Per-worker HMAC secret"
        TEXT rotating_to "Next secret during rotation"
        TEXT rotated_at
        INTEGER rate_limit_per_min "Per-worker override"
    }
    
    job_attempts {
        INTEGER id PK
        INTEGER job_id FK
        TEXT worker_id FK
        TEXT started_at
        TEXT finished_at
        INTEGER success "0|1"
        TEXT log_excerpt "Error/debug info"
        TEXT attempt_id "Matches job attempt"
    }
    
    dead_letter_jobs {
        INTEGER id PK
        INTEGER job_id FK
        TEXT worker_id FK
        TEXT reason "Max attempts reached"
        TEXT payload "Job details snapshot"
        TEXT created_at
    }
    
    categories {
        INTEGER id PK
        INTEGER parent_id FK "Self-reference"
        TEXT name
        TEXT slug UK
        INTEGER depth "Tree depth 0,1,2..."
        TEXT path "Breadcrumb trail"
        INTEGER is_active "0|1"
        TEXT icon_type "emoji|fa|material"
        TEXT icon_value
        INTEGER created_by_user_id FK
        TEXT created_at
        TEXT updated_at
    }
    
    category_keywords {
        INTEGER id PK
        INTEGER category_id FK
        TEXT keyword
        TEXT lang "ar|en"
        TEXT created_at
    }
    
    category_rules {
        INTEGER id PK
        INTEGER category_id FK
        TEXT target "name|types|website|email|..."
        TEXT pattern "Search string or regex"
        TEXT match_mode "contains|exact|regex"
        REAL weight "Scoring weight"
        TEXT note
        INTEGER enabled "0|1"
        TEXT created_at
    }
    
    job_groups {
        INTEGER id PK
        INTEGER created_by_user_id FK
        INTEGER category_id FK
        TEXT base_query "Shared query template"
        TEXT note
        TEXT created_at
    }
    
    hmac_replay {
        INTEGER id PK
        TEXT worker_id FK
        INTEGER ts "Unix timestamp"
        TEXT body_sha "SHA-256 of body"
        TEXT method "GET|POST"
        TEXT path "/api/..."
        TEXT created_at
    }
    
    rate_limit {
        TEXT ip PK
        TEXT key PK "SHA-1 of IP+endpoint"
        INTEGER window_start PK "Unix timestamp"
        INTEGER count "Request counter"
    }
    
    idempotency_keys {
        INTEGER id PK
        INTEGER job_id FK
        TEXT ikey UK "Client-provided key"
        TEXT created_at
    }
    
    leads_fingerprints {
        INTEGER id PK
        INTEGER lead_id FK
        TEXT fingerprint UK "SHA-1 hash"
        TEXT created_at
    }
    
    place_cache {
        INTEGER id PK
        TEXT provider "osm|google|foursquare|..."
        TEXT external_id "Provider's place ID"
        TEXT phone
        TEXT name
        TEXT city
        TEXT country
        TEXT updated_at
    }
    
    search_tiles {
        TEXT tile_key PK "SHA-1 of query+ll+radius"
        TEXT q "Query string"
        TEXT ll "lat,lng"
        INTEGER radius_km
        TEXT provider_order "Comma-separated"
        INTEGER preview_count
        INTEGER leads_added
        TEXT updated_at
    }
    
    usage_counters {
        INTEGER id PK
        TEXT day "YYYY-MM-DD"
        TEXT kind "google_details|ingest_added|..."
        INTEGER count
    }
    
    audit_logs {
        INTEGER id PK
        INTEGER user_id FK
        TEXT action "create_user|delete_lead|..."
        TEXT target "Resource identifier"
        TEXT payload "JSON details"
        TEXT created_at
    }
    
    alert_events {
        INTEGER id PK
        TEXT kind "worker_offline|dlq_not_empty|..."
        TEXT message
        TEXT payload "JSON"
        TEXT created_at
    }
    
    auth_attempts {
        INTEGER id PK
        TEXT ip
        TEXT key "mobile or username"
        TEXT created_at
    }
    
    settings {
        TEXT key PK
        TEXT value
    }
    
    washeej_logs {
        INTEGER id PK
        INTEGER lead_id FK
        INTEGER agent_id FK
        INTEGER sent_by_user_id FK
        INTEGER http_code
        TEXT response
        TEXT created_at
    }
    
    category_activity_log {
        INTEGER id PK
        TEXT action
        INTEGER category_id FK
        INTEGER user_id FK
        TEXT details "JSON"
        TEXT created_at
    }
```

---

## TABLE SUMMARY

| Table | Rows (Typical) | Purpose | Hot Path |
|-------|---------------|---------|----------|
| **users** | 10-100 | User accounts | ✅ Every auth |
| **sessions** | 10-50 | Active sessions | ✅ Every request |
| **leads** | 10K-10M | Core business data | ✅ Ingestion + Search |
| **assignments** | 1K-1M | Lead → Agent mapping | ✅ Agent dashboard |
| **internal_jobs** | 100-10K | Job queue | ✅ Worker pull (every 30s) |
| **internal_workers** | 1-100 | Worker registry | ✅ Health checks |
| **job_attempts** | 1K-100K | Audit trail | Read-mostly |
| **categories** | 10-1K | Taxonomy tree | ✅ Classification |
| **category_keywords** | 100-10K | Match patterns | ✅ Every classify |
| **category_rules** | 50-5K | Advanced matching | ✅ Every classify |
| **hmac_replay** | 10K-1M | Replay prevention | ✅ Every worker API call |
| **rate_limit** | 1K-100K | Throttling | ✅ High-traffic endpoints |
| **leads_fingerprints** | 10K-10M | Soft deduplication | ✅ Ingestion |
| **place_cache** | 1K-1M | External API cache | Read-mostly |
| **search_tiles** | 100-10K | Tile TTL tracking | Read-mostly |
| **settings** | 50-200 | Configuration | ✅ Every request |

---

## INDEXES

### Primary Keys (Automatic)
All tables have `INTEGER PRIMARY KEY AUTOINCREMENT` (SQLite ROWID alias)

### Unique Constraints
- `users.mobile`, `users.username`
- `leads.phone`
- `categories.slug`
- `hmac_replay(worker_id, ts, body_sha, method, path)`
- `rate_limit(ip, key, window_start)` — composite PK
- `leads_fingerprints.fingerprint`
- `place_cache(provider, external_id)`
- `search_tiles.tile_key`
- `idempotency_keys(job_id, ikey)`

### Foreign Key Indexes
- `idx_leads_category_id`, `idx_leads_geo_city`, `idx_leads_geo_district`, `idx_leads_phone_norm`
- `idx_internal_jobs_status_lease`, `idx_internal_jobs_status_updated`, `idx_internal_jobs_queue`
- `idx_internal_workers_last_seen`
- `idx_category_keywords_cat`, `idx_category_rules_cat_target`
- `idx_auth_attempts_key_time`
- `idx_rate_limit_recent`

**Evidence**: [config/db.php#L50-L289](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/config/db.php#L50-L289) — All CREATE INDEX statements

---

## RELATIONSHIPS

### One-to-Many
- `users` → `sessions`, `leads`, `assignments`, `internal_jobs`, `audit_logs`
- `categories` → `categories` (self-reference for hierarchy)
- `categories` → `category_keywords`, `category_rules`
- `internal_jobs` → `job_attempts`, `idempotency_keys`
- `leads` → `leads_fingerprints`

### Many-to-One
- `leads` → `categories`, `users` (created_by), `job_groups`
- `assignments` → `leads`, `users` (agent)
- `internal_jobs` → `users` (requested_by), `categories`, `internal_workers`, `job_groups`

### Optional Many-to-One (Nullable FK)
- `leads.geo_city_id`, `leads.geo_district_id` → geo tables (if exist)
- `internal_jobs.category_id` → categories (optional targeting)

---

## NORMALIZATION LEVEL

**Overall**: 3NF (Third Normal Form)

**Rationale**:
- ✅ No repeating groups (except serialized JSON in `social`, `info` fields — acceptable for flexibility)
- ✅ All non-key attributes depend on primary key
- ✅ No transitive dependencies

**Denormalization (Intentional)**:
- `leads.phone_norm` (duplicate of normalized `phone`) — for indexing
- `categories.path` (denormalized breadcrumb) — for fast display
- `categories.depth` (calculable from hierarchy) — for ordering
- `internal_jobs.result_count` (aggregate) — for progress tracking

**Evidence**: Schema design follows standard RDBMS patterns

---

## FOREIGN KEY ENFORCEMENT

**Enabled**: `PRAGMA foreign_keys=ON;`  
**Evidence**: [config/db.php#L3](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/config/db.php#L3)

**Cascades**:
- `sessions.user_id` → `users.id` ON DELETE CASCADE
- `assignments.lead_id` → `leads.id` ON DELETE CASCADE
- `leads_fingerprints.lead_id` → `leads.id` ON DELETE CASCADE
- `category_keywords.category_id` → `categories.id` ON DELETE CASCADE
- `job_attempts.job_id` → `internal_jobs.id` ON DELETE CASCADE

**SET NULL** (soft delete):
- `leads.created_by_user_id` → `users.id` ON DELETE SET NULL
- `internal_jobs.requested_by_user_id` → `users.id` ON DELETE SET NULL
- `categories.parent_id` → `categories.id` ON DELETE SET NULL

---

## DATA INTEGRITY CONSTRAINTS

### Check Constraints
- `users.role IN ('admin', 'agent')`
- `assignments.status` (implied by application logic)
- `internal_jobs.status IN ('queued', 'processing', 'done', 'failed')`
- `category_rules.match_mode IN ('contains', 'exact', 'regex')`

### Not Null Constraints
- Most ID columns, timestamps
- Critical fields: `users.mobile`, `users.password_hash`, `leads.phone`

### Default Values
- `users.active = 1`
- `users.is_superadmin = 0`
- `categories.is_active = 1`
- `internal_jobs.status = 'queued'`
- `category_rules.weight = 1.0`

---

## POTENTIAL ISSUES

### 1. Missing Indexes (Under Heavy Load)

**Symptom**: Slow queries on large datasets  
**Affected**:
- `leads` filtering by `created_at` + `category_id` (compound index missing)
- `internal_jobs` compound query on `(status, priority, queued_at)` — EXISTS but could be optimized
- `hmac_replay` cleanup by `ts` — index exists but periodic VACUUM recommended

**Recommendation**: Monitor `EXPLAIN QUERY PLAN` on slow endpoints

---

### 2. Unbounded Growth Tables

| Table | Risk Level | Mitigation |
|-------|-----------|------------|
| `hmac_replay` | HIGH | TTL cleanup (7 days default) |
| `rate_limit` | MEDIUM | TTL cleanup (2 days default) |
| `job_attempts` | MEDIUM | TTL cleanup (90 days default) |
| `leads_fingerprints` | LOW | Grows with leads, acceptable |
| `audit_logs` | LOW | Archive old logs periodically |

**Evidence**: TTL settings in [config/db.php#L374-L378](file:///d:/projects/forge.op-tg.com/forge.op-tg.com/config/db.php#L374-L378)

---

### 3. SQLite Limitations at Scale

**Current Mitigation**:
- WAL mode ✅ (concurrent reads)
- NORMAL synchronous ✅ (balanced safety)
- 8MB page cache ✅
- Foreign keys ON ✅

**Scalability Ceiling**:
- Write throughput: ~1K writes/sec (single writer limit)
- Database size: Practical limit ~10GB (beyond this, consider migration)
- Concurrent connections: No issue (read-heavy workload)

**Migration Trigger**: If `leads` table exceeds 10M rows OR write contention observed

---

## BACKUP & RECOVERY

### Recommended Strategy

**Hot Backup** (while running):
```sql
PRAGMA wal_checkpoint(FULL);
-- Then copy app.sqlite + app.sqlite-wal + app.sqlite-shm
```

**Cold Backup** (safer):
```bash
# Stop backend
cp storage/app.sqlite storage/backups/app_$(date +%Y%m%d_%H%M%S).sqlite
# Restart backend
```

**Point-in-Time Recovery**: Not natively supported by SQLite (consider replication to PostgreSQL for this)

---

## CONCLUSION

**Strengths**:
- ✅ Well-normalized schema
- ✅ Comprehensive foreign keys with cascades
- ✅ Strategic indexes on hot paths
- ✅ Idempotency + deduplication built-in
- ✅ Audit trail for accountability

**Weaknesses**:
- ⚠️ Single-writer bottleneck (SQLite inherent)
- ⚠️ Unbounded growth tables (requires periodic maintenance)
- ⚠️ No built-in sharding/replication

**Next Actions**:
1. Implement TTL cleanup cron jobs (already documented in RUNBOOK)
2. Monitor query performance with EXPLAIN QUERY PLAN
3. Plan migration to PostgreSQL/MySQL if scale exceeds 10M rows
