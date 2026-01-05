/**
 * Frontend Feature Flags
 * 
 * Fetches feature flags from the health endpoint.
 * Used to conditionally show integration UI.
 * 
 * @since Phase 5
 */

interface FeatureFlags {
  UNIFIED_LEAD_VIEW: boolean;
  AUTH_BRIDGE: boolean;
  SURVEY_FROM_LEAD: boolean;
  SEND_FROM_REPORT: boolean;
  WORKER_ENRICH: boolean;
}

let cachedFlags: FeatureFlags | null = null;
let lastFetch = 0;
const CACHE_TTL = 60000; // 1 minute

/**
 * Get feature flags from server
 */
export async function getFeatureFlags(): Promise<FeatureFlags> {
  const now = Date.now();
  
  // Return cached if fresh
  if (cachedFlags && now - lastFetch < CACHE_TTL) {
    return cachedFlags;
  }

  try {
    const response = await fetch('/api/health');
    const data = await response.json();
    
    cachedFlags = {
      UNIFIED_LEAD_VIEW: data.flags?.UNIFIED_LEAD_VIEW === true,
      AUTH_BRIDGE: data.flags?.AUTH_BRIDGE === true,
      SURVEY_FROM_LEAD: data.flags?.SURVEY_FROM_LEAD === true,
      SEND_FROM_REPORT: data.flags?.SEND_FROM_REPORT === true,
      WORKER_ENRICH: data.flags?.WORKER_ENRICH === true,
    };
    lastFetch = now;
    
    return cachedFlags;
  } catch (error) {
    console.error('[FeatureFlags] Failed to fetch:', error);
    // Return safe defaults
    return {
      UNIFIED_LEAD_VIEW: false,
      AUTH_BRIDGE: false,
      SURVEY_FROM_LEAD: false,
      SEND_FROM_REPORT: false,
      WORKER_ENRICH: false,
    };
  }
}

/**
 * Check if Forge Intel tab should be shown
 */
export async function shouldShowForgeIntel(): Promise<boolean> {
  const flags = await getFeatureFlags();
  return flags.UNIFIED_LEAD_VIEW && flags.AUTH_BRIDGE;
}

/**
 * Clear cached flags (for testing)
 */
export function clearFlagsCache(): void {
  cachedFlags = null;
  lastFetch = 0;
}
