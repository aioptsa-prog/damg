<?php
/**
 * CORS Allowlist Helper (Critical Security Fix)
 * 
 * Usage:
 *   require_once __DIR__ . '/../lib/cors.php';
 *   handle_cors(); // Call at the very beginning of API files
 * 
 * Configuration:
 *   Set ALLOWED_ORIGINS environment variable (comma-separated)
 *   Default: http://localhost:3000
 * 
 * @since Sprint 0 - Security Hardening
 */

/**
 * Handle CORS with allowlist
 * 
 * @param array $allowedMethods HTTP methods to allow (default: POST, OPTIONS)
 * @param array $allowedHeaders Headers to allow
 * @return void Exits with 403 if origin not allowed, 204 for preflight
 */
function handle_cors(
    array $allowedMethods = ['POST', 'OPTIONS'],
    array $allowedHeaders = ['Content-Type', 'Authorization', 'X-Api-Key']
): void {
    $allowedOriginsEnv = getenv('ALLOWED_ORIGINS') ?: 'http://localhost:3000';
    $allowedOrigins = array_map('trim', explode(',', $allowedOriginsEnv));
    
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    
    if ($origin && in_array($origin, $allowedOrigins, true)) {
        header("Access-Control-Allow-Origin: $origin");
        header("Vary: Origin");
        header("Access-Control-Allow-Methods: " . implode(', ', $allowedMethods));
        header("Access-Control-Allow-Headers: " . implode(', ', $allowedHeaders));
        header("Access-Control-Max-Age: 86400"); // Cache preflight for 24 hours
    } else if ($origin) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => 'CORS: origin not allowed',
            'origin' => $origin
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Handle preflight
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

/**
 * Handle CORS for public APIs (more permissive but still controlled)
 * Used for endpoints that need to be accessed from multiple frontends
 * 
 * @return void
 */
function handle_cors_public(): void {
    handle_cors(
        ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
        ['Content-Type', 'Authorization', 'X-Api-Key', 'X-Requested-With']
    );
}
