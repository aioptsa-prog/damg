/**
 * Integration Feature Flags
 * All new integration features are behind these flags for safe rollout
 * 
 * Usage:
 *   import { FLAGS } from './_flags.js';
 *   if (FLAGS.AUTH_BRIDGE) { ... }
 */

export const FLAGS = {
  /**
   * Enable cross-project authentication bridge
   * When true: Accept tokens from forge.op-tg.com
   */
  AUTH_BRIDGE: process.env.INTEGRATION_AUTH_BRIDGE === 'true',

  /**
   * Enable survey generation from forge leads
   * When true: /api/reports can accept forge lead data
   */
  SURVEY_FROM_LEAD: process.env.INTEGRATION_SURVEY_FROM_LEAD === 'true',

  /**
   * Enable WhatsApp sending from OP-Target reports
   * When true: Reports page shows "Send via WhatsApp" button
   */
  SEND_FROM_REPORT: process.env.INTEGRATION_SEND_FROM_REPORT === 'true',

  /**
   * Enable unified lead view combining both systems
   * When true: Lead detail shows data from both projects
   */
  UNIFIED_LEAD_VIEW: process.env.INTEGRATION_UNIFIED_LEAD_VIEW === 'true',

  /**
   * Enable worker enrichment from forge
   * When true: Can trigger worker jobs to collect lead data
   */
  WORKER_ENRICH: process.env.INTEGRATION_WORKER_ENRICH === 'true',
};

/**
 * Log flag status on startup (for debugging)
 */
export function logFlagStatus(): void {
  const enabled = Object.entries(FLAGS)
    .filter(([, value]) => value)
    .map(([key]) => key);
  
  if (enabled.length > 0) {
    console.log(`[FLAGS] Enabled: ${enabled.join(', ')}`);
  }
}
