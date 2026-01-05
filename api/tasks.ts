
import { query, toCamel, toSnake } from './_db.js';
import { requireAuth, canAccessLead } from './_auth.js';
import { z } from 'zod';

// Zod schemas for validation
const TaskCreateSchema = z.object({
  id: z.string().min(1),
  leadId: z.string().min(1),
  assignedToUserId: z.string().min(1),
  dayNumber: z.number().int().min(1).max(30),
  channel: z.string().optional().default(''),
  goal: z.string().optional().default(''),
  action: z.string().min(1),
  status: z.enum(['OPEN', 'DONE']).default('OPEN'),
  dueDate: z.string().optional(),
});

const TaskStatusUpdateSchema = z.object({
  taskId: z.string().min(1),
  status: z.enum(['OPEN', 'DONE']),
});

/**
 * Tasks API with RBAC + Zod Validation
 * Access based on lead ownership or assignment
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
          // Validate leadId format
          if (typeof leadId !== 'string' || leadId.length < 1) {
            return res.status(400).json({ error: 'INVALID_LEAD_ID', message: 'leadId must be a non-empty string' });
          }

          // Check access to the lead
          const hasAccess = await canAccessLead(user, leadId);
          if (!hasAccess) {
            return res.status(403).json({ error: 'FORBIDDEN', message: 'لا تملك صلاحية الوصول لهذا العميل' });
          }

          const tasksRes = await query(
            'SELECT * FROM tasks WHERE lead_id = $1 ORDER BY day_number ASC',
            [leadId]
          );
          return res.status(200).json(toCamel(tasksRes.rows));
        }

        // No leadId - return tasks assigned to user or all for admin
        let tasksRes;
        if (user.role === 'SUPER_ADMIN') {
          tasksRes = await query('SELECT * FROM tasks ORDER BY day_number ASC');
        } else {
          tasksRes = await query(
            'SELECT * FROM tasks WHERE assigned_to_user_id = $1 ORDER BY day_number ASC',
            [user.id]
          );
        }
        return res.status(200).json(toCamel(tasksRes.rows));

      case 'POST':
        const rawTasks = req.body;
        
        // Validate array
        if (!Array.isArray(rawTasks)) {
          return res.status(400).json({ error: 'INVALID_BODY', message: 'Request body must be an array of tasks' });
        }

        if (rawTasks.length === 0) {
          return res.status(400).json({ error: 'EMPTY_ARRAY', message: 'Tasks array cannot be empty' });
        }

        // Validate each task with Zod
        const validatedTasks = [];
        for (let i = 0; i < rawTasks.length; i++) {
          const result = TaskCreateSchema.safeParse(rawTasks[i]);
          if (!result.success) {
            return res.status(400).json({ 
              error: 'VALIDATION_ERROR', 
              message: `Task at index ${i} is invalid`,
              details: result.error.flatten().fieldErrors
            });
          }
          validatedTasks.push(result.data);
        }

        // Process validated tasks
        for (const task of validatedTasks) {
          const sTask = toSnake(task);

          // Check access to the lead
          const canPost = await canAccessLead(user, sTask.lead_id);
          if (!canPost) {
            return res.status(403).json({ error: 'FORBIDDEN', message: 'لا تملك صلاحية إضافة مهام لهذا العميل' });
          }

          // Build safe INSERT query
          const allowedCols = ['id', 'lead_id', 'assigned_to_user_id', 'day_number', 'channel', 'goal', 'action', 'status', 'due_date'];
          const cols = Object.keys(sTask).filter(k => allowedCols.includes(k));
          const vals = cols.map(k => (sTask as any)[k]);
          const placeholders = vals.map((_, i) => `$${i + 1}`).join(', ');
          
          await query(
            `INSERT INTO tasks (${cols.join(', ')}) VALUES (${placeholders}) ON CONFLICT (id) DO UPDATE SET status = EXCLUDED.status, action = EXCLUDED.action`,
            vals
          );
        }
        return res.status(201).json({ success: true, count: validatedTasks.length });

      case 'PUT':
        if (req.url.endsWith('/status')) {
          // Validate with Zod
          const statusResult = TaskStatusUpdateSchema.safeParse(req.body);
          if (!statusResult.success) {
            return res.status(400).json({ 
              error: 'VALIDATION_ERROR', 
              message: 'Invalid status update request',
              details: statusResult.error.flatten().fieldErrors
            });
          }
          
          const { taskId, status } = statusResult.data;

          // Verify user has access to this task (assigned to them or admin)
          const taskCheck = await query('SELECT lead_id, assigned_to_user_id FROM tasks WHERE id = $1', [taskId]);
          if (taskCheck.rows.length === 0) {
            return res.status(404).json({ error: 'NOT_FOUND', message: 'Task not found' });
          }

          const task = taskCheck.rows[0];
          if (user.role !== 'SUPER_ADMIN' && task.assigned_to_user_id !== user.id) {
            const canUpdate = await canAccessLead(user, task.lead_id);
            if (!canUpdate) {
              return res.status(403).json({ error: 'Forbidden' });
            }
          }

          await query('UPDATE tasks SET status = $1 WHERE id = $2', [status, taskId]);

          // Log as activity
          await query(
            'INSERT INTO activities (id, lead_id, user_id, type, payload, created_at) VALUES ($1, $2, $3, $4, $5, NOW())',
            [crypto.randomUUID(), task.lead_id, user.id, 'task_done', JSON.stringify({ taskId })]
          );

          return res.status(200).json({ success: true });
        }
        return res.status(404).end();

      default:
        res.setHeader('Allow', ['GET', 'POST', 'PUT']);
        res.status(405).end();
    }
  } catch (error: any) {
    console.error('API Tasks Error:', error);
    res.status(500).json({ message: error.message });
  }
}

