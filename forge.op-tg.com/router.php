<?php
/**
 * Router for PHP built-in server
 * Handles routing for both React SPA (built) and PHP endpoints
 */

$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = ltrim($requestUri, '/');

// Static files from dist folder
$distPath = __DIR__ . '/saudi-lead-iq-main/dist/' . $path;
if (is_file($distPath)) {
    $ext = pathinfo($distPath, PATHINFO_EXTENSION);
    $mimeTypes = [
        'js' => 'application/javascript',
        'css' => 'text/css',
        'html' => 'text/html',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
    ];
    if (isset($mimeTypes[$ext])) {
        header('Content-Type: ' . $mimeTypes[$ext]);
    }
    readfile($distPath);
    return true;
}

// API routes - pass to PHP
if (preg_match('#^(api|v1)/#', $path)) {
    return false;
}

// Admin/Auth/Agent routes - pass to PHP
if (preg_match('#^(admin|auth|agent)/#', $path)) {
    return false;
}

// Direct PHP files
if (pathinfo($path, PATHINFO_EXTENSION) === 'php') {
    return false;
}

// Public platform routes OR root - serve React SPA
if (strpos($path, 'public') === 0 || $path === '' || $path === '/') {
    $indexPath = __DIR__ . '/saudi-lead-iq-main/dist/index.html';
    if (file_exists($indexPath)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($indexPath);
        return true;
    }
}

// Assets folder from dist
if (strpos($path, 'assets/') === 0) {
    $assetPath = __DIR__ . '/saudi-lead-iq-main/dist/' . $path;
    if (is_file($assetPath)) {
        return false; // Let PHP serve it
    }
}

// Fallback - let PHP handle it
return false;
