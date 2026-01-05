
import { query, toCamel } from './_db.js';
import { createHmac } from 'crypto';

/**
 * Auth Middleware for API routes
 * Verifies JWT token from HttpOnly cookie and extracts user info
 */

export interface AuthUser {
    id: string;
    role: 'SUPER_ADMIN' | 'MANAGER' | 'SALES_REP';
    teamId?: string;
}

export interface AuthRequest extends Request {
    user?: AuthUser;
}

/**
 * Verify JWT token and return decoded payload
 * P0 FIX: Uses proper HMAC-SHA256 signature verification
 */
function verifyToken(token: string): { sub: string; role: string; mcp?: boolean; exp: number } | null {
    const secret = process.env.JWT_SECRET;
    if (!secret) {
        console.error('JWT_SECRET not configured');
        return null;
    }

    try {
        const parts = token.split('.');
        if (parts.length !== 3) return null;

        // P0 FIX: Use proper Base64URL decoding
        const payload = JSON.parse(Buffer.from(parts[1], 'base64url').toString('utf8'));

        // Check expiration
        if (payload.exp && payload.exp < Math.floor(Date.now() / 1000)) {
            return null;
        }

        // P0 FIX: Verify signature with proper HMAC-SHA256
        const signatureInput = `${parts[0]}.${parts[1]}`;
        const expectedSignature = createHmac('sha256', secret)
            .update(signatureInput)
            .digest('base64url');

        if (parts[2] !== expectedSignature) {
            return null;
        }

        return payload;
    } catch (e) {
        return null;
    }
}

/**
 * Extract and verify auth from request
 */
export function getAuthFromRequest(req: any): AuthUser | null {
    const cookies = req.headers.cookie || '';
    const tokenMatch = cookies.match(/auth_token=([^;]+)/);

    if (!tokenMatch) {
        return null;
    }

    const payload = verifyToken(tokenMatch[1]);
    if (!payload) {
        return null;
    }

    return {
        id: payload.sub,
        role: payload.role as AuthUser['role'],
    };
}

/**
 * Require authentication - returns 401 if not authenticated
 */
export function requireAuth(req: any, res: any): AuthUser | null {
    const user = getAuthFromRequest(req);

    if (!user) {
        res.status(401).json({ error: 'Unauthorized', message: 'يجب تسجيل الدخول أولاً' });
        return null;
    }

    return user;
}

/**
 * Require specific roles - returns 403 if not authorized
 */
export function requireRole(req: any, res: any, allowedRoles: AuthUser['role'][]): AuthUser | null {
    const user = requireAuth(req, res);
    if (!user) return null;

    if (!allowedRoles.includes(user.role)) {
        res.status(403).json({ error: 'Forbidden', message: 'ليس لديك صلاحية لهذا الإجراء' });
        return null;
    }

    return user;
}

/**
 * Check if user can access a specific lead
 * SUPER_ADMIN: all leads
 * MANAGER: leads in their team
 * SALES_REP: only their own leads
 */
export async function canAccessLead(user: AuthUser, leadId: string): Promise<boolean> {
    if (user.role === 'SUPER_ADMIN') {
        return true;
    }

    try {
        const result = await query(
            'SELECT owner_user_id, team_id FROM leads WHERE id = $1',
            [leadId]
        );

        if (result.rows.length === 0) {
            return false;
        }

        const lead = result.rows[0];

        if (user.role === 'SALES_REP') {
            return lead.owner_user_id === user.id;
        }

        if (user.role === 'MANAGER') {
            // Get manager's team
            const teamResult = await query(
                'SELECT team_id FROM users WHERE id = $1',
                [user.id]
            );
            if (teamResult.rows.length === 0) return false;
            return lead.team_id === teamResult.rows[0].team_id;
        }

        return false;
    } catch (e) {
        console.error('Error checking lead access:', e);
        return false;
    }
}

/**
 * Check if user can access a specific user record
 */
export async function canAccessUser(authUser: AuthUser, targetUserId: string): Promise<boolean> {
    if (authUser.role === 'SUPER_ADMIN') {
        return true;
    }

    if (authUser.id === targetUserId) {
        return true; // Can always access own profile
    }

    if (authUser.role === 'MANAGER') {
        // Check if target user is in same team
        const result = await query(
            'SELECT team_id FROM users WHERE id IN ($1, $2)',
            [authUser.id, targetUserId]
        );
        if (result.rows.length !== 2) return false;
        return result.rows[0].team_id === result.rows[1].team_id;
    }

    return false;
}
