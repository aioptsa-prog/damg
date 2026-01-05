/**
 * Integration Token Exchange Endpoint
 * GET /api/integration/forge-token
 * 
 * Server-side endpoint that:
 * 1. Verifies the current user via JWT cookie
 * 2. Creates an HMAC-signed assertion
 * 3. Exchanges it with forge for an integration token
 * 4. Returns the token to the frontend
 * 
 * SECURITY:
 * - Behind INTEGRATION_AUTH_BRIDGE flag
 * - Uses INTEGRATION_SHARED_SECRET (separate from JWT_SECRET)
 * - Server-to-server call (secret never exposed to browser)
 * - Short-lived tokens (5 minutes)
 * 
 * @since Phase 1
 */

import { createHmac, randomUUID } from 'crypto';
import { getAuthFromRequest } from '../_auth.js';
import { FLAGS } from '../_flags.js';

interface ForgeTokenResponse {
  ok: boolean;
  token?: string;
  expires_in?: number;
  forge_role?: string;
  error?: string;
}

interface AssertionPayload {
  issuer: string;
  sub: string;
  role: string;
  iat: number;
  exp: number;
  nonce: string;
}

export default async function handler(req: any, res: any) {
  // === Feature Flag Check ===
  if (!FLAGS.AUTH_BRIDGE) {
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

  // === Get configuration ===
  const secret = process.env.INTEGRATION_SHARED_SECRET;
  const forgeBaseUrl = process.env.FORGE_API_BASE_URL || 'http://localhost:8080';

  if (!secret) {
    console.error('[INTEGRATION] forge-token: INTEGRATION_SHARED_SECRET not configured');
    return res.status(500).json({ ok: false, error: 'Integration not configured' });
  }

  // === Build assertion ===
  const now = Math.floor(Date.now() / 1000);
  const assertion: AssertionPayload = {
    issuer: 'op-target',
    sub: auth.id,
    role: auth.role,
    iat: now,
    exp: now + 300, // 5 minutes
    nonce: randomUUID(),
  };

  // === Sign assertion ===
  // Canonical JSON: sorted keys, no 'sig' field
  const canonical = {
    exp: assertion.exp,
    iat: assertion.iat,
    issuer: assertion.issuer,
    nonce: assertion.nonce,
    role: assertion.role,
    sub: assertion.sub,
  };
  const canonicalJson = JSON.stringify(canonical);
  const sig = createHmac('sha256', secret).update(canonicalJson).digest('hex');

  // === Call forge exchange endpoint ===
  const exchangeUrl = `${forgeBaseUrl}/v1/api/integration/exchange.php`;
  const payload = {
    ...assertion,
    sig,
  };

  try {
    const response = await fetch(exchangeUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(payload),
    });

    const data: ForgeTokenResponse = await response.json();

    if (!response.ok || !data.ok) {
      console.error('[INTEGRATION] forge-token: Exchange failed:', data.error);
      return res.status(response.status >= 400 ? response.status : 502).json({
        ok: false,
        error: data.error || 'Token exchange failed',
      });
    }

    // === Log successful exchange ===
    console.log(`[INTEGRATION] forge-token: Token issued for user=${auth.id}, role=${auth.role}`);

    // === Return token to frontend ===
    return res.status(200).json({
      ok: true,
      token: data.token,
      expires_in: data.expires_in,
      forge_role: data.forge_role,
    });

  } catch (error) {
    console.error('[INTEGRATION] forge-token: Network error:', error);
    return res.status(502).json({
      ok: false,
      error: 'Failed to connect to forge',
    });
  }
}
