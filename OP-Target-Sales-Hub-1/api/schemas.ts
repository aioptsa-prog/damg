/**
 * API Input Validation Schemas
 * P1 FIX: Using Zod for runtime validation
 */

import { z } from 'zod';

// ============================================
// Auth Schemas
// ============================================

export const loginSchema = z.object({
  email: z.string()
    .email('البريد الإلكتروني غير صالح')
    .max(255, 'البريد الإلكتروني طويل جداً'),
  password: z.string()
    .min(1, 'كلمة المرور مطلوبة')
    .max(128, 'كلمة المرور طويلة جداً')
});

export const changePasswordSchema = z.object({
  currentPassword: z.string().min(1, 'كلمة المرور الحالية مطلوبة'),
  newPassword: z.string()
    .min(8, 'كلمة المرور يجب أن تكون 8 أحرف على الأقل')
    .max(128, 'كلمة المرور طويلة جداً')
    .regex(/[A-Z]/, 'كلمة المرور يجب أن تحتوي على حرف كبير')
    .regex(/[a-z]/, 'كلمة المرور يجب أن تحتوي على حرف صغير')
    .regex(/[0-9]/, 'كلمة المرور يجب أن تحتوي على رقم')
});

export const resetPasswordSchema = z.object({
  userId: z.string().uuid('معرف المستخدم غير صالح'),
  temporaryPassword: z.string().min(8).max(128).optional()
});

export const seedSchema = z.object({
  secret: z.string().min(1, 'Secret مطلوب')
});

// ============================================
// Lead Schemas
// ============================================

export const leadStatusEnum = z.enum([
  'NEW', 'CONTACTED', 'FOLLOW_UP', 'INTERESTED', 'WON', 'LOST'
]);

export const leadSchema = z.object({
  id: z.string().optional(),
  companyName: z.string()
    .min(1, 'اسم الشركة مطلوب')
    .max(255, 'اسم الشركة طويل جداً'),
  activity: z.string().max(1000).optional(),
  city: z.string().max(100).optional(),
  size: z.string().max(50).optional(),
  website: z.string().url().max(500).optional().or(z.literal('')),
  notes: z.string().max(5000).optional(),
  status: leadStatusEnum.optional().default('NEW'),
  ownerUserId: z.string().optional(),
  teamId: z.string().optional(),
  phone: z.string().max(50).optional(),
  decisionMakerName: z.string().max(255).optional(),
  decisionMakerRole: z.string().max(255).optional(),
  contactEmail: z.string().email().max(255).optional().or(z.literal('')),
  budgetRange: z.string().max(50).optional(),
  goalPrimary: z.string().max(500).optional(),
  timeline: z.string().max(100).optional(),
  sector: z.any().optional(),
  customFields: z.array(z.any()).optional(),
  attachments: z.array(z.any()).optional(),
  enrichment_signals: z.any().optional()
});

// ============================================
// User Schemas
// ============================================

export const userRoleEnum = z.enum(['SUPER_ADMIN', 'MANAGER', 'SALES_REP']);

export const userSchema = z.object({
  id: z.string(),
  name: z.string()
    .min(1, 'الاسم مطلوب')
    .max(255, 'الاسم طويل جداً'),
  email: z.string()
    .email('البريد الإلكتروني غير صالح')
    .max(255),
  role: userRoleEnum,
  teamId: z.string().optional(),
  avatar: z.string().url().max(500).optional(),
  isActive: z.boolean().optional().default(true)
});

// ============================================
// Report Schemas
// ============================================

export const reportSchema = z.object({
  id: z.string(),
  leadId: z.string(),
  versionNumber: z.number().int().positive(),
  provider: z.enum(['gemini', 'openai']),
  model: z.string().max(100),
  promptVersion: z.string().max(50).optional(),
  output: z.any(),
  change_log: z.string().max(5000).optional(),
  usage: z.object({
    inputTokens: z.number().int().nonnegative(),
    outputTokens: z.number().int().nonnegative(),
    cost: z.number().nonnegative(),
    latencyMs: z.number().int().nonnegative()
  }).optional()
});

