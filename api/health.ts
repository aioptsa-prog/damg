/**
 * Health Check Endpoint
 * GET /api/health
 * 
 * Returns system health status for monitoring and integration verification
 */

import { query } from './_db.js';
import { FLAGS } from './_flags.js';

interface HealthResponse {
  status: 'ok' | 'degraded' | 'error';
  timestamp: string;
  version: string;
  checks: {
    database: boolean;
    flags: Record<string, boolean>;
  };
  uptime?: number;
}

const startTime = Date.now();
const VERSION = '1.0.0';

export default async function handler(req: any, res: any) {
  // Only allow GET
  if (req.method !== 'GET') {
    res.setHeader('Allow', ['GET']);
    return res.status(405).json({ error: 'Method not allowed' });
  }

  const health: HealthResponse = {
    status: 'ok',
    timestamp: new Date().toISOString(),
    version: VERSION,
    checks: {
      database: false,
      flags: FLAGS,
    },
    uptime: Math.floor((Date.now() - startTime) / 1000),
  };

  try {
    // Check database connectivity
    const dbResult = await query('SELECT 1 as ping');
    health.checks.database = dbResult.rows.length > 0;
  } catch (error) {
    health.checks.database = false;
    health.status = 'degraded';
  }

  // Determine overall status
  if (!health.checks.database) {
    health.status = 'error';
  }

  const statusCode = health.status === 'ok' ? 200 : 
                     health.status === 'degraded' ? 200 : 503;

  return res.status(statusCode).json(health);
}
