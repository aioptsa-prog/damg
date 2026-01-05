/**
 * WhatsApp Send from Report Endpoint
 * POST /api/integration/forge/whatsapp/send
 * 
 * Sends WhatsApp message using suggested_message from forge report.
 * 
 * SECURITY:
 * - Behind INTEGRATION_SEND_FROM_REPORT flag
 * - Requires authenticated user
 * - Validates lead ownership via RBAC
 * - Server-side only (OP-Target calls forge)
 * - Idempotency: prevents duplicate sends within 10 minutes
 * 
 * @since Phase 4
 */

import { query } from '../../../_db.js';
import { getAuthFromRequest, canAccessLead } from '../../../_auth.js';
import { FLAGS } from '../../../_flags.js';
import { createHmac, randomUUID } from 'crypto';

// Dedupe window: 10 minutes
const DEDUPE_WINDOW_MS = 10 * 60 * 1000;

interface SendRequest {
  opLeadId: string;
  reportId?: string;
  message?: string;  // Override suggested_message
  dryRun?: boolean;
}

interface SendResponse {
  ok: boolean;
  sent?: boolean;
  dry_run?: boolean;
  phone?: string;
  message_preview?: string;
  provider_response?: any;
  error?: string;
  dedupe_blocked?: boolean;
}

