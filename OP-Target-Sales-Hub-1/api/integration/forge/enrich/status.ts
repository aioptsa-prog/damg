/**
 * Integration Enrich Status Endpoint
 * GET /api/integration/forge/enrich/status?opLeadId=...&jobId=...
 * 
 * Returns the status of an enrichment job.
 * 
 * @since Phase 6
 */

import { query } from '../../../_db.js';
import { getAuthFromRequest, canAccessLead } from '../../../_auth.js';
import { FLAGS } from '../../../_flags.js';
import { createHmac, randomUUID } from 'crypto';

const FORGE_API_BASE = process.env.FORGE_API_BASE_URL || 'http://localhost:8080';

export default async function handler(req: any, res: any) {
  // === Feature Flag Check ===
  if (!FLAGS.WORKER_ENRICH || !FLAGS.AUTH_BRIDGE) {
    return res.status(404).json({ ok: false, error: 'Not found' });
  }

  // === Only GET allowed ===
  if (req.method !== 'GET') {
    res.setHeader('Allow', ['GET']);
    return res.status(405).json({ ok: false, error: 'Method not allowed' });
  }

  // === Verify current user ===
  const auth = await getAuthFromRequest(req);
  if (!auth) {
    return res.status(401).json({ ok: false, error: 'Unauthorized' });
  }

  const { opLeadId, jobId } = req.query;

  if (!opLeadId && !jobId) {
    return res.status(400).json({ ok: false, error: 'Missing opLeadId or jobId' });
  }

  // === Check lead access if opLeadId provided ===
  if (opLeadId) {
    const hasAccess = await canAccessLead(auth, opLeadId);
    if (!hasAccess) {
      return res.status(403).json({ ok: false, error: 'Access denied to this lead' });
    }
  }

  try {
    // === Get Forge Token ===
    const forgeToken = await getForgeToken(auth);
    if (!forgeToken) {
      return res.status(502).json({ ok: false, error: 'Failed to obtain forge token' });
    }

    // If we have jobId, fetch status directly
    let targetJobId = jobId;

    // If no jobId but have opLeadId, we need to find the latest job
    // This requires getting the forge link first
    if (!targetJobId && opLeadId) {
      const linkResult = await query(
        `SELECT external_lead_id FROM lead_external_links 
         WHERE op_target_lead_id = $1 AND external_system = 'forge' AND link_status = 'active'
         LIMIT 1`,
        [opLeadId]
      );

      if (linkResult.rows.length === 0) {
        return res.status(404).json({ ok: false, error: 'Lead not linked to forge' });
      }

      // We don't have a way to get latest job by forgeLeadId from forge API
      // So we'll return an error asking for jobId
      return res.status(400).json({ 
        ok: false, 
        error: 'jobId required for status check',
        hint: 'Store the jobId from the enrich response'
      });
    }

    // === Call Forge Jobs Status ===
    const forgeResponse = await fetch(
      `${FORGE_API_BASE}/v1/api/integration/jobs/status.php?jobId=${encodeURIComponent(targetJobId)}`,
      {
        method: 'GET',
        headers: {
          'Authorization': `Bearer ${forgeToken}`,
        },
      }
    );

    const forgeData = await forgeResponse.json();

    if (!forgeResponse.ok) {
      return res.status(forgeResponse.status).json({ 
        ok: false, 
        error: forgeData.error || 'Failed to get job status' 
      });
    }

    return res.status(200).json({
      ok: true,
      jobId: forgeData.jobId,
      status: forgeData.status,
      progress: forgeData.progress,
      modules: forgeData.modules,
      created_at: forgeData.created_at,
      started_at: forgeData.started_at,
      finished_at: forgeData.finished_at,
      last_error: forgeData.last_error,
    });

  } catch (error: any) {
    console.error('[ENRICH_STATUS] Error:', error.message);
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

    if (!response.ok) return null;
    const data = await response.json();
    return data.token || null;
  } catch {
    return null;
  }
}
