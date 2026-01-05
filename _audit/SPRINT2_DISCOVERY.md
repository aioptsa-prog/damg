# Sprint 2 Discovery: Core Flow Analysis
> تاريخ: 2026-01-05

---

## ملخص النظام

### 1. Jobs System (Forge)

#### الملفات الرئيسية
| الملف | الوظيفة |
|-------|---------|
| `api/pull_job.php` | Worker يسحب مهمة جديدة |
| `api/report_results.php` | Worker يرسل النتائج |
| `config/db.php` | تعريف جدول `internal_jobs` |
| `worker/index.js` | Node.js Playwright worker |

#### جدول internal_jobs
```sql
-- الأعمدة الرئيسية
id, query, ll, role, agent_id, status, target_count
worker_id, claimed_at, lease_expires_at, attempt_id
attempts, max_attempts, progress_count, result_count
last_cursor, last_progress_at, next_retry_at, last_error
priority, queued_at, category_id, job_group_id
```

#### حالات المهمة (Status)
- `queued` → في الانتظار
- `processing` → قيد التنفيذ (مع lease)
- `done` → مكتملة
- `failed` → فشلت (بعد max_attempts)

#### آلية العمل
```
1. Worker يستدعي pull_job.php
   ↓
2. يتم اختيار مهمة queued وتحديثها لـ processing
   ↓
3. Worker يجمع البيانات (Google Maps scraping)
   ↓
4. Worker يرسل النتائج عبر report_results.php
   ↓
5. Leads تُضاف للجدول + تحديث progress
   ↓
6. عند الانتهاء: status = done
```

#### ميزات الأمان
- ✅ HMAC authentication للـ workers
- ✅ Replay attack prevention
- ✅ Lease expiration (auto-requeue)
- ✅ Circuit breaker للـ workers المعطلة
- ✅ Idempotency keys

---

### 2. Leads System (Forge)

#### جدول leads
```sql
-- الأعمدة الرئيسية
id, phone, phone_norm, name, city, country
rating, website, email, gmap_types, source_url
social, category_id, lat, lon
geo_country, geo_region_code, geo_city_id
created_at, created_by_user_id, job_group_id
```

#### مصادر الـ Leads
1. **Google Maps Scraping** (worker)
2. **Manual Entry** (UI)
3. **Import** (CSV/Excel)

---

### 3. Google Search Provider (Worker)

#### الملف: `worker/modules/google_web.js`

#### Providers
| Provider | الأولوية | الحد اليومي |
|----------|----------|-------------|
| SerpAPI | Primary | 100 |
| Chromium | Fallback | 10 |

#### الميزات
- ✅ Query caching
- ✅ Usage tracking
- ✅ Social profile detection
- ✅ Official site detection
- ✅ Directory detection
- ✅ AI Pack builder

#### Environment Variables
```env
SERPAPI_KEY=xxx
GOOGLE_WEB_FALLBACK_ENABLED=0|1
GOOGLE_WEB_MAX_RESULTS=10
```

---

### 4. LLM Report Generation (OP-Target)

#### الملفات
| الملف | الوظيفة |
|-------|---------|
| `services/aiService.ts` | AI Service class |
| `api/reports.ts` | Reports API + Enrichment |

#### Providers
| Provider | Model | الإعداد |
|----------|-------|---------|
| Gemini | gemini-1.5-flash | `GEMINI_API_KEY` |
| OpenAI | gpt-4o-mini | `OPENAI_API_KEY` |

#### Report Schema
```typescript
// services/aiService.ts:9-195
REPORT_SCHEMA = {
  company, sector, evidence_summary,
  snapshot, website_audit, social_audit,
  pain_points, quick_wins, recommended_services,
  talk_track, follow_up_plan,
  assumptions, data_gaps, compliance_notes
}
```

#### Evidence Pipeline
```
1. fetchWebsite() - جلب HTML
   ↓
2. parseWebsite() - استخراج البيانات
   ↓
3. buildEvidenceBundle() - تجميع الأدلة
   ↓
4. generateReport() - توليد التقرير بالـ AI
```

#### ميزات الأمان
- ✅ API keys في ENV فقط
- ✅ Rate limiting (client-side)
- ✅ Auto-repair للـ JSON الفاشل
- ⚠️ Rate limiting يحتاج نقل للـ server

---

## الفجوات المكتشفة

### Critical
| الفجوة | الموقع | الحل |
|--------|--------|------|
| ❌ لا integration بين OP-Target و Forge | - | Feature flags موجودة لكن معطلة |

### High
| الفجوة | الموقع | الحل |
|--------|--------|------|
| ⚠️ Rate limit client-side | `rateLimitService.ts` | نقل للـ API |
| ⚠️ لا E2E tests للـ flow | - | إضافة Playwright tests |

### Medium
| الفجوة | الموقع | الحل |
|--------|--------|------|
| ⚠️ Google search cache في DB | `google_web/cache.php` | تحتاج TTL |
| ⚠️ Worker health monitoring | - | Dashboard موجود لكن بسيط |

---

## Integration Points (Feature Flags)

### الملف: `integration_docs/FEATURE_FLAGS.md`

| Flag | الوظيفة | الحالة |
|------|---------|--------|
| `INTEGRATION_AUTH_BRIDGE` | مصادقة موحدة | ❌ معطل |
| `INTEGRATION_SURVEY_FROM_LEAD` | استبيان من Lead | ❌ معطل |
| `INTEGRATION_SEND_FROM_REPORT` | إرسال من التقرير | ❌ معطل |
| `INTEGRATION_UNIFIED_LEAD_VIEW` | عرض موحد | ❌ معطل |

---

## الخطوات التالية

### Sprint 2 Implementation
1. **تفعيل Feature Flags** تدريجياً
2. **نقل Rate Limiting** للـ server في OP-Target
3. **إضافة E2E tests** للـ core flow
4. **توثيق API endpoints** بـ OpenAPI

### Sprint 3 (مقترح)
1. Unified Dashboard
2. Real-time notifications
3. Advanced analytics

---

> **آخر تحديث**: 2026-01-05 20:50 UTC+3