export default async function handler(req: any, res: any) {
  // === Feature Flag Check ===
  if (!FLAGS.SEND_FROM_REPORT) {
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
  let body: SendRequest;
  try {
    body = typeof req.body === 'string' ? JSON.parse(req.body) : req.body;
  } catch {
    return res.status(400).json({ ok: false, error: 'Invalid JSON' });
  }

  const { opLeadId, reportId, message: messageOverride, dryRun = false } = body;

  if (!opLeadId) {
    return res.status(400).json({ ok: false, error: 'Missing opLeadId' });
  }

  // === Check lead access ===
  const hasAccess = await canAccessLead(auth, opLeadId);
  if (!hasAccess) {
    return res.status(403).json({ ok: false, error: 'Access denied to this lead' });
  }

  try {
    // === Get link from lead_external_links ===
    const linkResult = await query(
      `SELECT external_lead_id, external_phone, external_name
       FROM lead_external_links 
       WHERE op_target_lead_id = $1 AND external_system = 'forge' AND link_status = 'active'`,
      [opLeadId]
    );

    if (linkResult.rows.length === 0) {
      return res.status(404).json({ 
        ok: false, 
        error: 'Lead not linked to forge',
        hint: 'Use /api/integration/forge/link to link this lead first'
      });
    }

    const link = linkResult.rows[0];
    const phone = link.external_phone;

    if (!phone) {
      return res.status(400).json({ 
        ok: false, 
        error: 'No phone number in link',
        hint: 'Link does not have external_phone set'
      });
    }

    // === Get report (latest forge report or specific reportId) ===
    let reportQuery: string;
    let reportParams: any[];

    if (reportId) {
      reportQuery = `SELECT id, output, suggested_message FROM reports WHERE id = $1 AND lead_id = $2`;
      reportParams = [reportId, opLeadId];
    } else {
      reportQuery = `SELECT id, output, suggested_message FROM reports 
                     WHERE lead_id = $1 AND source = 'forge' 
                     ORDER BY created_at DESC LIMIT 1`;
      reportParams = [opLeadId];
    }

    const reportResult = await query(reportQuery, reportParams);

    if (reportResult.rows.length === 0) {
      return res.status(404).json({ 
        ok: false, 
        error: 'No report found',
        hint: 'Generate a survey first via /api/integration/forge/survey'
      });
    }

    const report = reportResult.rows[0];
    
    // === Determine message to send ===
    let messageToSend = messageOverride;
    
    if (!messageToSend) {
      // Try suggested_message column first
      messageToSend = report.suggested_message;
      
      // Fallback to output.suggested_message if column is empty
      if (!messageToSend && report.output) {
        const output = typeof report.output === 'string' ? JSON.parse(report.output) : report.output;
        messageToSend = output.suggested_message || output.suggestedMessage;
      }
    }

    if (!messageToSend) {
      return res.status(400).json({ 
        ok: false, 
        error: 'No message available',
        hint: 'Report has no suggested_message. Provide message override.'
      });
    }

    // === Check idempotency (dedupe) ===
    const messageHash = createHmac('sha256', 'dedupe').update(`${phone}:${messageToSend}`).digest('hex').substring(0, 32);
    
    const dedupeResult = await query(
      `SELECT id FROM activities 
       WHERE lead_id = $1 
         AND type = 'whatsapp_send_integration'
         AND (payload->>'message_hash') = $2
         AND created_at > NOW() - INTERVAL '10 minutes'
       LIMIT 1`,
      [opLeadId, messageHash]
    );

    if (dedupeResult.rows.length > 0 && !dryRun) {
      return res.status(409).json({ 
        ok: false, 
        error: 'Duplicate send blocked',
        dedupe_blocked: true,
        hint: 'Same message was sent within last 10 minutes'
      });
    }

    // === Dry run mode ===
    if (dryRun) {
      return res.status(200).json({
        ok: true,
        dry_run: true,
        phone,
        message_preview: messageToSend.substring(0, 100) + (messageToSend.length > 100 ? '...' : ''),
        message_length: messageToSend.length,
        report_id: report.id,
      });
    }

    // === Get forge token ===
    const forgeToken = await getForgeToken(auth);
    if (!forgeToken) {
      return res.status(502).json({ 
        ok: false, 
        error: 'Failed to obtain forge token'
      });
    }

    // === Send via forge ===
    const sendResult = await sendViaForge(forgeToken, phone, messageToSend);

    // === Log activity ===
    await query(
      `INSERT INTO activities (id, lead_id, user_id, type, payload, created_at)
       VALUES ($1, $2, $3, $4, $5, NOW())`,
      [
        randomUUID(),
        opLeadId,
        auth.id,
        'whatsapp_send_integration',
        JSON.stringify({
          report_id: report.id,
          phone,
          message_hash: messageHash,
          success: sendResult.ok,
          provider_response: sendResult.provider_response,
          error: sendResult.error,
        })
      ]
    );

    // === Log audit ===
    await query(
      `INSERT INTO audit_logs (id, actor_user_id, action, entity_type, entity_id, after, created_at)
       VALUES ($1, $2, $3, $4, $5, $6, NOW())`,
      [
        randomUUID(),
        auth.id,
        'whatsapp_send_integration',
        'lead',
        opLeadId,
        JSON.stringify({
          report_id: report.id,
          phone,
          success: sendResult.ok,
        })
      ]
    );

    if (!sendResult.ok) {
      console.error(`[INTEGRATION] whatsapp/send: Failed for lead ${opLeadId}:`, sendResult.error);
      return res.status(502).json({
        ok: false,
        error: 'Failed to send message',
        details: sendResult.error,
        provider_response: sendResult.provider_response,
      });
    }

    console.log(`[INTEGRATION] whatsapp/send: Sent to ${phone} for lead ${opLeadId}`);

    return res.status(200).json({
      ok: true,
      sent: true,
      phone,
      message_preview: messageToSend.substring(0, 50) + '...',
      report_id: report.id,
      provider_response: sendResult.provider_response,
    });

  } catch (error: any) {
    console.error('[INTEGRATION] whatsapp/send error:', error);
    return res.status(500).json({ ok: false, error: 'Internal error', details: error.message });
  }
}

/**
 * Get forge integration token
 */
async function getForgeToken(auth: { id: string; role: string }): Promise<string | null> {
  const secret = process.env.INTEGRATION_SHARED_SECRET;
  const forgeBaseUrl = process.env.FORGE_API_BASE_URL || 'http://localhost:8080';

  if (!secret) {
    console.error('[INTEGRATION] whatsapp/send: INTEGRATION_SHARED_SECRET not configured');
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
    exp: assertion.exp,
    iat: assertion.iat,
    issuer: assertion.issuer,
    nonce: assertion.nonce,
    role: assertion.role,
    sub: assertion.sub,
  };
  const sig = createHmac('sha256', secret).update(JSON.stringify(canonical)).digest('hex');

  try {
    const response = await fetch(`${forgeBaseUrl}/v1/api/integration/exchange.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ...assertion, sig }),
    });

    const data = await response.json();
    if (!data.ok || !data.token) {
      return null;
    }

    return data.token;
  } catch (error) {
    console.error('[INTEGRATION] whatsapp/send: Token exchange error:', error);
    return null;
  }
}

/**
 * Send message via forge WhatsApp endpoint
 */
async function sendViaForge(token: string, phone: string, message: string): Promise<{
  ok: boolean;
  provider_response?: any;
  error?: string;
}> {
  const forgeBaseUrl = process.env.FORGE_API_BASE_URL || 'http://localhost:8080';

  try {
    const response = await fetch(`${forgeBaseUrl}/v1/api/integration/whatsapp/send.php`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${token}`,
      },
      body: JSON.stringify({ phone, message }),
    });

    const data = await response.json();

    if (!response.ok || !data.ok) {
      return {
        ok: false,
        error: data.error || 'Send failed',
        provider_response: data,
      };
    }

    return {
      ok: true,
      provider_response: data,
    };

  } catch (error: any) {
    return {
      ok: false,
      error: error.message,
    };
  }
}
