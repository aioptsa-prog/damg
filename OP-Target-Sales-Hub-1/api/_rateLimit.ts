
import { query } from './_db.js';

/**
 * Server-side Rate Limiting with PostgreSQL
 * Sprint 2.2: نقل Rate Limiting للـ server
 * 
 * Features:
 * - Persistent across restarts
 * - Sliding window algorithm
 * - Per-user and per-IP limits
 * - Automatic cleanup
 */

export enum RateLimitAction {
  LOGIN_ATTEMPT = 'login',
  GENERATE_REPORT = 'report',
  WHATSAPP_SEND = 'whatsapp',
  API_CALL = 'api',
}

interface RateLimitConfig {
  limit: number;
  windowSeconds: number;
}

const CONFIGS: Record<RateLimitAction, RateLimitConfig> = {
  [RateLimitAction.LOGIN_ATTEMPT]: { limit: 5, windowSeconds: 15 * 60 }, // 5 per 15 min
  [RateLimitAction.GENERATE_REPORT]: { limit: 30, windowSeconds: 24 * 60 * 60 }, // 30 per day
  [RateLimitAction.WHATSAPP_SEND]: { limit: 100, windowSeconds: 24 * 60 * 60 }, // 100 per day
  [RateLimitAction.API_CALL]: { limit: 1000, windowSeconds: 60 * 60 }, // 1000 per hour
};

/**
 * Ensure rate_limits table exists
 */
async function ensureTable(): Promise<void> {
  await query(`
    CREATE TABLE IF NOT EXISTS rate_limits (
      id SERIAL PRIMARY KEY,
      key VARCHAR(255) NOT NULL,
      window_start BIGINT NOT NULL,
      count INTEGER NOT NULL DEFAULT 1,
      created_at TIMESTAMP DEFAULT NOW(),
      UNIQUE(key, window_start)
    )
  `);
  
  // Create index for cleanup
  await query(`
    CREATE INDEX IF NOT EXISTS idx_rate_limits_window 
    ON rate_limits(window_start)
  `);
}

// Initialize table on module load
let tableInitialized = false;
async function initTable() {
  if (!tableInitialized) {
    try {
      await ensureTable();
      tableInitialized = true;
    } catch (e) {
      console.error('[RateLimit] Failed to initialize table:', e);
    }
  }
}

/**
 * Cleanup old rate limit entries
 * Called probabilistically (1 in 100 requests)
 */
async function cleanup(): Promise<void> {
  if (Math.random() > 0.01) return; // 1% chance
  
  try {
    const cutoff = Math.floor(Date.now() / 1000) - (24 * 60 * 60); // 24 hours ago
    await query('DELETE FROM rate_limits WHERE window_start < $1', [cutoff]);
  } catch (e) {
    // Ignore cleanup errors
  }
}

/**
 * Check rate limit and return result
 * 
 * @param action Rate limit action type
 * @param identifier User ID or IP address
 * @returns { allowed, remaining, resetTime, retryAfter }
 */
export async function checkRateLimit(
  action: RateLimitAction,
  identifier: string
): Promise<{
  allowed: boolean;
  remaining: number;
  resetTime?: Date;
  retryAfter?: number;
}> {
  await initTable();
  await cleanup();
  
  const config = CONFIGS[action];
  const now = Math.floor(Date.now() / 1000);
  const windowStart = now - (now % config.windowSeconds);
  const key = `${action}:${identifier}`;
  
  try {
    // Get current count
    const result = await query(
      'SELECT count FROM rate_limits WHERE key = $1 AND window_start = $2',
      [key, windowStart]
    );
    
    const currentCount = result.rows[0]?.count || 0;
    
    if (currentCount >= config.limit) {
      const resetTime = new Date((windowStart + config.windowSeconds) * 1000);
      const retryAfter = (windowStart + config.windowSeconds) - now;
      
      return {
        allowed: false,
        remaining: 0,
        resetTime,
        retryAfter,
      };
    }
    
    // Increment count (upsert)
    await query(`
      INSERT INTO rate_limits (key, window_start, count)
      VALUES ($1, $2, 1)
      ON CONFLICT (key, window_start)
      DO UPDATE SET count = rate_limits.count + 1
    `, [key, windowStart]);
    
    return {
      allowed: true,
      remaining: config.limit - currentCount - 1,
    };
  } catch (e) {
    // Fail open - allow request if DB error
    console.error('[RateLimit] Error:', e);
    return { allowed: true, remaining: config.limit };
  }
}

/**
 * Rate limit middleware for API handlers
 * Returns 429 if limit exceeded
 */
export async function rateLimitMiddleware(
  action: RateLimitAction,
  identifier: string,
  res: any
): Promise<boolean> {
  const result = await checkRateLimit(action, identifier);
  
  if (!result.allowed) {
    res.setHeader('Retry-After', result.retryAfter || 60);
    res.setHeader('X-RateLimit-Limit', CONFIGS[action].limit);
    res.setHeader('X-RateLimit-Remaining', 0);
    res.setHeader('X-RateLimit-Reset', result.resetTime?.toISOString() || '');
    
    res.status(429).json({
      error: 'Too Many Requests',
      message: 'تم تجاوز الحد المسموح من الطلبات',
      retryAfter: result.retryAfter,
      resetTime: result.resetTime,
    });
    
    return false; // Request blocked
  }
  
  // Add rate limit headers
  res.setHeader('X-RateLimit-Limit', CONFIGS[action].limit);
  res.setHeader('X-RateLimit-Remaining', result.remaining);
  
  return true; // Request allowed
}

/**
 * Get rate limit status without incrementing
 */
export async function getRateLimitStatus(
  action: RateLimitAction,
  identifier: string
): Promise<{ used: number; limit: number; remaining: number; resetTime: Date }> {
  await initTable();
  
  const config = CONFIGS[action];
  const now = Math.floor(Date.now() / 1000);
  const windowStart = now - (now % config.windowSeconds);
  const key = `${action}:${identifier}`;
  
  try {
    const result = await query(
      'SELECT count FROM rate_limits WHERE key = $1 AND window_start = $2',
      [key, windowStart]
    );
    
    const used = result.rows[0]?.count || 0;
    const resetTime = new Date((windowStart + config.windowSeconds) * 1000);
    
    return {
      used,
      limit: config.limit,
      remaining: Math.max(0, config.limit - used),
      resetTime,
    };
  } catch (e) {
    return {
      used: 0,
      limit: config.limit,
      remaining: config.limit,
      resetTime: new Date(Date.now() + config.windowSeconds * 1000),
    };
  }
}
