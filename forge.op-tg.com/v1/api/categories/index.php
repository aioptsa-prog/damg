<?php
/**
 * REST API v1 - Categories Endpoint: List Categories
 * 
 * GET /v1/api/categories/index.php
 */

require_once __DIR__ . '/../bootstrap_api.php';

// Only allow GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

try {
    $pdo = db();

    // Fetch all active categories
    $stmt = $pdo->prepare("
        SELECT id, parent_id, name, slug, depth, icon_type, icon_value, is_active
        FROM categories
        WHERE is_active = 1
        ORDER BY depth ASC, name ASC
    ");
    $stmt->execute();
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Build tree structure
    $tree = [];
    $lookup = [];

    // First pass: Create lookup and format nodes
    foreach ($categories as $cat) {
        $node = [
            'id' => (int) $cat['id'],
            'name' => $cat['name'],
            'slug' => $cat['slug'],
            'parent_id' => $cat['parent_id'] ? (int) $cat['parent_id'] : null,
            'depth' => (int) $cat['depth'],
            'icon' => $cat['icon_type'] ? [
                'type' => $cat['icon_type'],
                'value' => $cat['icon_value']
            ] : null,
            'children' => []
        ];
        $lookup[$cat['id']] = &$node;

        if ($cat['parent_id'] === null) {
            $tree[] = &$node;
        }
    }

    // Second pass: Attach children to parents
    foreach ($categories as $cat) {
        if ($cat['parent_id'] !== null && isset($lookup[$cat['parent_id']])) {
            $lookup[$cat['parent_id']]['children'][] = &$lookup[$cat['id']];
        }
    }

    // Also provide flat list (easier for dropdowns)
    $flat = array_map(function ($cat) {
        return [
            'id' => (int) $cat['id'],
            'name' => $cat['name'],
            'slug' => $cat['slug'],
            'parent_id' => $cat['parent_id'] ? (int) $cat['parent_id'] : null,
            'depth' => (int) $cat['depth']
        ];
    }, $categories);

    send_success([
        'data' => $tree,
        'flat' => $flat,
        'total' => count($categories)
    ]);

} catch (Throwable $e) {
    error_log('Categories API Error: ' . $e->getMessage());
    send_error('حدث خطأ في الخادم', 'SERVER_ERROR', 500);
}
