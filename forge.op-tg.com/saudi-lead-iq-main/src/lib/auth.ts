/**
 * Centralized Authentication Utility
 * 
 * Provides unified token management across the application.
 * All authentication token operations should use this module.
 */

// Token storage keys - use these constants instead of hardcoded strings
export const TOKEN_KEYS = {
    /** Public user token (customer-facing platform) */
    PUBLIC: 'lead_iq_public_token',
    /** Admin/Agent user token (internal dashboard) */
    ADMIN: 'lead_iq_auth_token',
    /** Legacy token key for backward compatibility */
    LEGACY: 'auth_token'
} as const;

// User data storage keys
export const USER_KEYS = {
    /** Public user profile data */
    PUBLIC: 'lead_iq_public_user'
} as const;

/**
 * Get the current authentication token.
 * Checks all possible token storage locations in priority order:
 * 1. Public user token
 * 2. Admin user token
 * 3. Legacy token
 * 
 * @returns The authentication token or null if not authenticated
 */
export function getAuthToken(): string | null {
    return (
        localStorage.getItem(TOKEN_KEYS.PUBLIC) ||
        localStorage.getItem(TOKEN_KEYS.ADMIN) ||
        localStorage.getItem(TOKEN_KEYS.LEGACY)
    );
}

/**
 * Check if user is authenticated (has a valid token stored)
 */
export function isAuthenticated(): boolean {
    return getAuthToken() !== null;
}

/**
 * Get the Authorization header value for API requests
 * @returns The Bearer token header value or empty string
 */
export function getAuthHeader(): string {
    const token = getAuthToken();
    return token ? `Bearer ${token}` : '';
}

/**
 * Store public user token
 */
export function setPublicToken(token: string): void {
    localStorage.setItem(TOKEN_KEYS.PUBLIC, token);
}

/**
 * Store admin user token
 */
export function setAdminToken(token: string): void {
    localStorage.setItem(TOKEN_KEYS.ADMIN, token);
}

/**
 * Store public user data
 */
export function setPublicUser(user: object): void {
    localStorage.setItem(USER_KEYS.PUBLIC, JSON.stringify(user));
}

/**
 * Get stored public user data
 */
export function getPublicUser<T = any>(): T | null {
    const data = localStorage.getItem(USER_KEYS.PUBLIC);
    if (!data) return null;
    try {
        return JSON.parse(data) as T;
    } catch {
        return null;
    }
}

/**
 * Clear all authentication tokens and user data.
 * Use this for complete logout.
 */
export function clearAuthTokens(): void {
    // Clear all token variants
    Object.values(TOKEN_KEYS).forEach(key => localStorage.removeItem(key));
    // Clear user data
    Object.values(USER_KEYS).forEach(key => localStorage.removeItem(key));
}

/**
 * Clear only public user authentication
 */
export function clearPublicAuth(): void {
    localStorage.removeItem(TOKEN_KEYS.PUBLIC);
    localStorage.removeItem(USER_KEYS.PUBLIC);
}

/**
 * Clear only admin user authentication
 */
export function clearAdminAuth(): void {
    localStorage.removeItem(TOKEN_KEYS.ADMIN);
}
