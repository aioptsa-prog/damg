# Enrichment Service Contract

**Version:** 1.0.0  
**Date:** 2026-01-04

---

## Overview

هذا المستند يحدد العقد الرسمي (Contract) لخدمة الإثراء (Enrichment Service) التي تجمع البيانات من مصادر متعددة.

---

## Request Schema

```typescript
interface EnrichmentRequest {
  website?: string;      // URL of company website
  instagram?: string;    // Instagram handle or URL
  maps?: string;         // Google Maps URL or place ID
}
```

### Validation Rules

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| website | string | No | Valid URL format |
| instagram | string | No | Handle or URL |
| maps | string | No | Google Maps URL |

**Note:** At least one field should be provided for meaningful enrichment.

---

## Response Schema

### Success Response

```typescript
interface EnrichmentResponse {
  ok: true;
  data: EvidencePack;
}

interface EvidencePack {
  sources_used: string[];           // ['website', 'instagram', 'google_maps']
  key_findings: KeyFinding[];       // Array of findings
  tech_hints: string[];             // ['WordPress', 'WooCommerce', etc.]
  contacts_found: ContactsFound;    // Extracted contact info
  fetch_status: FetchStatus[];      // Status per source
  warnings: string[];               // Any warnings
}

interface KeyFinding {
  finding: string;                  // Description of finding
  evidence_url?: string;            // Source URL
  confidence: 'low' | 'medium' | 'high';
}

interface ContactsFound {
  phone?: string;
  whatsapp?: string;
  email?: string;
}

interface FetchStatus {
  source: string;                   // 'website' | 'instagram' | 'google_maps'
  status: 'success' | 'failed' | 'skipped';
  error?: string;                   // Error message if failed
}
```

### Error Response

```typescript
interface EnrichmentErrorResponse {
  ok: false;
  error: {
    code: string;           // Error code
    message: string;        // Human-readable message (Arabic)
    details?: unknown;      // Additional details
  };
}
```

### Error Codes

| Code | Description |
|------|-------------|
| `VALIDATION_ERROR` | Invalid input parameters |
| `NETWORK_ERROR` | Failed to reach external sources |
| `PARSE_ERROR` | Failed to parse response |
| `RATE_LIMIT` | Too many requests |
| `NO_DATA` | No data found from any source |

---

## Response Examples

### Success - Full Data

```json
{
  "ok": true,
  "data": {
    "sources_used": ["website", "instagram", "google_maps"],
    "key_findings": [
      {
        "finding": "الموقع يستخدم بنية WordPress ويفتقر لتحسين محركات البحث SEO التقني.",
        "evidence_url": "https://example.com",
        "confidence": "high"
      },
      {
        "finding": "حساب الانستجرام (@example) نشط ولكنه يفتقر للهوية البصرية الموحدة.",
        "evidence_url": "https://instagram.com/example",
        "confidence": "medium"
      }
    ],
    "tech_hints": ["WordPress", "WooCommerce", "Google Analytics"],
    "contacts_found": {
      "phone": "+966501234567",
      "email": "info@example.com"
    },
    "fetch_status": [
      { "source": "website", "status": "success" },
      { "source": "instagram", "status": "success" },
      { "source": "google_maps", "status": "success" }
    ],
    "warnings": []
  }
}
```

### Success - Partial Data

```json
{
  "ok": true,
  "data": {
    "sources_used": ["website"],
    "key_findings": [
      {
        "finding": "الموقع يستخدم بنية WordPress.",
        "evidence_url": "https://example.com",
        "confidence": "high"
      }
    ],
    "tech_hints": ["WordPress"],
    "contacts_found": {},
    "fetch_status": [
      { "source": "website", "status": "success" },
      { "source": "instagram", "status": "skipped" },
      { "source": "google_maps", "status": "failed", "error": "Not found" }
    ],
    "warnings": ["لم يتم العثور على بيانات من خرائط جوجل"]
  }
}
```

### Success - No Data Found

```json
{
  "ok": true,
  "data": {
    "sources_used": [],
    "key_findings": [
      {
        "finding": "لا توجد أدلة رقمية كافية. العميل قد يكون في مرحلة التأسيس أو يعتمد على الأوفلاين.",
        "confidence": "low"
      }
    ],
    "tech_hints": [],
    "contacts_found": {},
    "fetch_status": [],
    "warnings": ["لم يتم توفير أي مصادر للبحث"]
  }
}
```

### Error Response

```json
{
  "ok": false,
  "error": {
    "code": "NETWORK_ERROR",
    "message": "فشل الاتصال بالمصادر الخارجية. يرجى المحاولة لاحقاً.",
    "details": {
      "failedSources": ["website", "instagram"]
    }
  }
}
```

---

## Zod Schema (TypeScript)

```typescript
import { z } from 'zod';

export const KeyFindingSchema = z.object({
  finding: z.string().default(''),
  evidence_url: z.string().optional(),
  confidence: z.enum(['low', 'medium', 'high']).default('low'),
});

export const FetchStatusSchema = z.object({
  source: z.string(),
  status: z.enum(['success', 'failed', 'skipped']),
  error: z.string().optional(),
});

export const ContactsFoundSchema = z.object({
  phone: z.string().optional(),
  whatsapp: z.string().optional(),
  email: z.string().optional(),
});

export const EvidencePackSchema = z.object({
  sources_used: z.array(z.string()).default([]),
  key_findings: z.array(KeyFindingSchema).default([]),
  tech_hints: z.array(z.string()).default([]),
  contacts_found: ContactsFoundSchema.default({}),
  fetch_status: z.array(FetchStatusSchema).default([]),
  warnings: z.array(z.string()).default([]),
});
```

---

## Frontend Usage

```typescript
import { parseEvidencePack } from '../domain/schemas';

// Always parse API response before use
const response = await enrichmentService.enrichLead(website, instagram, maps);
const evidence = parseEvidencePack(response);

// Now safe to use - all fields have defaults
console.log(evidence.key_findings.length); // Never crashes
console.log(evidence.contacts_found.phone ?? 'غير متوفر');
```

---

## State Machine

```
idle → running → partial → done
         ↓
       failed
```

| State | Description |
|-------|-------------|
| `idle` | Initial state, no enrichment started |
| `running` | Enrichment in progress |
| `partial` | Some sources succeeded, some failed |
| `done` | All sources processed successfully |
| `failed` | Critical error, no data available |

---

## Fallback UI Guidelines

| Scenario | UI Behavior |
|----------|-------------|
| Missing `confidence` | Show "غير متوفر" or 0% |
| Empty `key_findings` | Show "لا توجد نتائج" message |
| Missing `contacts_found` | Hide contact section |
| `fetch_status` has failures | Show warning banner |
| Complete failure | Show error state with retry button |
