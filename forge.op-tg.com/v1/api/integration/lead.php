<?php
/**
 * Integration Lead Endpoint
 * GET /v1/api/integration/lead.php?id=xxx
 * GET /v1/api/integration/lead.php?phone=xxx
 * 
 * Returns minimal lead data for integration purposes.
 * Requires valid integration token (from exchange endpoint).
 * 
 * SECURITY:
 * - Behind INTEGRATION_AUTH_BRIDGE flag
 * - Requires valid integration token
 * - Returns minimal data only (no sensitive fields)
 * 
 * @since Phase 2
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../bootstrap.php';
require_once __DIR__ . '/../../../lib/flags.php';
require_once __DIR__ . '/../../../lib/integration_auth.php';

// === Feature Flag Check ===
if (!integration_flag('auth_bridge')) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Not found']);
    exit;
}

// === Only GET allowed ===
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// === Verify integration token ===
$integration = verify_integration_token();
if (!$integration) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Invalid or expired integration token']);
    exit;
}

// === Get query parameters ===
$leadId = isset($_GET['id']) ? trim((string)$_GET['id']) : '';
$phone = isset($_GET['phone']) ? trim((string)$_GET['phone']) : '';

if ($leadId === '' && $phone === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing id or phone parameter']);
    exit;
}

$pdo = db();

try {
    // Build query based on parameter
    if ($leadId !== '') {
        $stmt = $pdo->prepare("
            SELECT id, phone, phone_norm, name, city, category_id, created_at
            FROM leads 
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$leadId]);
    } else {
        // Normalize phone for lookup
        $phoneNorm = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($phoneNorm) === 10 && $phoneNorm[0] === '0') {
            $phoneNorm = '966' . substr($phoneNorm, 1);
        } elseif (strlen($phoneNorm) === 9) {
            $phoneNorm = '966' . $phoneNorm;
        }
        
        $stmt = $pdo->prepare("
            SELECT id, phone, phone_norm, name, city, category_id, created_at
            FROM leads 
            WHERE phone_norm = ? OR phone = ?
            LIMIT 1
        ");
        $stmt->execute([$phoneNorm, $phone]);
    }
    
    $lead = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$lead) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Lead not found']);
        exit;
    }
    
    // Get category name if exists
    $categoryName = null;
    if ($lead['category_id']) {
        $catStmt = $pdo->prepare("SELECT name FROM categories WHERE id = ?");
        $catStmt->execute([$lead['category_id']]);
        $cat = $catStmt->fetch(PDO::FETCH_ASSOC);
        if ($cat) {
            $categoryName = $cat['name'];
        }
    }
    
    // Return minimal lead data
    echo json_encode([
        'ok' => true,
        'lead' => [
            'id' => (string)$lead['id'],
            'phone' => $lead['phone'],
            'phone_norm' => $lead['phone_norm'],
            'name' => $lead['name'],
            'city' => $lead['city'],
            'category' => $categoryName,
            'created_at' => $lead['created_at'],
        ],
    ]);
    
} catch (PDOException $e) {
    error_log("[INTEGRATION] lead.php: Database error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database error']);
}
