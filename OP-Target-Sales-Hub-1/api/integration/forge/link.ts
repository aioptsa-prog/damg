/**
 * Lead Linking Endpoint
 * POST /api/integration/forge/link
 * GET /api/integration/forge/link?op_target_lead_id=xxx
 * DELETE /api/integration/forge/link?op_target_lead_id=xxx
 * 
 * Links an OP-Target lead to a forge lead.
 * 
 * SECURITY:
 * - Behind INTEGRATION_SURVEY_FROM_LEAD flag
 * - Requires authenticated user
 * - Validates lead ownership via RBAC
 * - Prevents duplicate links
 * 
 * @since Phase 2
 */

import { query } from '../../_db.js';
import { getAuthFromRequest, canAccessLead } from '../../_auth.js';
import { FLAGS } from '../../_flags.js';

interface LinkRequest {
  op_target_lead_id: string;
  forge_lead_id: string;
  forge_phone?: string;
  forge_name?: string;
  forge_city?: string;
}

interface LinkResponse {
  ok: boolean;
  link?: {
    id: string;
    op_target_lead_id: string;
    external_lead_id: string;
    external_phone?: string;
    external_name?: string;
    linked_at: string;
  };
  error?: string;
}

export default async function handler(req: any, res: any) {
  // === Feature Flag Check ===
  if (!FLAGS.SURVEY_FROM_LEAD) {
    return res.status(404).json({ ok: false, error: 'Not found' });
  }

  // === Verify current user ===
  const auth = await getAuthFromRequest(req);
  if (!auth) {
    return res.status(401).json({ ok: false, error: 'Unauthorized' });
  }

  const method = req.method;

  // === GET: Retrieve link for a lead ===
  if (method === 'GET') {
    const opTargetLeadId = req.query?.op_target_lead_id;
    if (!opTargetLeadId) {
      return res.status(400).json({ ok: false, error: 'Missing op_target_lead_id' });
    }

    // Check lead access
    const hasAccess = await canAccessLead(auth, opTargetLeadId);
    if (!hasAccess) {
      return res.status(403).json({ ok: false, error: 'Access denied to this lead' });
    }

    try {
      const result = await query(
        `SELECT id, op_target_lead_id, external_lead_id, external_phone, 
                external_name, external_city, linked_at, link_status
         FROM lead_external_links 
         WHERE op_target_lead_id = $1 AND external_system = 'forge' AND link_status = 'active'`,
        [opTargetLeadId]
      );

      if (result.rows.length === 0) {
        return res.status(200).json({ ok: true, link: null });
      }

      const row = result.rows[0];
      return res.status(200).json({
        ok: true,
        link: {
          id: row.id,
          op_target_lead_id: row.op_target_lead_id,
          external_lead_id: row.external_lead_id,
          external_phone: row.external_phone,
          external_name: row.external_name,
          external_city: row.external_city,
          linked_at: row.linked_at,
          link_status: row.link_status,
        },
      });
    } catch (error) {
      console.error('[INTEGRATION] forge/link GET error:', error);
      return res.status(500).json({ ok: false, error: 'Database error' });
    }
  }

  // === POST: Create new link ===
  if (method === 'POST') {
    let body: LinkRequest;
    try {
      body = typeof req.body === 'string' ? JSON.parse(req.body) : req.body;
    } catch {
      return res.status(400).json({ ok: false, error: 'Invalid JSON' });
    }

    const { op_target_lead_id, forge_lead_id, forge_phone, forge_name, forge_city } = body;

    if (!op_target_lead_id || !forge_lead_id) {
      return res.status(400).json({ ok: false, error: 'Missing required fields' });
    }

    // Check lead access
    const hasAccess = await canAccessLead(auth, op_target_lead_id);
    if (!hasAccess) {
      return res.status(403).json({ ok: false, error: 'Access denied to this lead' });
    }

    try {
      // Check if link already exists
      const existing = await query(
        `SELECT id FROM lead_external_links 
         WHERE (op_target_lead_id = $1 AND external_system = 'forge')
            OR (external_system = 'forge' AND external_lead_id = $2)`,
        [op_target_lead_id, forge_lead_id]
      );

      if (existing.rows.length > 0) {
        return res.status(409).json({ 
          ok: false, 
          error: 'Link already exists for this lead or forge lead is already linked' 
        });
      }

      // Create link
      const result = await query(
        `INSERT INTO lead_external_links 
         (op_target_lead_id, external_system, external_lead_id, external_phone, 
          external_name, external_city, linked_by_user_id)
         VALUES ($1, 'forge', $2, $3, $4, $5, $6)
         RETURNING id, op_target_lead_id, external_lead_id, external_phone, 
                   external_name, external_city, linked_at`,
        [op_target_lead_id, forge_lead_id, forge_phone || null, forge_name || null, 
         forge_city || null, auth.id]
      );

      const row = result.rows[0];
      console.log(`[INTEGRATION] forge/link: Created link ${row.id} for lead ${op_target_lead_id} -> forge:${forge_lead_id}`);

      return res.status(201).json({
        ok: true,
        link: {
          id: row.id,
          op_target_lead_id: row.op_target_lead_id,
          external_lead_id: row.external_lead_id,
          external_phone: row.external_phone,
          external_name: row.external_name,
          external_city: row.external_city,
          linked_at: row.linked_at,
        },
      });
    } catch (error: any) {
      console.error('[INTEGRATION] forge/link POST error:', error);
      
      // Handle unique constraint violation
      if (error.code === '23505') {
        return res.status(409).json({ ok: false, error: 'Link already exists' });
      }
      
      return res.status(500).json({ ok: false, error: 'Database error' });
    }
  }

  // === DELETE: Remove link ===
  if (method === 'DELETE') {
    const opTargetLeadId = req.query?.op_target_lead_id;
    if (!opTargetLeadId) {
      return res.status(400).json({ ok: false, error: 'Missing op_target_lead_id' });
    }

    // Check lead access
    const hasAccess = await canAccessLead(auth, opTargetLeadId);
    if (!hasAccess) {
      return res.status(403).json({ ok: false, error: 'Access denied to this lead' });
    }

    try {
      const result = await query(
        `UPDATE lead_external_links 
         SET link_status = 'unlinked', updated_at = NOW()
         WHERE op_target_lead_id = $1 AND external_system = 'forge' AND link_status = 'active'
         RETURNING id`,
        [opTargetLeadId]
      );

      if (result.rows.length === 0) {
        return res.status(404).json({ ok: false, error: 'No active link found' });
      }

      console.log(`[INTEGRATION] forge/link: Unlinked lead ${opTargetLeadId}`);
      return res.status(200).json({ ok: true, unlinked: true });
    } catch (error) {
      console.error('[INTEGRATION] forge/link DELETE error:', error);
      return res.status(500).json({ ok: false, error: 'Database error' });
    }
  }

  // === Method not allowed ===
  res.setHeader('Allow', ['GET', 'POST', 'DELETE']);
  return res.status(405).json({ ok: false, error: 'Method not allowed' });
}
