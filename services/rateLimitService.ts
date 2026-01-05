
/**
 * Rate Limiting Service
 * Enforces usage quotas and security boundaries locally.
 */
export enum RateLimitAction {
  LOGIN_ATTEMPT = 'LOGIN_ATTEMPT',
  GENERATE_REPORT = 'GENERATE_REPORT',
  WHATSAPP_SEND = 'WHATSAPP_SEND'
}

interface RateLimitConfig {
  limit: number;
  windowMs: number; // Duration in milliseconds
}

const CONFIGS: Record<RateLimitAction, RateLimitConfig> = {
  [RateLimitAction.LOGIN_ATTEMPT]: { limit: 5, windowMs: 15 * 60 * 1000 }, // 5 tries per 15 mins
  [RateLimitAction.GENERATE_REPORT]: { limit: 30, windowMs: 24 * 60 * 60 * 1000 }, // 30 per day
  [RateLimitAction.WHATSAPP_SEND]: { limit: 100, windowMs: 24 * 60 * 60 * 1000 }, // 100 per day
};

class RateLimitService {
  check(action: RateLimitAction, identifier: string): { allowed: boolean; remaining: number; resetTime?: Date } {
    const key = `rate_limit_${action}_${identifier}`;
    const now = Date.now();
    const config = CONFIGS[action];
    
    let history: number[] = JSON.parse(localStorage.getItem(key) || '[]');
    
    // Filter out expired timestamps
    history = history.filter(ts => now - ts < config.windowMs);
    
    if (history.length >= config.limit) {
      const oldest = history[0];
      const resetTime = new Date(oldest + config.windowMs);
      return { allowed: false, remaining: 0, resetTime };
    }
    
    history.push(now);
    localStorage.setItem(key, JSON.stringify(history));
    
    return { allowed: true, remaining: config.limit - history.length };
  }
}

export const rateLimitService = new RateLimitService();
