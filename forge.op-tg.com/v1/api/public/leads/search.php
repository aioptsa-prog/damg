<?php
/**
 * Public API - Search Leads
 * GET /v1/api/public/leads/search.php
 * 
 * Returns leads with data filtered based on user's subscription tier
 */

require_once __DIR__ . '/../../bootstrap_api.php';
require_once __DIR__ . '/../../../../lib/public_auth.php';
require_once __DIR__ . '/../../../../lib/subscriptions.php';

// Only allow GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

try {
    // Require authentication
    $user = require_public_auth();
    $pdo = db();

    // Get subscription to determine data visibility
    $subscription = get_user_subscription($user['id']);
    $plan_slug = $subscription['slug'] ?? 'free';

    // Track search in usage (non-blocking - don't fail search if tracking fails)
    try {
        deduct_credit($user['id'], 'search');
    } catch (Throwable $e) {
        error_log('Usage tracking failed: ' . $e->getMessage());
    }

    // Pagination
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = min(50, max(10, (int) ($_GET['limit'] ?? 20))); // Max 50 for public
    $offset = ($page - 1) * $limit;

    // Filters
    $category_id = isset($_GET['category_id']) ? (int) $_GET['category_id'] : null;
    $city = trim($_GET['city'] ?? '');
    $search = trim($_GET['search'] ?? '');

    // Build WHERE clause
    $where = ['1=1'];
    $params = [];

    if ($category_id) {
        $where[] = 'l.category_id = ?';
        $params[] = $category_id;
    }

    if ($city) {
        $where[] = 'l.city LIKE ?';
        $params[] = '%' . $city . '%';
    }

    if ($search) {
        $where[] = '(l.name LIKE ? OR l.city LIKE ?)';
        $searchParam = '%' . $search . '%';
        $params[] = $searchParam;
        $params[] = $searchParam;
    }

    $whereClause = implode(' AND ', $where);

    // Count total
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM leads l WHERE $whereClause");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    // Fetch leads
    $sql = "
        SELECT 
            l.id,
            l.name,
            l.city,
            l.country,
            l.category_id,
            c.name as category_name,
            c.slug as category_slug,
            l.rating,
            l.phone,
            l.email,
            l.website,
            l.created_at
        FROM leads l
        LEFT JOIN categories c ON l.category_id = c.id
        WHERE $whereClause
        ORDER BY l.created_at DESC
        LIMIT ? OFFSET ?
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($params, [$limit, $offset]));
    $leads = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Filter data based on subscription tier
    $leads = array_map(function ($lead) use ($user, $plan_slug) {
        $filtered = [
            'id' => (int) $lead['id'],
            'name' => $lead['name'],
            'city' => $lead['city'],
            'country' => $lead['country'],
            'category' => $lead['category_id'] ? [
                'id' => (int) $lead['category_id'],
                'name' => $lead['category_name'],
                'slug' => $lead['category_slug']
            ] : null,
            'rating' => $lead['rating'] ? (float) $lead['rating'] : null,
        ];

        // Check if user has already revealed this contact
        $hasRevealedPhone = has_revealed_contact($user['id'], $lead['id'], 'phone');
        $hasRevealedEmail = has_revealed_contact($user['id'], $lead['id'], 'email');

        // Data visibility rules based on subscription
        switch ($plan_slug) {
            case 'free':
                // Free users: See name, city, category only
                // Phone/Email hidden, show "reveal" status
                $filtered['phone'] = $hasRevealedPhone ? $lead['phone'] : null;
                $filtered['phone_available'] = !$hasRevealedPhone;
                $filtered['email'] = $hasRevealedEmail ? $lead['email'] : null;
                $filtered['email_available'] = !$hasRevealedEmail;
                $filtered['website'] = null; // Hidden for free
                break;

            case 'basic':
                // Basic users: Can reveal phones (with quota)
                $filtered['phone'] = $hasRevealedPhone ? $lead['phone'] : null;
                $filtered['phone_available'] = !$hasRevealedPhone && !empty($lead['phone']);
                $filtered['email'] = null; // Email hidden for basic
                $filtered['email_available'] = false;
                $filtered['website'] = $lead['website'];
                break;

            case 'professional':
                // Professional: Unlimited phone, limited email
                $filtered['phone'] = $lead['phone'];
                $filtered['phone_available'] = false; // Already visible
                $filtered['email'] = $hasRevealedEmail ? $lead['email'] : null;
                $filtered['email_available'] = !$hasRevealedEmail && !empty($lead['email']);
                $filtered['website'] = $lead['website'];
                break;

            case 'enterprise':
                // Enterprise: Everything visible
                $filtered['phone'] = $lead['phone'];
                $filtered['email'] = $lead['email'];
                $filtered['website'] = $lead['website'];
                $filtered['phone_available'] = false;
                $filtered['email_available'] = false;
                break;
        }

        return $filtered;
    }, $leads);

    send_success([
        'leads' => $leads,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => (int) ceil($total / $limit),
            'has_next' => $page < ceil($total / $limit),
            'has_prev' => $page > 1
        ],
        'subscription' => [
            'plan' => $plan_slug,
            'name' => $subscription['name']
        ]
    ]);

} catch (Throwable $e) {
    error_log('Public Search API Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    error_log('Trace: ' . $e->getTraceAsString());
    send_error('حدث خطأ في الخادم: ' . $e->getMessage(), 'SERVER_ERROR', 500);
}
