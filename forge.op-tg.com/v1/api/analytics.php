<?php
/**
 * Analytics API
 * GET - جلب إحصائيات التحليلات للمستخدم الحالي
 * 
 * For Admin/Agent: Shows leads from their user_campaigns
 * For Superadmin: Shows all leads
 */

require_once __DIR__ . '/bootstrap_api.php';
require_once __DIR__ . '/../../lib/auth.php';

// Handle CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

try {
    $user = current_user();
    if (!$user) {
        send_error('يرجى تسجيل الدخول أولاً', 'UNAUTHORIZED', 401);
    }

    $pdo = db();
    $userId = $user['id'];
    $isSuperAdmin = !empty($user['is_superadmin']);

    // Time period filter
    $period = $_GET['period'] ?? '30days';
    $daysAgo = match ($period) {
        '7days' => 7,
        '30days' => 30,
        '90days' => 90,
        'year' => 365,
        default => 30
    };
    $dateFrom = date('Y-m-d H:i:s', strtotime("-{$daysAgo} days"));

    // Build query based on user type
    // For regular users: only show leads from THEIR campaigns
    // For superadmin: show all leads

    if ($isSuperAdmin) {
        // Superadmin sees everything
        $leadsQuery = "SELECT COUNT(*) as total_leads FROM leads";
        $cityQuery = "SELECT city, COUNT(*) as leads_count FROM leads WHERE city IS NOT NULL AND city != '' GROUP BY city ORDER BY leads_count DESC LIMIT 5";
        $categoryQuery = "SELECT c.name as category, COUNT(*) as leads_count FROM leads l LEFT JOIN categories c ON l.category_id = c.id GROUP BY l.category_id, c.name HAVING leads_count > 0 ORDER BY leads_count DESC LIMIT 5";
        $monthlyQuery = "SELECT COUNT(*) as count FROM leads WHERE created_at BETWEEN ? AND ?";
        $citiesCountQuery = "SELECT COUNT(DISTINCT city) as unique_cities FROM leads WHERE city IS NOT NULL AND city != ''";

        $stmt = $pdo->query($leadsQuery);
        $totalLeads = (int) ($stmt->fetch()['total_leads'] ?? 0);

        $stmt = $pdo->query($cityQuery);
        $cityData = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->query($categoryQuery);
        $categoryData = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->query($citiesCountQuery);
        $uniqueCities = (int) ($stmt->fetch()['unique_cities'] ?? 0);

    } else {
        // Regular user sees only leads from their campaigns
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total_leads
            FROM leads l
            INNER JOIN user_campaigns uc ON l.campaign_id = uc.id
            WHERE uc.user_id = ?
        ");
        $stmt->execute([$userId]);
        $totalLeads = (int) ($stmt->fetch()['total_leads'] ?? 0);

        $stmt = $pdo->prepare("
            SELECT l.city, COUNT(*) as leads_count
            FROM leads l
            INNER JOIN user_campaigns uc ON l.campaign_id = uc.id
            WHERE uc.user_id = ? AND l.city IS NOT NULL AND l.city != ''
            GROUP BY l.city
            ORDER BY leads_count DESC
            LIMIT 5
        ");
        $stmt->execute([$userId]);
        $cityData = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("
            SELECT c.name as category, COUNT(*) as leads_count
            FROM leads l
            INNER JOIN user_campaigns uc ON l.campaign_id = uc.id
            LEFT JOIN categories c ON l.category_id = c.id
            WHERE uc.user_id = ?
            GROUP BY l.category_id, c.name
            HAVING leads_count > 0
            ORDER BY leads_count DESC
            LIMIT 5
        ");
        $stmt->execute([$userId]);
        $categoryData = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT l.city) as unique_cities
            FROM leads l
            INNER JOIN user_campaigns uc ON l.campaign_id = uc.id
            WHERE uc.user_id = ? AND l.city IS NOT NULL AND l.city != ''
        ");
        $stmt->execute([$userId]);
        $uniqueCities = (int) ($stmt->fetch()['unique_cities'] ?? 0);
    }

    // Format city data with percentages
    $cityDataFormatted = [];
    foreach ($cityData as $city) {
        $percentage = $totalLeads > 0 ? round(($city['leads_count'] / $totalLeads) * 100, 1) : 0;
        $cityDataFormatted[] = [
            'city' => $city['city'] ?: 'غير محدد',
            'leads' => (int) $city['leads_count'],
            'percentage' => $percentage
        ];
    }

    // Format category data
    $categoryDataFormatted = [];
    foreach ($categoryData as $cat) {
        $categoryDataFormatted[] = [
            'category' => $cat['category'] ?: 'غير محدد',
            'leads' => (int) $cat['leads_count']
        ];
    }

    // User's Campaigns Stats (always user-specific)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total_campaigns,
               SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_campaigns,
               SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as active_campaigns
        FROM user_campaigns
        WHERE user_id = ?
    ");
    $stmt->execute([$userId]);
    $campaignStats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Monthly Trend (last 6 months)
    $monthlyTrend = [];
    $arabicMonths = [
        'January' => 'يناير',
        'February' => 'فبراير',
        'March' => 'مارس',
        'April' => 'أبريل',
        'May' => 'مايو',
        'June' => 'يونيو',
        'July' => 'يوليو',
        'August' => 'أغسطس',
        'September' => 'سبتمبر',
        'October' => 'أكتوبر',
        'November' => 'نوفمبر',
        'December' => 'ديسمبر'
    ];

    for ($i = 5; $i >= 0; $i--) {
        $monthStart = date('Y-m-01', strtotime("-{$i} months"));
        $monthEnd = date('Y-m-t', strtotime("-{$i} months"));
        $monthName = date('F', strtotime("-{$i} months"));

        if ($isSuperAdmin) {
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM leads WHERE created_at BETWEEN ? AND ?");
            $stmt->execute([$monthStart, $monthEnd . ' 23:59:59']);
        } else {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count
                FROM leads l
                INNER JOIN user_campaigns uc ON l.campaign_id = uc.id
                WHERE uc.user_id = ? AND l.created_at BETWEEN ? AND ?
            ");
            $stmt->execute([$userId, $monthStart, $monthEnd . ' 23:59:59']);
        }
        $count = (int) ($stmt->fetch()['count'] ?? 0);

        $monthlyTrend[] = [
            'month' => $arabicMonths[$monthName] ?? $monthName,
            'value' => $count
        ];
    }

    // Calculate percentages for chart
    $maxMonthly = max(array_column($monthlyTrend, 'value')) ?: 1;
    foreach ($monthlyTrend as &$m) {
        $m['percentage'] = round(($m['value'] / $maxMonthly) * 100);
    }

    send_success([
        'stats' => [
            'totalLeads' => $totalLeads,
            'totalCampaigns' => (int) ($campaignStats['total_campaigns'] ?? 0),
            'activeCampaigns' => (int) ($campaignStats['active_campaigns'] ?? 0),
            'completedCampaigns' => (int) ($campaignStats['completed_campaigns'] ?? 0),
            'uniqueCities' => $uniqueCities
        ],
        'cityData' => $cityDataFormatted,
        'categoryData' => $categoryDataFormatted,
        'monthlyTrend' => $monthlyTrend,
        'period' => $period,
        'userType' => $isSuperAdmin ? 'superadmin' : 'user'
    ]);

} catch (Throwable $e) {
    error_log('Analytics API Error: ' . $e->getMessage());
    send_error('حدث خطأ في الخادم: ' . $e->getMessage(), 'SERVER_ERROR', 500);
}
