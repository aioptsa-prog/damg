import { describe, it, expect, vi, beforeEach } from 'vitest';
import { createHmac } from 'crypto';

// Mock JWT creation for testing
function createTestToken(payload: object, secret: string, expiresIn = 3600): string {
  const header = { alg: 'HS256', typ: 'JWT' };
  const now = Math.floor(Date.now() / 1000);
  const fullPayload = { ...payload, iat: now, exp: now + expiresIn };
  
  const headerB64 = Buffer.from(JSON.stringify(header)).toString('base64url');
  const payloadB64 = Buffer.from(JSON.stringify(fullPayload)).toString('base64url');
  const signature = createHmac('sha256', secret)
    .update(`${headerB64}.${payloadB64}`)
    .digest('base64url');
  
  return `${headerB64}.${payloadB64}.${signature}`;
}

// Inline verifyToken for testing (same logic as _auth.ts)
function verifyToken(token: string, secret: string): { sub: string; role: string; exp: number } | null {
  try {
    const parts = token.split('.');
    if (parts.length !== 3) return null;

    const payload = JSON.parse(Buffer.from(parts[1], 'base64url').toString('utf8'));

    if (payload.exp && payload.exp < Math.floor(Date.now() / 1000)) {
      return null;
    }

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

describe('Auth Token Verification', () => {
  const TEST_SECRET = 'test-jwt-secret-for-unit-tests';

  it('should verify a valid token', () => {
    const token = createTestToken({ sub: 'user-123', role: 'SALES_REP' }, TEST_SECRET);
    const result = verifyToken(token, TEST_SECRET);
    
    expect(result).not.toBeNull();
    expect(result?.sub).toBe('user-123');
    expect(result?.role).toBe('SALES_REP');
  });

  it('should reject token with wrong secret', () => {
    const token = createTestToken({ sub: 'user-123', role: 'SALES_REP' }, TEST_SECRET);
    const result = verifyToken(token, 'wrong-secret');
    
    expect(result).toBeNull();
  });

  it('should reject expired token', () => {
    const token = createTestToken({ sub: 'user-123', role: 'SALES_REP' }, TEST_SECRET, -3600);
    const result = verifyToken(token, TEST_SECRET);
    
    expect(result).toBeNull();
  });

  it('should reject malformed token', () => {
    expect(verifyToken('not.a.valid.token', TEST_SECRET)).toBeNull();
    expect(verifyToken('invalid', TEST_SECRET)).toBeNull();
    expect(verifyToken('', TEST_SECRET)).toBeNull();
  });

  it('should reject token with tampered payload', () => {
    const token = createTestToken({ sub: 'user-123', role: 'SALES_REP' }, TEST_SECRET);
    const parts = token.split('.');
    
    // Tamper with payload
    const tamperedPayload = Buffer.from(JSON.stringify({ sub: 'hacker', role: 'SUPER_ADMIN' })).toString('base64url');
    const tamperedToken = `${parts[0]}.${tamperedPayload}.${parts[2]}`;
    
    const result = verifyToken(tamperedToken, TEST_SECRET);
    expect(result).toBeNull();
  });
});

describe('RBAC Role Checks', () => {
  const roles = ['SUPER_ADMIN', 'MANAGER', 'SALES_REP'] as const;
  
  function checkRole(userRole: string, allowedRoles: string[]): boolean {
    return allowedRoles.includes(userRole);
  }

  it('SUPER_ADMIN should have access to admin-only routes', () => {
    expect(checkRole('SUPER_ADMIN', ['SUPER_ADMIN'])).toBe(true);
    expect(checkRole('MANAGER', ['SUPER_ADMIN'])).toBe(false);
    expect(checkRole('SALES_REP', ['SUPER_ADMIN'])).toBe(false);
  });

  it('MANAGER should have access to manager routes', () => {
    expect(checkRole('SUPER_ADMIN', ['SUPER_ADMIN', 'MANAGER'])).toBe(true);
    expect(checkRole('MANAGER', ['SUPER_ADMIN', 'MANAGER'])).toBe(true);
    expect(checkRole('SALES_REP', ['SUPER_ADMIN', 'MANAGER'])).toBe(false);
  });

  it('All roles should have access to common routes', () => {
    const allRoles = ['SUPER_ADMIN', 'MANAGER', 'SALES_REP'];
    roles.forEach(role => {
      expect(checkRole(role, allRoles)).toBe(true);
    });
  });
});

describe('RBAC Lead Access', () => {
  interface AuthUser {
    id: string;
    role: 'SUPER_ADMIN' | 'MANAGER' | 'SALES_REP';
    teamId?: string;
  }

  interface Lead {
    id: string;
    ownerUserId: string;
    teamId: string;
  }

  function canAccessLead(user: AuthUser, lead: Lead, userTeamId?: string): boolean {
    if (user.role === 'SUPER_ADMIN') return true;
    if (user.role === 'SALES_REP') return lead.ownerUserId === user.id;
    if (user.role === 'MANAGER') return lead.teamId === userTeamId;
    return false;
  }

  const lead: Lead = { id: 'lead-1', ownerUserId: 'user-rep', teamId: 'team-a' };

  it('SUPER_ADMIN can access any lead', () => {
    const admin: AuthUser = { id: 'admin-1', role: 'SUPER_ADMIN' };
    expect(canAccessLead(admin, lead)).toBe(true);
  });

  it('SALES_REP can only access own leads', () => {
    const owner: AuthUser = { id: 'user-rep', role: 'SALES_REP' };
    const other: AuthUser = { id: 'user-other', role: 'SALES_REP' };
    
    expect(canAccessLead(owner, lead)).toBe(true);
    expect(canAccessLead(other, lead)).toBe(false);
  });

  it('MANAGER can access leads in their team', () => {
    const sameTeamManager: AuthUser = { id: 'manager-1', role: 'MANAGER' };
    const otherTeamManager: AuthUser = { id: 'manager-2', role: 'MANAGER' };
    
    expect(canAccessLead(sameTeamManager, lead, 'team-a')).toBe(true);
    expect(canAccessLead(otherTeamManager, lead, 'team-b')).toBe(false);
  });
});

describe('RBAC User Access', () => {
  interface AuthUser {
    id: string;
    role: 'SUPER_ADMIN' | 'MANAGER' | 'SALES_REP';
  }

  function canAccessUser(authUser: AuthUser, targetUserId: string, sameTeam: boolean): boolean {
    if (authUser.role === 'SUPER_ADMIN') return true;
    if (authUser.id === targetUserId) return true;
    if (authUser.role === 'MANAGER' && sameTeam) return true;
    return false;
  }

  it('SUPER_ADMIN can access any user', () => {
    const admin: AuthUser = { id: 'admin-1', role: 'SUPER_ADMIN' };
    expect(canAccessUser(admin, 'any-user', false)).toBe(true);
  });

  it('Users can access their own profile', () => {
    const user: AuthUser = { id: 'user-1', role: 'SALES_REP' };
    expect(canAccessUser(user, 'user-1', false)).toBe(true);
  });

  it('MANAGER can access team members', () => {
    const manager: AuthUser = { id: 'manager-1', role: 'MANAGER' };
    expect(canAccessUser(manager, 'team-member', true)).toBe(true);
    expect(canAccessUser(manager, 'other-team-member', false)).toBe(false);
  });

  it('SALES_REP cannot access other users', () => {
    const rep: AuthUser = { id: 'rep-1', role: 'SALES_REP' };
    expect(canAccessUser(rep, 'other-user', true)).toBe(false);
  });
});
