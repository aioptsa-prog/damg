/**
 * Safe Data Utilities
 * Global helpers to prevent runtime crashes from malformed data
 */

/**
 * Ensures value is always an array - prevents .map() crashes
 * Handles: null, undefined, strings, objects, arrays
 */
export function asArray<T = any>(value: unknown): T[] {
  if (Array.isArray(value)) return value;
  if (value === null || value === undefined) return [];
  // If it's a string or object, wrap it (but usually we want empty)
  return [];
}

/**
 * Safely get a string value
 */
export function asString(value: unknown, fallback = ''): string {
  if (typeof value === 'string') return value;
  if (typeof value === 'number') return String(value);
  return fallback;
}

/**
 * Safely get a number value
 */
export function asNumber(value: unknown, fallback = 0): number {
  if (typeof value === 'number' && !isNaN(value)) return value;
  if (typeof value === 'string') {
    const parsed = parseFloat(value);
    if (!isNaN(parsed)) return parsed;
  }
  return fallback;
}

/**
 * Safely access nested object property
 */
export function safeGet<T>(obj: unknown, path: string, fallback: T): T {
  if (!obj || typeof obj !== 'object') return fallback;
  const keys = path.split('.');
  let current: any = obj;
  for (const key of keys) {
    if (current === null || current === undefined) return fallback;
    current = current[key];
  }
  return current ?? fallback;
}

/**
 * Ensures object has required array fields
 */
export function ensureArrayFields<T extends Record<string, any>>(
  obj: T,
  fields: (keyof T)[]
): T {
  const result = { ...obj };
  for (const field of fields) {
    if (!Array.isArray(result[field])) {
      (result as any)[field] = [];
    }
  }
  return result;
}
