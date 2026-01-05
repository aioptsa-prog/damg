
import bcrypt from 'bcrypt';
import { createHmac } from 'crypto';
import { query, toCamel } from './_db.js';
import { loginSchema, validateInput } from './schemas.js';
import { checkRateLimit as checkDbRateLimit, RateLimitAction } from './_rateLimit.js';

/**
 * Auth API - Login with bcrypt password verification
 * - Validates email/password with Zod
 * - Rate limits login attempts
 * - Returns JWT in httpOnly cookie
 * - Supports mustChangePassword flow
 */

// Generate JWT token with proper HMAC-SHA256 signature
function generateToken(userId: string, role: string, mustChangePassword: boolean = false): string {
  const secret = process.env.JWT_SECRET;
  if (!secret) {
    throw new Error('JWT_SECRET environment variable is required');
  }

  const header = { alg: 'HS256', typ: 'JWT' };
  const now = Math.floor(Date.now() / 1000);
  const payload = {
    sub: userId,
    role: role,
    mcp: mustChangePassword, // must change password flag
    iat: now,
    exp: now + (24 * 60 * 60), // 24 hours
  };

  // P0 FIX: Use proper Base64URL encoding
  const base64Header = Buffer.from(JSON.stringify(header)).toString('base64url');
  const base64Payload = Buffer.from(JSON.stringify(payload)).toString('base64url');

  // P0 FIX: Use proper HMAC-SHA256 signature
  const signatureInput = `${base64Header}.${base64Payload}`;
  const signature = createHmac('sha256', secret)
    .update(signatureInput)
    .digest('base64url');

  return `${base64Header}.${base64Payload}.${signature}`;
}

// Rate limiting now uses PostgreSQL via _rateLimit.ts
// This provides persistence across restarts and horizontal scaling

// Handle GET /api/auth - Get current user (replaces /api/me)
async function handleGetMe(req: any, res: any) {
  const cookies = req.headers.cookie || '';
  const tokenMatch = cookies.match(/auth_token=([^;]+)/);
  
  if (!tokenMatch) {
    return res.status(401).json({ error: 'Not authenticated' });
  }

  try {
    const token = tokenMatch[1];
    const parts = token.split('.');
    if (parts.length !== 3) {
      return res.status(401).json({ error: 'Invalid token' });
    }

    const payload = JSON.parse(Buffer.from(parts[1], 'base64url').toString('utf8'));
    
    // Check expiration
    if (payload.exp && payload.exp < Math.floor(Date.now() / 1000)) {
      return res.status(401).json({ error: 'Token expired' });
    }

    // Get full user data from database
    const result = await query(
      'SELECT id, name, email, role, team_id, avatar, is_active, must_change_password FROM users WHERE id = $1',
      [payload.sub]
    );

    if (result.rows.length === 0) {
      return res.status(401).json({ error: 'User not found' });
    }

    return res.status(200).json({
      user: toCamel(result.rows[0])
    });
  } catch (error: any) {
    console.error('API /auth GET Error:', error);
    return res.status(500).json({ error: 'Internal server error' });
  }
}

// Handle DELETE /api/auth - Logout (replaces /api/logout)
async function handleLogout(req: any, res: any) {
  const cookies = req.headers.cookie || '';
  const tokenMatch = cookies.match(/auth_token=([^;]+)/);

  if (tokenMatch) {
    try {
      const token = tokenMatch[1];
      const parts = token.split('.');
      if (parts.length === 3) {
        const payload = JSON.parse(Buffer.from(parts[1], 'base64url').toString('utf8'));
        await query(
          'INSERT INTO audit_logs (id, actor_user_id, action, entity_type, entity_id, created_at) VALUES ($1, $2, $3, $4, $5, NOW())',
          [crypto.randomUUID(), payload.sub, 'LOGOUT', 'USER', payload.sub]
        );
      }
    } catch (e) {
      // Ignore decode errors
    }
  }

  res.setHeader('Set-Cookie', [
    'auth_token=; HttpOnly; SameSite=Strict; Path=/; Max-Age=0'
  ]);

  return res.status(200).json({ success: true });
}

