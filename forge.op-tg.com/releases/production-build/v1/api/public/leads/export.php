<?php
/**
 * Public API - Export Leads
 * POST /v1/api/public/leads/export.php
 */

require_once __DIR__ . '/../../bootstrap_api.php';
require_once __DIR__ . '/../../../../lib/subscriptions.php';

// Require authentication
$user = require_api_auth();

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

try {
    $data = get_json_input();

    // Validate format
    $format = $data['format'] ?? 'csv';
    if (!in_array($format, ['csv', 'excel', 'json'])) {
        send_error('صيغة غير صالحة', 'INVALID_FORMAT', 400);
    }

    // Check export quota
    $quota = check_quota($user['id'], 'export');
    if (!$quota['allowed']) {
        send_error(
            'لقد استنفدت حصة التصدير الشهرية. قم بترقية باقتك للمزيد.',
            'QUOTA_EXCEEDED',
            403
        );
    }

    $pdo = db();

    // Get filters
    $filters = $data['filters'] ?? [];
    $lead_ids = $data['lead_ids'] ?? [];

    // Build query
    $sql = "SELECT 
        l.id, l.name, l.phone, l.email, l.website,
        l.city, l.district, l.company, l.industry,
        l.employees_count, l.rating, l.source, l.created_at
    FROM leads l WHERE 1=1";

    $params = [];

    // Apply filters
    if (!empty($lead_ids)) {
        $placeholders = str_repeat('?,', count($lead_ids) - 1) . '?';
        $sql .= " AND l.id IN ($placeholders)";
        $params = array_merge($params, $lead_ids);
    } else {
        if (!empty($filters['category_id'])) {
            $sql .= " AND l.category_id = ?";
            $params[] = $filters['category_id'];
        }
        if (!empty($filters['city'])) {
            $sql .= " AND l.city = ?";
            $params[] = $filters['city'];
        }
        if (!empty($filters['search'])) {
            $sql .= " AND (l.name LIKE ? OR l.company LIKE ?)";
            $search = '%' . $filters['search'] . '%';
            $params[] = $search;
            $params[] = $search;
        }
    }

    $sql .= " ORDER BY l.created_at DESC LIMIT 1000"; // Max 1000 records per export

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $leads = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($leads)) {
        send_error('لا توجد نتائج للتصدير', 'NO_RESULTS', 400);
    }

    // Deduct export credit
    deduct_credit($user['id'], 'export');

    // Log export
    $stmt = $pdo->prepare("
        INSERT INTO export_history (user_id, export_type, filters, record_count, created_at)
        VALUES (?, ?, ?, ?, datetime('now'))
    ");
    $stmt->execute([
        $user['id'],
        $format,
        json_encode($filters),
        count($leads)
    ]);

    // Generate file based on format
    $timestamp = date('Y-m-d_H-i-s');
    $filename = "leads_export_{$timestamp}";

    if ($format === 'csv') {
        $filename .= '.csv';
        $filepath = sys_get_temp_dir() . '/' . $filename;

        $fp = fopen($filepath, 'w');

        // UTF-8 BOM for Excel
        fputs($fp, "\xEF\xBB\xBF");

        // Headers
        fputcsv($fp, [
            'الاسم',
            'الشركة',
            'الصناعة',
            'الهاتف',
            'البريد الإلكتروني',
            'الموقع',
            'المدينة',
            'الحي',
            'عدد الموظفين',
            'التقييم',
            'المصدر'
        ]);

        // Data
        foreach ($leads as $lead) {
            fputcsv($fp, [
                $lead['name'],
                $lead['company'],
                $lead['industry'],
                $lead['phone'],
                $lead['email'],
                $lead['website'],
                $lead['city'],
                $lead['district'],
                $lead['employees_count'],
                $lead['rating'],
                $lead['source']
            ]);
        }

        fclose($fp);

        // Read file and encode as base64
        $content = base64_encode(file_get_contents($filepath));
        unlink($filepath);

        send_success([
            'filename' => $filename,
            'content' => $content,
            'mime_type' => 'text/csv',
            'record_count' => count($leads)
        ]);

    } elseif ($format === 'json') {
        $content = json_encode($leads, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        send_success([
            'filename' => $filename . '.json',
            'content' => base64_encode($content),
            'mime_type' => 'application/json',
            'record_count' => count($leads)
        ]);

    } else {
        send_error('صيغة غير مدعومة حالياً', 'NOT_SUPPORTED', 400);
    }

} catch (Throwable $e) {
    error_log('Export API Error: ' . $e->getMessage());
    send_error('حدث خطأ في الخادم', 'SERVER_ERROR', 500);
}
