/**
 * Integration Enrich Endpoint
 * POST /api/integration/forge/enrich
 * 
 * Triggers a worker enrichment job for a lead via forge.
 * 
 * Input:
 * {
 *   "opLeadId": "uuid",
 *   "modules": ["maps", "website"],
 *   "options": { "force": false }
 * }
 * 
 * @since Phase 6
 */

import { query } from '../../_db.js';
import { getAuthFromRequest, canAccessLead } from '../../_auth.js';
import { FLAGS } from '../../_flags.js';
import { createHmac, randomUUID } from 'crypto';

const FORGE_API_BASE = process.env.FORGE_API_BASE_URL || 'http://localhost:8080';
const ALLOWED_MODULES = ['maps', 'website'];

export default async function handler(req: any, res: any) {
  // === Feature Flag Check ===
  if (!FLAGS.WORKER_ENRICH || !FLAGS.AUTH_BRIDGE) {
    return res.status(404).json({ ok: false, error: 'Not found' });
  }

  // === Only POST allowed ===
  if (req.method !== 'POST') {
    res.setHeader('Allow', ['POST']);
    return res.status(405).json({ ok: false, error: 'Method not allowed' });
  }

  // === Verify current user ===
  const auth = await getAuthFromRequest(req);
  if (!auth) {
    return res.status(401).json({ ok: false, error: 'Unauthorized' });
  }

  // === Parse request body ===
  let body: any;
  try {
    body = typeof req.body === 'string' ? JSON.parse(req.body) : req.body;
  } catch {
    return res.status(400).json({ ok: false, error: 'Invalid JSON' });
  }

  const { opLeadId, modules = ['maps', 'website'], options = {} } = body;

  if (!opLeadId) {
    return res.status(400).json({ ok: false, error: 'Missing opLeadId' });
  }

  // === Check lead access ===
  const hasAccess = await canAccessLead(auth, opLeadId);
  if (!hasAccess) {
    return res.status(403).json({ ok: false, error: 'Access denied to this lead' });
  }

  // === Validate Modules ===
  const validModules = modules.filter((m: string) => ALLOWED_MODULES.includes(m));
  if (validModules.length === 0) {
    return res.status(400).json({ 
      ok: false, 
      error: 'No valid modules specified',
      allowed: ALLOWED_MODULES 
    });
  }

  try {
    // === Get Forge Link ===
    const linkResult = await query(
      `SELECT external_lead_id, external_phone, external_name 
       FROM lead_external_links 
       WHERE op_target_lead_id = $1 AND external_system = 'forge' AND link_status = 'active'
       LIMIT 1`,
      [opLeadId]
    );

    if (linkResult.rows.length === 0) {
      return res.status(404).json({ ok: false, error: 'Lead not linked to forge' });
    }

    const forgeLeadId = linkResult.rows[0].external_lead_id;

    // === Get Forge Token ===
    const forgeToken = await getForgeToken(auth);
    if (!forgeToken) {
      return res.status(502).json({ ok: false, error: 'Failed to obtain forge token' });
    }

    // === Call Forge Jobs Create ===
    const correlationId = `enrich-${opLeadId.slice(0, 8)}-${Date.now()}`;
    
    const forgeResponse = await fetch(`${FORGE_API_BASE}/v1/api/integration/jobs/create.php`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${forgeToken}`,
      },
      body: JSON.stringify({
        opLeadId,
        forgeLeadId: parseInt(forgeLeadId, 10),
        modules: validModules,
        options: {
          ...options,
          correlationId,
        },
      }),
    });

    const forgeData = await forgeResponse.json();

    if (!forgeResponse.ok) {
      if (forgeResponse.status === 429) {
        return res.status(429).json({ 
          ok: false, 
          error: 'Rate limit exceeded',
          limit: forgeData.limit,
          used: forgeData.used
        });
      }
      if (forgeResponse.status === 409) {
        return res.status(409).json({
          ok: false,
          error: 'Job already in progress',
          existingJobId: forgeData.existingJobId,
          status: forgeData.status,
          progress: forgeData.progress
        });
      }
      return res.status(forgeResponse.status).json({ 
        ok: false, 
        error: forgeData.error || 'Failed to create job' 
      });
    }

    console.log(`[ENRICH] Job created: ${forgeData.jobId} for lead ${opLeadId} by ${auth.id}`);

    return res.status(200).json({
      ok: true,
      jobId: forgeData.jobId,
      status: forgeData.status,
      modules: forgeData.modules,
      correlationId: forgeData.correlationId || correlationId,
    });

  } catch (error: any) {
    console.error('[ENRICH] Error:', error.message);
    return res.status(502).json({ ok: false, error: 'Forge service unavailable' });
  }
}

/**
 * Get forge integration token
 */
async function getForgeToken(auth: { id: string; role: string }): Promise<string | null> {
  const secret = process.env.INTEGRATION_SHARED_SECRET;
  const forgeBaseUrl = process.env.FORGE_API_BASE_URL || 'http://localhost:8080';

  if (!secret) {
    console.error('[ENRICH] INTEGRATION_SHARED_SECRET not configured');
    return null;
  }

  const now = Math.floor(Date.now() / 1000);
  const assertion = {
    issuer: 'op-target',
    sub: auth.id,
    role: auth.role,
    iat: now,
    exp: now + 300,
    nonce: randomUUID(),
  };

  const canonical = {
    issuer: assertion.issuer,
    sub: assertion.sub,
    role: assertion.role,
    iat: assertion.iat,
    exp: assertion.exp,
    nonce: assertion.nonce,
  };

  const sig = createHmac('sha256', secret)
    .update(JSON.stringify(canonical))
    .digest('hex');

  try {
    const response = await fetch(`${forgeBaseUrl}/v1/api/integration/exchange.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ...assertion, sig }),
    });

    if (!response.ok) {
      console.error('[ENRICH] Token exchange failed:', response.status);
      return null;
    }

    const data = await response.json();
    return data.token || null;
  } catch (err: any) {
    console.error('[ENRICH] Token exchange error:', err.message);
    return null;
  }
}