export default async function handler(req: any, res: any) {
  // GET /api/auth - Get current user
  if (req.method === 'GET') {
    return handleGetMe(req, res);
  }

  // DELETE /api/auth - Logout
  if (req.method === 'DELETE') {
    return handleLogout(req, res);
  }

  // POST /api/auth - Login
  if (req.method !== 'POST') {
    return res.status(405).json({ error: 'Method not allowed' });
  }

  // P1 FIX: Validate input with Zod schema
  const validation = validateInput(loginSchema, req.body);
  if (!validation.success) {
    const errorResult = validation as { success: false; error: string; details: any[] };
    return res.status(400).json({ 
      error: 'Invalid input', 
      message: errorResult.error,
      details: errorResult.details 
    });
  }

  const { email, password } = validation.data;

  // Rate limit check (now using PostgreSQL for persistence)
  const rateCheck = await checkDbRateLimit(RateLimitAction.LOGIN_ATTEMPT, email);
  if (!rateCheck.allowed) {
    return res.status(429).json({
      error: 'AUTH_LOCKED',
      message: `تم تجاوز محاولات الدخول. يرجى المحاولة بعد ${rateCheck.resetTime?.toLocaleTimeString('ar-SA')}`,
      retryAfter: rateCheck.retryAfter,
    });
  }

  try {
    // Get user from database (including must_change_password)
    const result = await query(
      'SELECT id, name, email, password_hash, role, team_id, avatar, is_active, must_change_password FROM users WHERE email = $1 LIMIT 1',
      [email.toLowerCase().trim()]
    );

    // Generic error message - don't reveal if email exists
    const genericError = { error: 'AUTH_INVALID', message: 'البريد أو كلمة المرور غير صحيحة' };

    if (result.rows.length === 0) {
      await query(
        'INSERT INTO audit_logs (id, actor_user_id, action, entity_type, entity_id, created_at) VALUES ($1, $2, $3, $4, $5, NOW())',
        [crypto.randomUUID(), 'system', 'LOGIN_FAILED', 'USER', email]
      );
      return res.status(401).json(genericError);
    }

    const user = toCamel(result.rows[0]);

    // Check if user is active
    if (!user.isActive) {
      return res.status(403).json({ error: 'AUTH_LOCKED', message: 'هذا الحساب معطل حالياً' });
    }

    // Check if password hash exists
    if (!user.passwordHash) {
      console.error(`User ${user.id} has no password hash - needs password setup`);
      return res.status(401).json(genericError);
    }

    // Verify password with bcrypt
    const isValid = await bcrypt.compare(password, user.passwordHash);
    if (!isValid) {
      await query(
        'INSERT INTO audit_logs (id, actor_user_id, action, entity_type, entity_id, created_at) VALUES ($1, $2, $3, $4, $5, NOW())',
        [crypto.randomUUID(), 'system', 'LOGIN_FAILED', 'USER', email]
      );
      return res.status(401).json(genericError);
    }

    // Generate JWT (include mustChangePassword flag)
    const mustChangePassword = user.mustChangePassword || false;
    const token = generateToken(user.id, user.role, mustChangePassword);

    // Log successful login
    await query(
      'INSERT INTO audit_logs (id, actor_user_id, action, entity_type, entity_id, created_at) VALUES ($1, $2, $3, $4, $5, NOW())',
      [crypto.randomUUID(), user.id, 'LOGIN', 'USER', user.id]
    );

    // Set HttpOnly cookie
    const isProduction = process.env.NODE_ENV === 'production';
    res.setHeader('Set-Cookie', [
      `auth_token=${token}; HttpOnly; ${isProduction ? 'Secure;' : ''} SameSite=Strict; Path=/; Max-Age=${24 * 60 * 60}`
    ]);

    // Return user data (NEVER include password_hash)
    return res.status(200).json({
      user: {
        id: user.id,
        name: user.name,
        email: user.email,
        role: user.role,
        teamId: user.teamId,
        avatar: user.avatar,
        isActive: user.isActive,
        mustChangePassword: mustChangePassword
      }
    });

  } catch (error: any) {
    console.error('Login error:', error);
    return res.status(500).json({ error: 'Internal server error' });
  }
}

