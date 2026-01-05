/**
 * Forge Integration Service
 * 
 * Provides functions to interact with forge.op-tg.com through the Auth Bridge.
 * Tokens are obtained lazily and cached in memory with TTL.
 * 
 * SECURITY:
 * - Tokens stored in memory only (not localStorage)
 * - Short-lived tokens (5 minutes)
 * - Automatic refresh on expiry
 * 
 * @since Phase 1
 */

interface ForgeToken {
  token: string;
  expiresAt: number; // Unix timestamp in ms
  forgeRole: string;
}

interface ForgeTokenResponse {
  ok: boolean;
  token?: string;
  expires_in?: number;
  forge_role?: string;
  error?: string;
}

// In-memory token cache
let cachedToken: ForgeToken | null = null;

// Buffer time before expiry to refresh (30 seconds)
const EXPIRY_BUFFER_MS = 30 * 1000;

/**
 * Check if integration auth bridge is enabled
 * This checks the server-side flag via the health endpoint
 */
export async function isAuthBridgeEnabled(): Promise<boolean> {
  try {
    const response = await fetch('/api/health');
    if (!response.ok) return false;
    
    const data = await response.json();
    return data.checks?.flags?.AUTH_BRIDGE === true;
  } catch {
    return false;
  }
}

/**
 * Get a valid forge integration token
 * Returns cached token if still valid, otherwise fetches a new one
 * 
 * @returns Token string or null if not available
 */
export async function getForgeToken(): Promise<string | null> {
  // Check if cached token is still valid
  if (cachedToken && cachedToken.expiresAt > Date.now() + EXPIRY_BUFFER_MS) {
    return cachedToken.token;
  }

  // Clear expired token
  cachedToken = null;

  try {
    const response = await fetch('/api/integration/forge-token', {
      method: 'GET',
      credentials: 'include', // Include cookies for JWT auth
    });

    if (!response.ok) {
      console.error('[ForgeIntegration] Failed to get token:', response.status);
      return null;
    }

    const data: ForgeTokenResponse = await response.json();

    if (!data.ok || !data.token) {
      console.error('[ForgeIntegration] Token exchange failed:', data.error);
      return null;
    }

    // Cache the token
    cachedToken = {
      token: data.token,
      expiresAt: Date.now() + (data.expires_in || 300) * 1000,
      forgeRole: data.forge_role || 'agent',
    };

    return cachedToken.token;

  } catch (error) {
    console.error('[ForgeIntegration] Network error:', error);
    return null;
  }
}

/**
 * Get the current forge role (if token is cached)
 */
export function getCachedForgeRole(): string | null {
  if (cachedToken && cachedToken.expiresAt > Date.now()) {
    return cachedToken.forgeRole;
  }
  return null;
}

/**
 * Clear the cached token (e.g., on logout)
 */
export function clearForgeToken(): void {
  cachedToken = null;
}

/**
 * Make an authenticated request to forge API
 * 
 * @param path API path (e.g., '/v1/api/leads/index.php')
 * @param options Fetch options
 * @returns Response or null on auth failure
 */
export async function forgeApiFetch(
  path: string,
  options: RequestInit = {}
): Promise<Response | null> {
  const token = await getForgeToken();
  
  if (!token) {
    console.error('[ForgeIntegration] No token available for API call');
    return null;
  }

  const forgeBaseUrl = '/forge-proxy'; // Assumes a proxy is set up, or use direct URL in dev
  
  const headers = new Headers(options.headers);
  headers.set('Authorization', `Bearer ${token}`);
  headers.set('Content-Type', 'application/json');

  try {
    const response = await fetch(`${forgeBaseUrl}${path}`, {
      ...options,
      headers,
    });

    // If unauthorized, clear token and retry once
    if (response.status === 401) {
      clearForgeToken();
      const newToken = await getForgeToken();
      if (newToken) {
        headers.set('Authorization', `Bearer ${newToken}`);
        return fetch(`${forgeBaseUrl}${path}`, {
          ...options,
          headers,
        });
      }
    }

    return response;

  } catch (error) {
    console.error('[ForgeIntegration] API call failed:', error);
    return null;
  }
}
