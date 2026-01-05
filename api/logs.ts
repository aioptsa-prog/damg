
import { query, toCamel, toSnake } from './_db.js';
import { requireAuth, requireRole } from './_auth.js';

/**
 * Logs API with RBAC
 * - GET audit: SUPER_ADMIN only
 * - POST audit/usage: authenticated users
 */

export default async function handler(req: any, res: any) {
  const { method } = req;
  const path = req.url.split('?')[0];

  try {
    if (method === 'GET' && path.includes('/audit')) {
      // Audit logs - admin only
      const user = requireRole(req, res, ['SUPER_ADMIN']);
      if (!user) return;

      const logs = await query('SELECT * FROM audit_logs ORDER BY created_at DESC LIMIT 100');
      return res.status(200).json(toCamel(logs.rows));
    }

    if (method === 'POST') {
      // POST requires authentication
      const user = requireAuth(req, res);
      if (!user) return;

      const data = toSnake(req.body);
      const typeParam = req.query?.type;

      if (typeParam === 'usage' || path.includes('/usage')) {
        if (!data.id) data.id = crypto.randomUUID();
        const cols = Object.keys(data).join(', ');
        const vals = Object.values(data);
        const placeholders = vals.map((_, i) => `$${i + 1}`).join(', ');
        await query(`INSERT INTO usage_logs (${cols}) VALUES (${placeholders})`, vals);
      } else if (typeParam === 'audit' || path.includes('/audit')) {
        if (!data.id) data.id = crypto.randomUUID();
        data.actor_user_id = user.id; // Always use authenticated user
        const cols = Object.keys(data).join(', ');
        const vals = Object.values(data);
        const placeholders = vals.map((_, i) => `$${i + 1}`).join(', ');
        await query(`INSERT INTO audit_logs (${cols}, created_at) VALUES (${placeholders}, NOW())`, vals);
      }

      return res.status(200).json({ success: true });
    }

    res.setHeader('Allow', ['GET', 'POST']);
    res.status(405).end();
  } catch (error: any) {
    console.error('API Logs Error:', error);
    res.status(500).json({ message: error.message });
  }
}

