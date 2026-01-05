<?php
/**
 * REST API v1 - Leads Endpoint: List Leads with Filters
 * 
 * GET /v1/api/leads/index.php?page=1&limit=20&category_id=5&search=clinic
 */

require_once __DIR__ . '/../bootstrap_api.php';
require_once __DIR__ . '/../../../lib/auth.php';

// Only allow GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

try {
    // Authentication required
    $user = require_api_auth();
    $pdo = db();

    // Pagination parameters
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = min(100, max(10, (int) ($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    // Filter parameters
    $category_id = isset($_GET['category_id']) ? (int) $_GET['category_id'] : null;
    $city_id = isset($_GET['city_id']) ? (int) $_GET['city_id'] : null;
    $search = trim($_GET['search'] ?? '');
    $status = trim($_GET['status'] ?? ''); // For agents: assignment status

    // Build WHERE clause
    $where = ['1=1'];
    $params = [];

    if ($category_id) {
        $where[] = 'l.category_id = ?';
        $params[] = $category_id;
    }

    if ($city_id) {
        $where[] = 'l.city = ?';
        $params[] = $city_id;
    }

    if ($search) {
        $where[] = '(l.name LIKE ? OR l.phone LIKE ? OR l.phone_norm LIKE ?)';
        $searchParam = '%' . $search . '%';
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }

    // Role-based filtering
    if ($user['role'] === 'agent') {
        // Agents only see their assigned leads
        $where[] = 'l.id IN (SELECT lead_id FROM assignments WHERE agent_id = ?)';
        $params[] = (int) $user['id'];

        // Filter by assignment status if provided
        if ($status) {
            $where[] = 'l.id IN (SELECT lead_id FROM assignments WHERE agent_id = ? AND status = ?)';
            $params[] = (int) $user['id'];
            $params[] = $status;
        }
    }

    $whereClause = implode(' AND ', $where);

    // Count total matching records
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM leads l WHERE $whereClause");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    // Fetch leads with joins (removed geo joins since those tables don't exist)
    $sql = "
        SELECT 
            l.id,
            l.phone,
            l.phone_norm,
            l.name,
            l.city,
            l.country,
            l.category_id,
            c.name as category_name,
            c.slug as category_slug,
            l.rating,
            l.website,
            l.email,
            l.lat,
            l.lon,
            l.source,
            l.created_at,
            l.created_by_user_id
        FROM leads l
        LEFT JOIN categories c ON l.category_id = c.id
        WHERE $whereClause
        ORDER BY l.created_at DESC
        LIMIT ? OFFSET ?
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($params, [$limit, $offset]));
    $leads = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Transform data (clean up nulls, format)
    $leads = array_map(function ($lead) {
        return [
            'id' => (int) $lead['id'],
            'phone' => $lead['phone'],
            'phone_norm' => $lead['phone_norm'],
            'name' => $lead['name'] ?: 'غير محدد',
            'city' => $lead['city'],
            'country' => $lead['country'],
            'category' => $lead['category_id'] ? [
                'id' => (int) $lead['category_id'],
                'name' => $lead['category_name'],
                'slug' => $lead['category_slug']
            ] : null,
            'location' => [
                'city_id' => null,
                'city_name' => $lead['city'],
                'district_id' => null,
                'district_name' => null,
                'lat' => $lead['lat'] ? (float) $lead['lat'] : null,
                'lng' => $lead['lon'] ? (float) $lead['lon'] : null
            ],
            'rating' => $lead['rating'] ? (float) $lead['rating'] : null,
            'website' => $lead['website'],
            'email' => $lead['email'],
            'source' => $lead['source'],
            'created_at' => $lead['created_at'],
            'created_by_user_id' => $lead['created_by_user_id'] ? (int) $lead['created_by_user_id'] : null
        ];
    }, $leads);

    // Get total unique cities for stats
    $citiesStmt = $pdo->query("SELECT COUNT(DISTINCT city) FROM leads WHERE city IS NOT NULL AND city != ''");
    $totalCities = (int) $citiesStmt->fetchColumn();

    // Success response
    send_success([
        'data' => $leads,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => (int) ceil($total / $limit),
            'has_next' => $page < ceil($total / $limit),
            'has_prev' => $page > 1
        ],
        'stats' => [
            'totalCities' => $totalCities
        ],
        'filters_applied' => [
            'category_id' => $category_id,
            'city_id' => $city_id,
            'search' => $search ?: null,
            'status' => $status ?: null
        ]
    ]);

} catch (Throwable $e) {
    error_log('Leads API Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    send_error('حدث خطأ في الخادم', 'SERVER_ERROR', 500);
}
