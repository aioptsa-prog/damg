# Map Crash Root Cause Analysis

**Date:** 2026-01-04  
**Status:** ✅ RESOLVED  
**Error:** `TypeError: h.map is not a function`

---

## Root Cause Identification

### Error Location (Minified → Source)

| Field | Value |
|-------|-------|
| **Minified Variable** | `h` |
| **Actual Variable** | `svc.package_suggestion.scope` |
| **File** | `components/ReportView.tsx` |
| **Line** | 303 (previously 325) |
| **Operation** | `.split('\n').map()` |

### The Problem

```typescript
// BEFORE (CRASH)
{svc.package_suggestion.scope.split('\n').map((line: string, idx: number) => (
  <div key={idx}>...</div>
))}
```

**Why it crashed:**
- `scope` was expected to be a `string` (to call `.split()`)
- AI sometimes returns `scope` as:
  - `null`
  - `undefined`
  - An array `[]`
  - An object `{}`
- When `scope` is not a string, `.split()` fails
- When `scope` is not an array, `.map()` fails

### Data Flow

```
AI Response → report.output → ReportView → .map() → CRASH
```

---

## Solution: Normalize Layer

### Architecture

```
AI Response → normalizeReport() → NormalizedReportModel → ReportView → .map() → ✅ SAFE
```

### Key Changes

1. **Created `domain/normalizeReport.ts`**
   - Single source of truth for data normalization
   - Guarantees ALL array fields are arrays
   - Converts `scope` string to array automatically

2. **Updated `components/ReportView.tsx`**
   - Uses `normalizeReport()` once at the top
   - All `.map()` calls now operate on guaranteed arrays
   - No more scattered `safeArray()` calls

### The Fix

```typescript
// AFTER (SAFE)
// In normalizeReport.ts
function normalizeScope(scope: unknown): string[] {
  if (Array.isArray(scope)) {
    return scope.map(item => toString(item)).filter(Boolean);
  }
  if (typeof scope === 'string' && scope.trim()) {
    return scope.split('\n').map(line => line.trim()).filter(Boolean);
  }
  return [];
}

// In ReportView.tsx
const model = useMemo(() => normalizeReport(report.output), [report.output]);

// scope is now ALWAYS an array
{svc.package_suggestion.scope.map((line, idx) => (
  <div key={idx}>...</div>
))}
```

---

## Files Changed

| File | Change |
|------|--------|
| `domain/normalizeReport.ts` | NEW - Normalization layer with Zod schemas |
| `components/ReportView.tsx` | Uses `normalizeReport()`, removed scattered safe accessors |
| `tests/normalizeReport.test.ts` | NEW - 28 unit tests covering all edge cases |

---

## Verification

### Unit Tests (28 passed)

```bash
npm run test -- tests/normalizeReport.test.ts

✓ package_suggestion.scope normalization - CRITICAL (4)
  ✓ should convert scope string to array (fixes .map crash)
  ✓ should keep scope as array if already array
  ✓ should handle null scope
  ✓ should handle undefined scope

✓ all array fields are guaranteed arrays (1)
  ✓ should guarantee ALL array fields are arrays regardless of input
```

### Build Test

```bash
npm run build
# Exit code: 0
# ✓ built in 6.22s
```

---

## Contract: All Array Fields

After `normalizeReport()`, these fields are **GUARANTEED** to be arrays:

| Field Path | Type |
|------------|------|
| `sector.matched_signals` | `string[]` |
| `evidence_summary.key_findings` | `KeyFinding[]` |
| `evidence_summary.tech_hints` | `string[]` |
| `website_audit.issues` | `WebsiteIssue[]` |
| `social_audit.presence` | `SocialPresence[]` |
| `social_audit.content_gaps` | `string[]` |
| `social_audit.quick_content_ideas` | `string[]` |
| `pain_points` | `PainPoint[]` |
| `recommended_services` | `RecommendedService[]` |
| `recommended_services[].package_suggestion.scope` | `string[]` |
| `talk_track.objection_handlers` | `ObjectionHandler[]` |
| `talk_track.whatsapp_messages` | `WhatsAppMessage[]` |
| `follow_up_plan` | `FollowUpStep[]` |

---

## Prevention

1. **All report data MUST pass through `normalizeReport()`** before reaching UI
2. **Unit tests** prevent regression
3. **TypeScript types** enforce correct usage
4. **No direct access** to `report.output` in components
