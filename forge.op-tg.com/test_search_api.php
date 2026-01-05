<?php
/**
 * Test Search API Endpoint
 * This script tests the leads search functionality
 */

// Token from the previous test file
$token = '207482aa0379e9f162437db2de6b71363d5b1cf2ad1863e5cfac4d21256946c2';

// Simulate the API request
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
$_SERVER['REQUEST_METHOD'] = 'GET';

// Test 1: Basic search with no filters
echo "===========================================\n";
echo "TEST 1: Basic Search (no filters)\n";
echo "===========================================\n";
$_GET = ['page' => 1, 'limit' => 10];

ini_set('display_errors', 1);
error_reporting(E_ALL);

try {
    ob_start();
    require 'v1/api/public/leads/search.php';
    $output = ob_get_clean();

    $response = json_decode($output, true);
    if ($response) {
        echo "Status: " . ($response['ok'] ? 'SUCCESS' : 'FAILED') . "\n";
        echo "Total Leads: " . ($response['pagination']['total'] ?? 'N/A') . "\n";
        echo "Current Page: " . ($response['pagination']['page'] ?? 'N/A') . "\n";
        echo "Leads per page: " . count($response['leads'] ?? []) . "\n";
        echo "User Plan: " . ($response['subscription']['plan'] ?? 'N/A') . "\n\n";

        if (!empty($response['leads'])) {
            echo "Sample Lead Data:\n";
            $lead = $response['leads'][0];
            echo "  - ID: " . $lead['id'] . "\n";
            echo "  - Name: " . $lead['name'] . "\n";
            echo "  - City: " . $lead['city'] . "\n";
            echo "  - Country: " . $lead['country'] . "\n";
            echo "  - Category: " . ($lead['category']['name'] ?? 'N/A') . "\n";
            echo "  - Phone: " . ($lead['phone'] ?? 'Hidden') . "\n";
            echo "  - Email: " . ($lead['email'] ?? 'Hidden') . "\n";
        }
    } else {
        echo "Raw Response:\n$output\n";
    }
} catch (Throwable $e) {
    ob_end_clean();
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

// Reset for next test - we need to re-include with fresh state
// Since PHP caches includes, we'll output what we found
echo "\n";
