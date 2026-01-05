# Sprint 3: API Contracts
> تاريخ: 2026-01-05

---

## التدفق الكامل

```
┌─────────────────────────────────────────────────────────────────────┐
│                    E2E Integration Flow                              │
├─────────────────────────────────────────────────────────────────────┤
│                                                                      │
│  1. SEARCH                                                           │
│     OP-Target → Forge: POST /v1/api/campaigns/create.php            │
│     Creates internal_job with query + location                       │
│                                                                      │
│  2. RESULTS                                                          │
│     Worker → Forge: GET /api/pull_job.php                           │
│     Worker → Forge: POST /api/report_results.php                    │
│     Leads stored in leads table                                      │
│                                                                      │
│  3. SURVEY                                                           │
│     OP-Target → Forge: POST /v1/api/integration/survey.php          │
│     Generates smart survey from lead data gaps                       │
│                                                                      │
│  4. EVIDENCE                                                         │
│     OP-Target: POST /api/reports?enrich=true                        │
│     Fetches website + social + Google Web                            │
│                                                                      │
│  5. REPORT                                                           │
│     OP-Target: POST /api/reports?generate=true                      │
│     LLM generates report from evidence                               │
│                                                                      │
│  6. SEND                                                             │
│     OP-Target → Forge: POST /v1/api/whatsapp/send.php               │
│     Sends WhatsApp message with report summary                       │
│                                                                      │
└─────────────────────────────────────────────────────────────────────┘
```

---

## 1. SEARCH: Create Campaign/Job

### Endpoint
```
POST /v1/api/campaigns/create.php
Host: forge.op-tg.com
```

### Request
```json
{
  "name": "مطاعم الرياض",
  "query": "مطاعم",
  "city": "الرياض",
  "target_count": 100,
  "category_id": 5
}
```

### Response
```json
{
  "ok": true,
  "campaign_id": 123,
  "job_id": 456,
  "message": "تم إنشاء الحملة بنجاح"
}
```

### Auth
- Bearer token (admin/supervisor)
- Session cookie

### Rate Limit
- 10 jobs per minute per user

### curl
```bash
curl -X POST "http://localhost:8081/v1/api/campaigns/create.php" \
  -H "Origin: http://localhost:3000" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer <token>" \
  -d '{"name":"test","query":"مطاعم","city":"الرياض","target_count":10}'
```

---

## 2. RESULTS: Worker Pull & Report

### 2a. Pull Job

```
GET /api/pull_job.php
Host: forge.op-tg.com
```

### Request Headers
```
X-Worker-Id: worker-001
X-Internal-Secret: <hmac_signature>
X-Auth-Ts: 1736100000
```

### Response
```json
{
  "job": {
    "id": 456,
    "query": "مطاعم",
    "ll": "24.7136,46.6753",
    "radius_km": 10,
    "target_count": 100,
    "attempt_id": "abc123"
  },
  "lease_expires_at": "2026-01-05T21:30:00Z",
  "lease_sec": 180
}
```

### 2b. Report Results

```
POST /api/report_results.php
Host: forge.op-tg.com
```

### Request
```json
{
  "job_id": 456,
  "attempt_id": "abc123",
  "items": [
    {
      "name": "مطعم الشرق",
      "phone": "0501234567",
      "city": "الرياض",
      "rating": 4.5,
      "website": "https://example.com"
    }
  ],
  "cursor": 20,
  "done": false,
  "idempotency_key": "batch-001"
}
```

### Response
```json
{
  "ok": true,
  "added": 5,
  "duplicates": 2,
  "lease_expires_at": "2026-01-05T21:32:00Z",
  "done": false
}
```

---

## 3. SURVEY: Generate Smart Survey

### Endpoint
```
POST /v1/api/integration/survey.php
Host: forge.op-tg.com
```

### Request
```json
{
  "lead_id": 789,
  "data_gaps": ["budget", "decision_maker", "timeline"]
}
```

### Response
```json
{
  "ok": true,
  "survey": {
    "title": "استبيان تأهيل العميل",
    "questions": [
      {
        "id": "q1",
        "type": "single_choice",
        "question": "ما هي ميزانيتك التقريبية للتسويق؟",
        "options": ["أقل من 5000", "5000-15000", "أكثر من 15000"]
      }
    ]
  }
}
```

