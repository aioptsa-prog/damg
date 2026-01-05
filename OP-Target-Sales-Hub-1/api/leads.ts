
import { query, toCamel, toSnake } from './_db.js';
import { requireAuth, canAccessLead, AuthUser } from './_auth.js';

/**
 * Leads API with RBAC protection
 * - SUPER_ADMIN: all leads
 * - MANAGER: team leads only
 * - SALES_REP: own leads only
 */

async function getLeadsForUser(user: AuthUser): Promise<any[]> {
  let leadsRes;

  if (user.role === 'SUPER_ADMIN') {
    // Admin sees all
    leadsRes = await query('SELECT * FROM leads ORDER BY created_at DESC');
  } else if (user.role === 'MANAGER') {
    // Manager sees team leads
    const teamRes = await query('SELECT team_id FROM users WHERE id = $1', [user.id]);
    const teamId = teamRes.rows[0]?.team_id;
    leadsRes = await query(
      'SELECT * FROM leads WHERE team_id = $1 ORDER BY created_at DESC',
      [teamId]
    );
  } else {
    // Sales rep sees only their own leads
    leadsRes = await query(
      'SELECT * FROM leads WHERE owner_user_id = $1 ORDER BY created_at DESC',
      [user.id]
    );
  }

  return toCamel(leadsRes.rows);
}

export default async function handler(req: any, res: any) {
  // Require authentication for all lead operations
  const user = requireAuth(req, res);
  if (!user) return; // Response already sent by requireAuth

  const { method, query: queryParams } = req;

  try {
    switch (method) {
      case 'GET':
        // Get leads based on user's role (RBAC enforced)
        const leads = await getLeadsForUser(user);
        return res.status(200).json(leads);

      case 'POST':
        const leadData = toSnake(req.body);

        // For updates, verify user has access to this lead
        if (leadData.id) {
          const hasAccess = await canAccessLead(user, leadData.id);
          if (!hasAccess) {
            return res.status(403).json({ error: 'Forbidden', message: 'ليس لديك صلاحية لتعديل هذا العميل' });
          }
        } else {
          // New lead - set owner to current user if not specified
          if (!leadData.owner_user_id) {
            leadData.owner_user_id = user.id;
          }
          // Non-admin cannot create leads for other users
          if (user.role !== 'SUPER_ADMIN' && leadData.owner_user_id !== user.id) {
            return res.status(403).json({ error: 'Forbidden', message: 'لا يمكنك إنشاء عميل لمستخدم آخر' });
          }
        }

        // Filter to only known columns to prevent SQL errors
        const KNOWN_LEAD_COLUMNS = [
          'id', 'company_name', 'activity', 'city', 'size', 'website', 'notes', 'sector',
          'status', 'owner_user_id', 'team_id', 'created_at', 'last_activity_at', 'created_by',
          'phone', 'custom_fields', 'attachments', 'decision_maker_name', 'decision_maker_role',
          'contact_email', 'budget_range', 'goal_primary', 'timeline', 'transcript', 'enrichment_signals',
          'instagram', 'twitter', 'linkedin', 'facebook', 'maps', 'tiktok', 'snapchat', 'youtube', 'whatsapp'
        ];
        
        const filteredData: Record<string, any> = {};
        for (const key of Object.keys(leadData)) {
          if (KNOWN_LEAD_COLUMNS.includes(key)) {
            filteredData[key] = leadData[key];
          }
        }

        const columns = Object.keys(filteredData).join(', ');
        const values = Object.values(filteredData);
        const placeholders = values.map((_, i) => `$${i + 1}`).join(', ');

        const insertQuery = `
          INSERT INTO leads (${columns}) 
          VALUES (${placeholders}) 
          ON CONFLICT (id) DO UPDATE SET 
            company_name = EXCLUDED.company_name,
            activity = EXCLUDED.activity,
            status = EXCLUDED.status,
            last_activity_at = CURRENT_TIMESTAMP,
            enrichment_signals = EXCLUDED.enrichment_signals
          RETURNING *;
        `;

        const saveRes = await query(insertQuery, values);
        return res.status(200).json(toCamel(saveRes.rows[0]));

      case 'DELETE':
        const { id } = queryParams;

        if (!id) {
          return res.status(400).json({ error: 'Bad Request', message: 'Lead ID is required' });
        }

        // Verify user has access to delete this lead
        const canDelete = await canAccessLead(user, id);
        if (!canDelete) {
          return res.status(403).json({ error: 'Forbidden', message: 'ليس لديك صلاحية لحذف هذا العميل' });
        }

        await query('DELETE FROM leads WHERE id = $1', [id]);
        return res.status(200).json({ success: true });

      default:
        res.setHeader('Allow', ['GET', 'POST', 'DELETE']);
        res.status(405).end(`Method ${method} Not Allowed`);
    }
  } catch (error: any) {
    console.error('API Leads Error:', error);
    res.status(500).json({ message: error.message });
  }
}

