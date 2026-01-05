<?php
/**
 * API Bootstrap
 * 
 * This file initializes the API environment and ensures clean JSON responses
 * by suppressing PHP warnings/errors that would otherwise break JSON parsing.
 */

// ==================== Error Handling ====================
// Suppress all errors and warnings in API responses
// Errors should be logged to files, not printed to output
error_reporting(0);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

// Set custom error handler to log errors instead of displaying them
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    // Log to error_log instead of displaying
    error_log("PHP Error [$errno]: $errstr in $errfile on line $errline");
    return true; // Prevent default PHP error handler
});

// Set custom exception handler
set_exception_handler(function ($exception) {
    error_log("Uncaught Exception: " . $exception->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => false,
        'message' => 'Internal server error',
        'error' => 'INTERNAL_ERROR'
    ]);
    exit;
});

// ==================== Environment Setup ====================
// Start output buffering to catch any stray output
ob_start();

// Load existing bootstrap
require_once __DIR__ . '/../../bootstrap.php';

// Load unified API auth
require_once __DIR__ . '/../../lib/api_auth.php';

// Clear any output that might have been generated
ob_end_clean();

// ==================== Headers ====================
// Ensure JSON content type
header('Content-Type: application/json; charset=utf-8');

// Note: CORS is now handled by lib/cors.php - call handle_cors() at start of each endpoint

// ==================== Helper Functions ====================

/**
 * Send JSON response and exit
 */
function send_json($data, $status_code = 200)
{
    http_response_code($status_code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Send error response and exit
 */
function send_error($message, $error_code = 'ERROR', $status_code = 400)
{
    send_json([
        'ok' => false,
        'message' => $message,
        'error' => $error_code
    ], $status_code);
}

/**
 * Send success response and exit
 */
function send_success($data = [], $message = null)
{
    $response = ['ok' => true];
    if ($message) {
        $response['message'] = $message;
    }
    $response = array_merge($response, $data);
    send_json($response);
}

/**
 * Validate required fields in request data
 */
function validate_required_fields($data, $required_fields)
{
    $missing = [];
    foreach ($required_fields as $field) {
        if (!isset($data[$field]) || $data[$field] === '') {
            $missing[] = $field;
        }
    }

    if (!empty($missing)) {
        send_error(
            'Missing required fields: ' . implode(', ', $missing),
            'VALIDATION_ERROR',
            400
        );
    }
}

/**
 * Get JSON request body
 */
function get_json_input()
{
    $input = file_get_contents('php://input');
    if (empty($input)) {
        return [];
    }

    $data = json_decode($input, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        send_error('Invalid JSON in request body', 'INVALID_JSON', 400);
    }

    return $data;
}

/**
 * Require authentication for API endpoint
 * @deprecated Use require_api_user() from lib/api_auth.php
 */
function require_api_auth()
{
    return require_api_user();
}

/**
 * Require specific role for API endpoint
 * @deprecated Use require_role() from lib/api_auth.php
 */
function require_api_role($role)
{
    return require_role($role);
}
