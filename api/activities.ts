
import { query, toCamel, toSnake } from './_db.js';
import { requireAuth, canAccessLead } from './_auth.js';

/**
 * Activities API with RBAC
 * Access based on lead ownership
 */

export default async function handler(req: any, res: any) {
  const user = requireAuth(req, res);
  if (!user) return;

  const { method, query: queryParams } = req;

  try {
    switch (method) {
      case 'GET':
        const { leadId } = queryParams;

        if (leadId) {
          // Check access to the lead
          const hasAccess = await canAccessLead(user, leadId);
          if (!hasAccess) {
            return res.status(403).json({ error: 'Forbidden' });
          }
        }

        const activitiesRes = await query(
          'SELECT * FROM activities WHERE lead_id = $1 ORDER BY created_at DESC',
          [leadId]
        );
        return res.status(200).json(toCamel(activitiesRes.rows));

      case 'POST':
        const act = toSnake(req.body);

        // Check access to the lead if specified
        if (act.lead_id) {
          const canPost = await canAccessLead(user, act.lead_id);
          if (!canPost) {
            return res.status(403).json({ error: 'Forbidden' });
          }
        }

        if (!act.id) act.id = crypto.randomUUID();
        act.user_id = user.id; // Always use authenticated user

        const cols = Object.keys(act).join(', ');
        const vals = Object.values(act);
        const placeholders = vals.map((_, i) => `$${i + 1}`).join(', ');

        await query(`INSERT INTO activities (${cols}, created_at) VALUES (${placeholders}, NOW())`, vals);
        return res.status(201).json({ success: true });

      default:
        res.setHeader('Allow', ['GET', 'POST']);
        res.status(405).end();
    }
  } catch (error: any) {
    console.error('API Activities Error:', error);
    res.status(500).json({ message: error.message });
  }
}

