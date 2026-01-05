# FEATURE_FLAGS.md
> Phase 0: Feature Flags Configuration
> Generated: 2026-01-04

---

## Overview

All new integration features are behind feature flags to enable:
- Gradual rollout
- Quick rollback if issues arise
- A/B testing
- Environment-specific configuration

---

## Flag Definitions

| Flag Name | Default | Description |
|-----------|---------|-------------|
| `INTEGRATION_AUTH_BRIDGE` | `false` | Enable cross-project authentication |
| `INTEGRATION_SURVEY_FROM_LEAD` | `false` | Enable survey generation from forge leads |
| `INTEGRATION_SEND_FROM_REPORT` | `false` | Enable WhatsApp sending from OP-Target reports |
| `INTEGRATION_UNIFIED_LEAD_VIEW` | `false` | Enable unified lead view combining both systems |

---

## Implementation

### Project A: OP-Target-Sales-Hub-1

**Location**: Environment variables + runtime config

**File**: `.env` (add these lines)
```env
# Integration Feature Flags (Phase 1+)
INTEGRATION_AUTH_BRIDGE=false
INTEGRATION_SURVEY_FROM_LEAD=false
INTEGRATION_SEND_FROM_REPORT=false
INTEGRATION_UNIFIED_LEAD_VIEW=false
```

**Usage in Code**:
```typescript
// api/_flags.ts (new file)
export const FLAGS = {
  AUTH_BRIDGE: process.env.INTEGRATION_AUTH_BRIDGE === 'true',
  SURVEY_FROM_LEAD: process.env.INTEGRATION_SURVEY_FROM_LEAD === 'true',
  SEND_FROM_REPORT: process.env.INTEGRATION_SEND_FROM_REPORT === 'true',
  UNIFIED_LEAD_VIEW: process.env.INTEGRATION_UNIFIED_LEAD_VIEW === 'true',
};

// Usage example:
import { FLAGS } from './_flags.js';
if (FLAGS.AUTH_BRIDGE) {
  // New integration code
}
```

### Project B: forge.op-tg.com

**Location**: `settings` table in SQLite database

**SQL to add flags**:
```sql
INSERT OR IGNORE INTO settings (key, value) VALUES 
  ('integration_auth_bridge', '0'),
  ('integration_survey_from_lead', '0'),
  ('integration_send_from_report', '0'),
  ('integration_unified_lead_view', '0');
```

**Usage in Code**:
```php
// lib/flags.php (new file)
<?php
function integration_flag(string $name): bool {
    return get_setting('integration_' . $name, '0') === '1';
}

// Usage example:
if (integration_flag('auth_bridge')) {
    // New integration code
}
```

---

## Flag States by Phase

| Phase | AUTH_BRIDGE | SURVEY_FROM_LEAD | SEND_FROM_REPORT | UNIFIED_LEAD_VIEW |
|-------|-------------|------------------|------------------|-------------------|
| 0 (Baseline) | ❌ | ❌ | ❌ | ❌ |
| 1 (Auth Bridge) | ✅ | ❌ | ❌ | ❌ |
| 2 (Lead Link) | ✅ | ✅ | ❌ | ❌ |
| 3 (Orchestrator) | ✅ | ✅ | ✅ | ❌ |
| 4 (UI Integration) | ✅ | ✅ | ✅ | ✅ |

---

## Rollback Procedure

To disable any integration feature:

### OP-Target:
```bash
# In .env file, set flag to false:
INTEGRATION_AUTH_BRIDGE=false

# Restart the server
npm run dev  # or restart production
```

### forge:
```sql
-- In SQLite:
UPDATE settings SET value = '0' WHERE key = 'integration_auth_bridge';
```

Or via Admin UI: Settings → Integration → Toggle off

---

## Monitoring

When a flag is enabled, log the activation:

```typescript
// OP-Target
console.log(`[FLAG] AUTH_BRIDGE enabled at ${new Date().toISOString()}`);
```

```php
// forge
error_log("[FLAG] auth_bridge enabled at " . date('c'));
```
