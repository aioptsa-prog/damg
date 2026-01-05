/**
 * Integration Snapshot Endpoint
 * GET /api/integration/forge/snapshot?opLeadId=...
 * 
 * Returns the latest snapshot for a linked lead.
 * 
 * @since Phase 6
 */

import { query } from '../../_db.js';
import { getAuthFromRequest, canAccessLead } from '../../_auth.js';
import { FLAGS } from '../../_flags.js';
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

  const { opLeadId } = req.query;

  if (!opLeadId) {
    return res.status(400).json({ ok: false, error: 'Missing opLeadId' });
  }

  // === Check lead access ===
  const hasAccess = await canAccessLead(auth, opLeadId);
  if (!hasAccess) {
    return res.status(403).json({ ok: false, error: 'Access denied to this lead' });
  }

  try {
    // === Get Forge Link ===
    const linkResult = await query(
      `SELECT external_lead_id FROM lead_external_links 
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

    // === Call Forge Snapshot API ===
    const forgeResponse = await fetch(
      `${FORGE_API_BASE}/v1/api/integration/leads/snapshot.php?forgeLeadId=${encodeURIComponent(forgeLeadId)}`,
      {
        method: 'GET',
        headers: {
          'Authorization': `Bearer ${forgeToken}`,
        },
      }
    );

    const forgeData = await forgeResponse.json();

    if (!forgeResponse.ok) {
      if (forgeResponse.status === 404) {
        return res.status(404).json({ 
          ok: false, 
          error: 'No snapshot found',
          hint: 'Run Worker Enrich first to collect lead data'
        });
      }
      return res.status(forgeResponse.status).json({ 
        ok: false, 
        error: forgeData.error || 'Failed to get snapshot' 
      });
    }

    return res.status(200).json({
      ok: true,
      forgeLeadId: forgeData.forgeLeadId,
      snapshot: forgeData.snapshot,
      source: forgeData.source,
      jobId: forgeData.jobId,
      created_at: forgeData.created_at,
    });

  } catch (error: any) {
    console.error('[SNAPSHOT] Error:', error.message);
    return res.status(502).json({ ok: false, error: 'Forge service unavailable' });
  }
}

/**
 * Get forge integration token
 */
async function getForgeToken(auth: { id: string; role: string }): Promise<string | null> {
  const secret = process.env.INTEGRATION_SHARED_SECRET;
  const forgeBaseUrl = process.env.FORGE_API_BASE_URL || 'http://localhost:8080';

  if (!secret) return null;

  const now = Math.floor(Date.now() / 1000);
  const assertion = {
    issuer: 'op-target',
    sub: auth.id,
    role: auth.role,
    iat: now,
    exp: now + 300,
    nonce: randomUUID(),
  };

  const sig = createHmac('sha256', secret)
    .update(JSON.stringify(assertion))
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
