import { describe, it, expect } from 'vitest';
import { z } from 'zod';
import {
  loginSchema,
  changePasswordSchema,
  leadSchema,
  userRoleEnum,
  validateInput,
  isValidationError,
  ErrorCodes,
} from '../../api/schemas';

describe('Login Schema Validation', () => {
  it('should accept valid login credentials', () => {
    const result = validateInput(loginSchema, {
      email: 'user@example.com',
      password: 'password123'
    });
    
    expect(result.success).toBe(true);
    if (result.success) {
      expect(result.data.email).toBe('user@example.com');
    }
  });

  it('should reject invalid email format', () => {
    const result = validateInput(loginSchema, {
      email: 'not-an-email',
      password: 'password123'
    });
    
    expect(result.success).toBe(false);
    expect(isValidationError(result)).toBe(true);
  });

  it('should reject empty password', () => {
    const result = validateInput(loginSchema, {
      email: 'user@example.com',
      password: ''
    });
    
    expect(result.success).toBe(false);
  });

  it('should reject missing fields', () => {
    const result = validateInput(loginSchema, {});
    expect(result.success).toBe(false);
  });
});

describe('Change Password Schema Validation', () => {
  it('should accept valid password change', () => {
    const result = validateInput(changePasswordSchema, {
      currentPassword: 'oldPassword123',
      newPassword: 'NewPassword1'
    });
    
    expect(result.success).toBe(true);
  });

  it('should reject password without uppercase', () => {
    const result = validateInput(changePasswordSchema, {
      currentPassword: 'oldPassword123',
      newPassword: 'newpassword1'
    });
    
    expect(result.success).toBe(false);
  });

  it('should reject password without lowercase', () => {
    const result = validateInput(changePasswordSchema, {
      currentPassword: 'oldPassword123',
      newPassword: 'NEWPASSWORD1'
    });
    
    expect(result.success).toBe(false);
  });

  it('should reject password without number', () => {
    const result = validateInput(changePasswordSchema, {
      currentPassword: 'oldPassword123',
      newPassword: 'NewPassword'
    });
    
    expect(result.success).toBe(false);
  });

  it('should reject password shorter than 8 characters', () => {
    const result = validateInput(changePasswordSchema, {
      currentPassword: 'oldPassword123',
      newPassword: 'New1'
    });
    
    expect(result.success).toBe(false);
  });
});

describe('Lead Schema Validation', () => {
  it('should accept valid lead data', () => {
    const result = validateInput(leadSchema, {
      companyName: 'شركة الاختبار',
      status: 'NEW'
    });
    
    expect(result.success).toBe(true);
  });

  it('should reject empty company name', () => {
    const result = validateInput(leadSchema, {
      companyName: '',
      status: 'NEW'
    });
    
    expect(result.success).toBe(false);
  });

  it('should reject invalid status', () => {
    const result = validateInput(leadSchema, {
      companyName: 'شركة الاختبار',
      status: 'INVALID_STATUS'
    });
    
    expect(result.success).toBe(false);
  });

  it('should accept all valid statuses', () => {
    const statuses = ['NEW', 'CONTACTED', 'FOLLOW_UP', 'INTERESTED', 'WON', 'LOST'];
    
    statuses.forEach(status => {
      const result = validateInput(leadSchema, {
        companyName: 'شركة الاختبار',
        status
      });
      expect(result.success).toBe(true);
    });
  });
});

describe('User Role Enum', () => {
  it('should accept valid roles', () => {
    expect(userRoleEnum.safeParse('SUPER_ADMIN').success).toBe(true);
    expect(userRoleEnum.safeParse('MANAGER').success).toBe(true);
    expect(userRoleEnum.safeParse('SALES_REP').success).toBe(true);
  });

  it('should reject invalid roles', () => {
    expect(userRoleEnum.safeParse('ADMIN').success).toBe(false);
    expect(userRoleEnum.safeParse('USER').success).toBe(false);
    expect(userRoleEnum.safeParse('').success).toBe(false);
  });
});

describe('Validation Helper Functions', () => {
  it('isValidationError should correctly identify errors', () => {
    const success = validateInput(loginSchema, { email: 'a@b.com', password: '123' });
    const failure = validateInput(loginSchema, { email: 'invalid' });
    
    expect(isValidationError(success)).toBe(false);
    expect(isValidationError(failure)).toBe(true);
  });
});

describe('Error Codes', () => {
  it('should have all required error codes', () => {
    expect(ErrorCodes.AUTH_INVALID).toBe('AUTH_INVALID');
    expect(ErrorCodes.AUTH_REQUIRED).toBe('AUTH_REQUIRED');
    expect(ErrorCodes.AUTH_FORBIDDEN).toBe('AUTH_FORBIDDEN');
    expect(ErrorCodes.AUTH_LOCKED).toBe('AUTH_LOCKED');
    expect(ErrorCodes.VALIDATION_ERROR).toBe('VALIDATION_ERROR');
    expect(ErrorCodes.NOT_FOUND).toBe('NOT_FOUND');
    expect(ErrorCodes.INTERNAL_ERROR).toBe('INTERNAL_ERROR');
  });
});