// ============================================
// Task Schemas
// ============================================

export const taskStatusEnum = z.enum(['OPEN', 'DONE', 'SKIPPED']);
export const taskChannelEnum = z.enum(['call', 'whatsapp', 'email']);

export const taskSchema = z.object({
  id: z.string(),
  leadId: z.string(),
  assignedToUserId: z.string(),
  dayNumber: z.number().int().nonnegative(),
  channel: taskChannelEnum,
  goal: z.string().max(500),
  action: z.string().max(1000),
  status: taskStatusEnum.optional().default('OPEN'),
  dueDate: z.string().optional()
});

export const updateTaskStatusSchema = z.object({
  taskId: z.string(),
  status: taskStatusEnum,
  userId: z.string().optional()
});

// ============================================
// Activity Schemas
// ============================================

export const activityTypeEnum = z.enum([
  'status_change', 'note', 'call_result', 'whatsapp_sent', 
  'task_done', 'export_pdf', 'export_sheet', 'report_generated'
]);

export const activitySchema = z.object({
  id: z.string().optional(),
  leadId: z.string(),
  userId: z.string().optional(),
  type: activityTypeEnum,
  payload: z.any()
});

// ============================================
// Settings Schemas
// ============================================

export const aiSettingsSchema = z.object({
  activeProvider: z.enum(['gemini', 'openai']),
  geminiApiKey: z.string().max(500).optional(),
  geminiModel: z.string().max(100).optional(),
  openaiApiKey: z.string().max(500).optional(),
  openaiModel: z.string().max(100).optional(),
  temperature: z.number().min(0).max(2).optional(),
  maxTokens: z.number().int().positive().max(100000).optional(),
  systemInstruction: z.string().max(10000).optional()
});

export const scoringSettingsSchema = z.object({
  report_generated: z.number().int().nonnegative(),
  call_result: z.number().int().nonnegative(),
  whatsapp_sent: z.number().int().nonnegative(),
  status_interested: z.number().int().nonnegative(),
  status_won: z.number().int().nonnegative()
});

// ============================================
// Helper types and function for validation
// ============================================

export type ValidationSuccess<T> = { success: true; data: T };
export type ValidationError = { success: false; error: string; details: z.ZodIssue[] };
export type ValidationResult<T> = ValidationSuccess<T> | ValidationError;

export function validateInput<T>(schema: z.ZodSchema<T>, data: unknown): ValidationResult<T> {
  const result = schema.safeParse(data);
  
  if (result.success) {
    return { success: true, data: result.data };
  }
  
  // Get first error message in Arabic if available
  const firstError = result.error.issues[0];
  const errorMessage = firstError?.message || 'بيانات غير صالحة';
  
  return { 
    success: false, 
    error: errorMessage,
    details: result.error.issues 
  };
}

// Type guard for validation errors
export function isValidationError<T>(result: ValidationResult<T>): result is ValidationError {
  return !result.success;
}

// ============================================
// Unified Error Response
// ============================================

export interface APIError {
  errorCode: string;
  message: string;
  details?: unknown;
}

export function createErrorResponse(
  res: any, 
  status: number, 
  errorCode: string, 
  message: string, 
  details?: unknown
): void {
  const response: APIError = { errorCode, message };
  if (details !== undefined) {
    response.details = details;
  }
  res.status(status).json(response);
}

// Common error codes
export const ErrorCodes = {
  // Auth errors
  AUTH_INVALID: 'AUTH_INVALID',
  AUTH_REQUIRED: 'AUTH_REQUIRED',
  AUTH_FORBIDDEN: 'AUTH_FORBIDDEN',
  AUTH_LOCKED: 'AUTH_LOCKED',
  
  // Validation errors
  VALIDATION_ERROR: 'VALIDATION_ERROR',
  
  // Resource errors
  NOT_FOUND: 'NOT_FOUND',
  ALREADY_EXISTS: 'ALREADY_EXISTS',
  
  // Server errors
  INTERNAL_ERROR: 'INTERNAL_ERROR',
  METHOD_NOT_ALLOWED: 'METHOD_NOT_ALLOWED',
} as const;
