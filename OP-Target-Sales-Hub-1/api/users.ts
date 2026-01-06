
import { query, toCamel, toSnake } from './_db.js';
import { requireAuth, requireRole, canAccessUser } from './_auth.js';
import bcrypt from 'bcrypt';

/**
 * Users API with RBAC protection
 * - GET (list all): SUPER_ADMIN only
 * - GET (points): authenticated user can get own points, admin can get any
 * - POST (create/update): SUPER_ADMIN only
 */

export default async function handler(req: any, res: any) {
  const { method, query: queryParams } = req;

  try {
    switch (method) {
      case 'GET':
        // Points calculation - /api/users?points=true&userId=xxx
        if (queryParams.points) {
          const user = requireAuth(req, res);
          if (!user) return;

          const userId = queryParams.userId || user.id;

          // Non-admin can only get their own points
          if (user.role !== 'SUPER_ADMIN' && userId !== user.id) {
            return res.status(403).json({ error: 'Forbidden', message: 'لا يمكنك عرض نقاط مستخدم آخر' });
          }

          try {
            const acts = await query('SELECT type FROM activities WHERE user_id = $1', [userId]);
            const points = acts.rows.length * 5;
            return res.status(200).json({ points });
          } catch {
            return res.status(200).json({ points: 0 });
          }
        }

        // Teams CRUD - /api/users?teams=true
        if (queryParams.teams) {
          const user = requireAuth(req, res);
          if (!user) return;

          try {
            const teamsRes = await query(`
              SELECT 
                t.id, 
                t.name, 
                t.manager_user_id,
                u.name as manager_name,
                (SELECT COUNT(*) FROM users WHERE team_id = t.id) as member_count
              FROM teams t
              LEFT JOIN users u ON t.manager_user_id = u.id
              ORDER BY t.name
            `);
            return res.status(200).json(toCamel(teamsRes.rows));
          } catch {
            return res.status(200).json([]);
          }
        }

        // Create/Update Team - /api/users?teamAction=save
        if (queryParams.teamAction === 'save') {
          const admin = requireRole(req, res, ['SUPER_ADMIN']);
          if (!admin) return;
          // This is handled in POST
        }

        // Delete Team - /api/users?teamAction=delete&teamId=xxx
        if (queryParams.teamAction === 'delete') {
          const admin = requireRole(req, res, ['SUPER_ADMIN']);
          if (!admin) return;

          const teamId = queryParams.teamId;
          if (!teamId) {
            return res.status(400).json({ error: 'Bad Request', message: 'معرف الفريق مطلوب' });
          }

          // Check if team has members
          const membersCheck = await query('SELECT COUNT(*) as count FROM users WHERE team_id = $1', [teamId]);
          if (parseInt(membersCheck.rows[0].count) > 0) {
            return res.status(400).json({ error: 'Bad Request', message: 'لا يمكن حذف فريق يحتوي على أعضاء. قم بنقل الأعضاء أولاً.' });
          }

          await query('DELETE FROM teams WHERE id = $1', [teamId]);
          await query(
            'INSERT INTO audit_logs (id, actor_user_id, action, entity_type, entity_id, created_at) VALUES ($1, $2, $3, $4, $5, NOW())',
            [crypto.randomUUID(), admin.id, 'TEAM_DELETED', 'TEAM', teamId]
          );

          return res.status(200).json({ success: true });
        }

        // Audit logs - /api/users?audit=true (SUPER_ADMIN only)
        if (queryParams.audit) {
          const adminForAudit = requireRole(req, res, ['SUPER_ADMIN']);
          if (!adminForAudit) return;

          try {
            const auditRes = await query(
              'SELECT id, actor_user_id, action, entity_type, entity_id, created_at FROM audit_logs ORDER BY created_at DESC LIMIT 100'
            );
            return res.status(200).json(toCamel(auditRes.rows));
          } catch {
            return res.status(200).json([]);
          }
        }

        // List all users - SUPER_ADMIN only
        const adminUser = requireRole(req, res, ['SUPER_ADMIN']);
        if (!adminUser) return;

        // Don't return password_hash in response
        const usersRes = await query('SELECT id, name, email, role, team_id, avatar, is_active FROM users');
        return res.status(200).json(toCamel(usersRes.rows));

      case 'POST':
        // Create/Update user or team - SUPER_ADMIN only
        const admin = requireRole(req, res, ['SUPER_ADMIN']);
        if (!admin) return;

        // Handle team creation/update - /api/users?teamAction=save
        if (queryParams.teamAction === 'save') {
          const { team } = req.body;
          if (!team || !team.name) {
            return res.status(400).json({ error: 'Bad Request', message: 'اسم الفريق مطلوب' });
          }

          const teamId = team.id || crypto.randomUUID();
          const insertQuery = `
            INSERT INTO teams (id, name, manager_user_id) 
            VALUES ($1, $2, $3) 
            ON CONFLICT (id) DO UPDATE SET 
              name = EXCLUDED.name, 
              manager_user_id = EXCLUDED.manager_user_id
            RETURNING id, name, manager_user_id
          `;
          const result = await query(insertQuery, [teamId, team.name, team.managerUserId || null]);

          await query(
            'INSERT INTO audit_logs (id, actor_user_id, action, entity_type, entity_id, created_at) VALUES ($1, $2, $3, $4, $5, NOW())',
            [crypto.randomUUID(), admin.id, team.id ? 'TEAM_UPDATED' : 'TEAM_CREATED', 'TEAM', teamId]
          );

          return res.status(200).json(toCamel(result.rows[0]));
        }

        const { user: userData } = req.body;

        if (!userData || !userData.id) {
          return res.status(400).json({ error: 'Bad Request', message: 'User data with ID is required' });
        }

        // Handle password separately
        const password = userData.password;
        delete userData.password;

        // Check if user exists
        const existingUser = await query('SELECT id FROM users WHERE id = $1', [userData.id]);
        const isNewUser = existingUser.rows.length === 0;

        // Update password if provided (for both new and existing users)
        let passwordHash = null;
        if (password && password.length >= 6) {
          passwordHash = await bcrypt.hash(password, 10);
        } else if (isNewUser) {
          // New user must have password
          return res.status(400).json({ error: 'Bad Request', message: 'كلمة المرور مطلوبة للمستخدم الجديد' });
        }

        const snakeUser = toSnake(userData);

        // Never allow setting password or password_hash directly via this endpoint
        delete snakeUser.password;
        delete snakeUser.password_hash;

        if (isNewUser) {
          // INSERT new user with password
          const cols = [...Object.keys(snakeUser), 'password_hash'].join(', ');
          const vals = [...Object.values(snakeUser), passwordHash];
          const placeholders = vals.map((_, i) => `$${i + 1}`).join(', ');
          
          const q = `INSERT INTO users (${cols}) VALUES (${placeholders}) RETURNING id, name, email, role, team_id, avatar, is_active`;
          const resUser = await query(q, vals);
          
          // Audit log
          await query(
            'INSERT INTO audit_logs (id, actor_user_id, action, entity_type, entity_id, created_at) VALUES ($1, $2, $3, $4, $5, NOW())',
            [crypto.randomUUID(), admin.id, 'USER_CREATED', 'USER', userData.id]
          );
          
          return res.status(201).json(toCamel(resUser.rows[0]));
        } else {
          // UPDATE existing user
          let updateQuery = 'UPDATE users SET name = $1, role = $2, is_active = $3, team_id = $4, avatar = $5';
          let updateVals: any[] = [
            snakeUser.name,
            snakeUser.role,
            snakeUser.is_active,
            snakeUser.team_id || null,
            snakeUser.avatar
          ];
          
          if (passwordHash) {
            updateQuery += ', password_hash = $6 WHERE id = $7';
            updateVals.push(passwordHash, userData.id);
          } else {
            updateQuery += ' WHERE id = $6';
            updateVals.push(userData.id);
          }
          
          updateQuery += ' RETURNING id, name, email, role, team_id, avatar, is_active';
          const resUser = await query(updateQuery, updateVals);

          // Audit log
          await query(
            'INSERT INTO audit_logs (id, actor_user_id, action, entity_type, entity_id, created_at) VALUES ($1, $2, $3, $4, $5, NOW())',
            [crypto.randomUUID(), admin.id, 'USER_MODIFIED', 'USER', userData.id]
          );

          return res.status(200).json(toCamel(resUser.rows[0]));
        }

      case 'DELETE':
        // Delete user - SUPER_ADMIN only
        const delAdmin = requireRole(req, res, ['SUPER_ADMIN']);
        if (!delAdmin) return;

        const { id } = queryParams;
        if (!id) {
          return res.status(400).json({ error: 'Bad Request', message: 'User ID is required' });
        }

        // Cannot delete yourself
        if (id === delAdmin.id) {
          return res.status(400).json({ error: 'Bad Request', message: 'لا يمكنك حذف حسابك الخاص' });
        }

        await query('DELETE FROM users WHERE id = $1', [id]);

        // Audit log
        await query(
          'INSERT INTO audit_logs (id, actor_user_id, action, entity_type, entity_id, created_at) VALUES ($1, $2, $3, $4, $5, NOW())',
          [crypto.randomUUID(), delAdmin.id, 'USER_DELETED', 'USER', id]
        );

        return res.status(200).json({ success: true });

      default:
        res.setHeader('Allow', ['GET', 'POST', 'DELETE']);
        res.status(405).end();
    }
  } catch (error: any) {
    console.error('API Users Error:', error);
    res.status(500).json({ 
      error: 'Internal Server Error',
      message: error.message || 'Unknown error',
      stack: process.env.NODE_ENV === 'development' ? error.stack : undefined
    });
  }
}