### Feature Flag
- `integration_survey_from_lead` must be enabled

---

## 4. EVIDENCE: Enrich Lead

### Endpoint
```
POST /api/reports?enrich=true
Host: op-target.vercel.app
```

### Request
```json
{
  "leadId": "uuid-123",
  "website": "https://example.com",
  "socialLinks": [
    { "platform": "instagram", "url": "https://instagram.com/example" }
  ]
}
```

### Response
```json
{
  "success": true,
  "evidence": {
    "sources": [
      {
        "type": "website",
        "status": "success",
        "parsed": {
          "title": "مطعم الشرق",
          "phones": ["0501234567"],
          "tracking": {
            "googleAnalytics": false,
            "metaPixel": false
          }
        }
      }
    ],
    "qualityScore": 75
  }
}
```

### Evidence Storage
- Stored in `lead_evidence` table
- Referenced by report claims

---

## 5. REPORT: Generate AI Report

### Endpoint
```
POST /api/reports?generate=true
Host: op-target.vercel.app
```

### Request
```json
{
  "prompt": "<context + user prompt>",
  "useSearch": true,
  "companyName": "مطعم الشرق",
  "city": "الرياض"
}
```

### Response
```json
{
  "data": {
    "company": { "name": "مطعم الشرق", "activity": "مطاعم" },
    "sector": { "primary": "food_beverage", "confidence": 0.9 },
    "evidence_summary": {
      "key_findings": [
        {
          "finding": "لا يوجد Google Analytics",
          "evidence_url": "https://example.com",
          "confidence": "high"
        }
      ]
    },
    "recommended_services": [
      {
        "tier": "tier1",
        "service": "إعداد Google Analytics",
        "why": "مبني على: لا يوجد tracking في الموقع",
        "confidence": 0.95
      }
    ]
  },
  "usage": { "promptTokens": 1500, "completionTokens": 2000 }
}
```

### Report Claims
كل claim يجب أن يكون:
- مربوط بـ `evidence_url` (مؤكد)
- أو مكتوب "غير مؤكد - مبني على افتراض"

---

## 6. SEND: WhatsApp Message

### Endpoint
```
POST /v1/api/whatsapp/send.php
Host: forge.op-tg.com
```

### Request
```json
{
  "phone": "966501234567",
  "message": "مرحباً، نود مناقشة فرص التسويق الرقمي لمطعمكم...",
  "template_id": "sales_intro"
}
```

### Response
```json
{
  "ok": true,
  "message_id": "wamid.xxx",
  "status": "sent"
}
```

### Rate Limit
- 30 messages per minute per user

### Feature Flag
- `integration_send_from_report` must be enabled

---

## Error Responses

### 400 Bad Request
```json
{
  "ok": false,
  "error": "VALIDATION_ERROR",
  "message": "الحقل 'phone' مطلوب",
  "details": [{ "field": "phone", "message": "required" }]
}
```

### 401 Unauthorized
```json
{
  "ok": false,
  "error": "UNAUTHORIZED",
  "message": "يرجى تسجيل الدخول"
}
```

### 403 Forbidden
```json
{
  "ok": false,
  "error": "FORBIDDEN",
  "message": "لا تملك صلاحية لهذا الإجراء"
}
```

### 429 Too Many Requests
```json
{
  "ok": false,
  "error": "RATE_LIMITED",
  "message": "تم تجاوز الحد المسموح",
  "retry_after": 60
}
```

---

## Headers المطلوبة

### CORS
```
Origin: http://localhost:3000
```

### Auth
```
Authorization: Bearer <token>
Cookie: session=<session_id>
```

### Content
```
Content-Type: application/json
```

### Rate Limit Response
```
X-RateLimit-Limit: 30
X-RateLimit-Remaining: 25
X-RateLimit-Reset: 2026-01-05T21:30:00Z
Retry-After: 60
```

---

## Feature Flags Status

| Flag | Required For |
|------|--------------|
| `integration_auth_bridge` | Cross-system auth |
| `integration_survey_from_lead` | Survey generation |
| `integration_send_from_report` | WhatsApp from report |
| `integration_worker_enabled` | Worker integration |

---

> **آخر تحديث**: 2026-01-05 21:15 UTC+3
