<?php
/**
 * Security Headers
 * Sprint 1.4: Security Headers + XSS Mitigation
 * 
 * Note: CSRF is NOT needed because:
 * - Auth is via Bearer token in Authorization header (not cookies)
 * - SameSite cookies are used for session (Lax/Strict)
 * - APIs are stateless and don't rely on ambient authority
 * 
 * @since Sprint 1
 */

/**
 * Apply security headers to response
 * Call this early in API endpoints
 * 
 * @param array $options Override default options
 */
function apply_security_headers(array $options = []): void {
    $defaults = [
        'content_type' => 'application/json; charset=utf-8',
        'frame_options' => 'DENY',
        'content_type_options' => 'nosniff',
        'xss_protection' => '1; mode=block',
        'referrer_policy' => 'strict-origin-when-cross-origin',
        'hsts' => false, // Enable only in production with HTTPS
        'csp' => null, // Content Security Policy (for HTML responses)
    ];
    
    $opts = array_merge($defaults, $options);
    
    // Content-Type
    if ($opts['content_type']) {
        header('Content-Type: ' . $opts['content_type']);
    }
    
    // X-Frame-Options - Prevent clickjacking
    if ($opts['frame_options']) {
        header('X-Frame-Options: ' . $opts['frame_options']);
    }
    
    // X-Content-Type-Options - Prevent MIME sniffing
    if ($opts['content_type_options']) {
        header('X-Content-Type-Options: ' . $opts['content_type_options']);
    }
    
    // X-XSS-Protection - Legacy XSS filter (for older browsers)
    if ($opts['xss_protection']) {
        header('X-XSS-Protection: ' . $opts['xss_protection']);
    }
    
    // Referrer-Policy - Control referrer information
    if ($opts['referrer_policy']) {
        header('Referrer-Policy: ' . $opts['referrer_policy']);
    }
    
    // HSTS - Force HTTPS (only enable in production)
    if ($opts['hsts'] && is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    
    // Content-Security-Policy (mainly for HTML responses)
    if ($opts['csp']) {
        header('Content-Security-Policy: ' . $opts['csp']);
    }
    
    // Permissions-Policy - Disable unnecessary browser features
    header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
}

/**
 * Apply security headers for JSON API responses
 */
function apply_api_security_headers(): void {
    apply_security_headers([
        'content_type' => 'application/json; charset=utf-8',
        'hsts' => is_production(),
    ]);
}

/**
 * Apply security headers for HTML responses
 */
function apply_html_security_headers(): void {
    apply_security_headers([
        'content_type' => 'text/html; charset=utf-8',
        'hsts' => is_production(),
        'csp' => "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: https:; connect-src 'self'",
    ]);
}

/**
 * Check if running over HTTPS
 */
function is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
}

/**
 * Check if running in production
 */
function is_production(): bool {
    $env = getenv('APP_ENV') ?: getenv('NODE_ENV') ?: 'development';
    return strtolower($env) === 'production';
}

/**
 * Sanitize output for HTML context (XSS prevention)
 * 
 * @param string $input
 * @return string
 */
function html_escape(string $input): string {
    return htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Sanitize output for JavaScript context
 * 
 * @param mixed $input
 * @return string
 */
function js_escape($input): string {
    return json_encode($input, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
}

// ============================================
// CSRF Decision Documentation
// ============================================
/*
 * CSRF Protection Decision: NOT REQUIRED
 * 
 * Reason: The API uses Bearer token authentication via Authorization header,
 * not cookies with ambient authority.
 * 
 * Evidence:
 * - lib/auth.php:36-48 - Bearer token check in Authorization header
 * - lib/public_auth.php:17-39 - Bearer token check for public users
 * - Session cookies use SameSite=Lax (lib/auth.php:6-13)
 * 
 * CSRF attacks rely on browsers automatically sending cookies.
 * Since auth requires explicit Authorization header, CSRF is not possible.
 * 
 * Additional mitigations:
 * - CORS allowlist (lib/cors.php) - Only allowed origins can make requests
 * - SameSite cookies - Prevents cross-site cookie sending
 * - Security headers - X-Frame-Options, X-Content-Type-Options
 */
