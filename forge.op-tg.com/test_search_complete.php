<?php
/**
 * Complete Search API Test
 * Creates a test user and session, then tests the search API
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config/db.php';

echo "===========================================\n";
echo "Lead Search API - Complete Test\n";
echo "===========================================\n\n";

try {
    $pdo = db();

    // Step 1: Check how many leads we have
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM leads");
    $leadCount = $stmt->fetchColumn();
    echo "1. Total leads in database: $leadCount\n";

    // Step 2: Get sample leads data
    echo "\n2. Sample leads data:\n";
    $stmt = $pdo->query("SELECT l.id, l.name, l.city, l.country, l.phone, l.email, l.rating, c.name as category_name 
                         FROM leads l 
                         LEFT JOIN categories c ON l.category_id = c.id 
                         LIMIT 5");
    $sampleLeads = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($sampleLeads as $lead) {
        echo "   - ID: {$lead['id']}\n";
        echo "     Name: {$lead['name']}\n";
        echo "     City: {$lead['city']}\n";
        echo "     Country: {$lead['country']}\n";
        echo "     Phone: " . ($lead['phone'] ?? 'N/A') . "\n";
        echo "     Email: " . ($lead['email'] ?? 'N/A') . "\n";
        echo "     Rating: " . ($lead['rating'] ?? 'N/A') . "\n";
        echo "     Category: " . ($lead['category_name'] ?? 'N/A') . "\n";
        echo "   ---\n";
    }

    // Step 3: Check categories
    echo "\n3. Categories in database:\n";
    $stmt = $pdo->query("SELECT id, name, slug FROM categories WHERE is_active = 1");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($categories as $cat) {
        echo "   - [{$cat['id']}] {$cat['name']} ({$cat['slug']})\n";
    }

    // Step 4: Check cities distribution
    echo "\n4. Cities distribution:\n";
    $stmt = $pdo->query("SELECT city, COUNT(*) as count FROM leads WHERE city IS NOT NULL AND city != '' GROUP BY city ORDER BY count DESC LIMIT 10");
    $cities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cities as $city) {
        echo "   - {$city['city']}: {$city['count']} leads\n";
    }

    // Step 5: Check if we have a public user to test with
    echo "\n5. Checking public users:\n";
    $stmt = $pdo->query("SELECT id, email, name, status FROM public_users LIMIT 5");
    $publicUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($publicUsers)) {
        echo "   No public users found. Creating a test user...\n";

        // Create a test public user
        $email = 'test@example.com';
        $password_hash = password_hash('test1234', PASSWORD_DEFAULT);
        $name = 'Test User';

        $stmt = $pdo->prepare("INSERT INTO public_users (email, password_hash, name, status, email_verified, created_at) 
                               VALUES (?, ?, ?, 'active', 1, datetime('now'))");
        $stmt->execute([$email, $password_hash, $name]);
        $userId = $pdo->lastInsertId();
        echo "   Created test user with ID: $userId\n";

        // Create a session for the test user
        $token = bin2hex(random_bytes(32));
        $token_hash = hash('sha256', $token);
        $expires_at = date('Y-m-d H:i:s', time() + 86400 * 30);

        $stmt = $pdo->prepare("INSERT INTO public_sessions (user_id, token_hash, expires_at, device_info) 
                               VALUES (?, ?, ?, 'Test Script')");
        $stmt->execute([$userId, $token_hash, $expires_at]);
        echo "   Created session for test user\n";
        echo "   Token (save this): $token\n";
    } else {
        foreach ($publicUsers as $user) {
            echo "   - [{$user['id']}] {$user['name']} ({$user['email']}) - Status: {$user['status']}\n";
        }
    }

    // Step 6: Check existing valid sessions
    echo "\n6. Checking valid sessions:\n";
    $stmt = $pdo->query("SELECT ps.id, ps.user_id, pu.email, ps.expires_at 
                         FROM public_sessions ps 
                         JOIN public_users pu ON ps.user_id = pu.id 
                         WHERE ps.expires_at > datetime('now')
                         LIMIT 5");
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($sessions)) {
        echo "   No valid sessions found.\n";
    } else {
        foreach ($sessions as $session) {
            echo "   - Session {$session['id']} for {$session['email']} (expires: {$session['expires_at']})\n";
        }
    }

    echo "\n===========================================\n";
    echo "Database check complete!\n";
    echo "===========================================\n";

} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
}
