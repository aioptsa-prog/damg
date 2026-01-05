<?php
/**
 * Input Validation & Limits
 * Sprint 1.2: Input Validation + Limits
 * 
 * Features:
 * - Schema validation for JSON input
 * - Max length enforcement
 * - Payload size limits
 * - Type checking
 * 
 * @since Sprint 1
 */

// Maximum payload size (default 1MB)
define('MAX_PAYLOAD_SIZE', (int)(getenv('MAX_PAYLOAD_SIZE') ?: 1048576));

// Maximum string field length (default 10KB)
define('MAX_STRING_LENGTH', (int)(getenv('MAX_STRING_LENGTH') ?: 10240));

/**
 * Check and limit raw input size
 * Call this BEFORE json_decode
 * 
 * @param int|null $maxSize Maximum allowed size in bytes
 * @return string Raw input
 * @throws Exits with 413 if payload too large
 */
function check_payload_size(?int $maxSize = null): string {
    $maxSize = $maxSize ?? MAX_PAYLOAD_SIZE;
    
    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > $maxSize) {
        http_response_code(413);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'error' => 'PAYLOAD_TOO_LARGE',
            'message' => 'Request payload exceeds maximum allowed size',
            'max_bytes' => $maxSize
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $raw = file_get_contents('php://input');
    if (strlen($raw) > $maxSize) {
        http_response_code(413);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'error' => 'PAYLOAD_TOO_LARGE',
            'message' => 'Request payload exceeds maximum allowed size',
            'max_bytes' => $maxSize
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    return $raw;
}

/**
 * Parse and validate JSON input
 * 
 * @param string|null $raw Raw input (if null, reads from php://input)
 * @return array Parsed JSON
 * @throws Exits with 400 if invalid JSON
 */
function parse_json_input(?string $raw = null): array {
    if ($raw === null) {
        $raw = check_payload_size();
    }
    
    if (empty($raw)) {
        return [];
    }
    
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'error' => 'INVALID_JSON',
            'message' => 'Invalid JSON in request body: ' . json_last_error_msg()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    if (!is_array($data)) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'error' => 'INVALID_JSON',
            'message' => 'JSON root must be an object or array'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    return $data;
}

/**
 * Validate input against schema
 * 
 * Schema format:
 * [
 *   'field_name' => [
 *     'type' => 'string|int|float|bool|array|email|phone|url',
 *     'required' => true|false,
 *     'min' => minimum value/length,
 *     'max' => maximum value/length,
 *     'pattern' => regex pattern,
 *     'enum' => ['allowed', 'values'],
 *     'default' => default value if not provided,
 *   ]
 * ]
 * 
 * @param array $data Input data
 * @param array $schema Validation schema
 * @return array Validated and sanitized data
 * @throws Exits with 400 if validation fails
 */
function validate_schema(array $data, array $schema): array {
    $errors = [];
    $validated = [];
    
    foreach ($schema as $field => $rules) {
        $value = $data[$field] ?? null;
        $required = $rules['required'] ?? false;
        $type = $rules['type'] ?? 'string';
        
        // Check required
        if ($required && ($value === null || $value === '')) {
            $errors[] = "Field '$field' is required";
            continue;
        }
        
        // Apply default if not provided
        if ($value === null && isset($rules['default'])) {
            $validated[$field] = $rules['default'];
            continue;
        }
        
        // Skip validation if not provided and not required
        if ($value === null) {
            continue;
        }
        
        // Type validation
        $typeError = validate_type($value, $type, $field);
        if ($typeError) {
            $errors[] = $typeError;
            continue;
        }
        
        // Length/value limits
        if (isset($rules['min'])) {
            if (is_string($value) && strlen($value) < $rules['min']) {
                $errors[] = "Field '$field' must be at least {$rules['min']} characters";
                continue;
            }
            if (is_numeric($value) && $value < $rules['min']) {
                $errors[] = "Field '$field' must be at least {$rules['min']}";
                continue;
            }
        }
        
        if (isset($rules['max'])) {
            if (is_string($value) && strlen($value) > $rules['max']) {
                $errors[] = "Field '$field' must be at most {$rules['max']} characters";
                continue;
            }
            if (is_numeric($value) && $value > $rules['max']) {
                $errors[] = "Field '$field' must be at most {$rules['max']}";
                continue;
            }
        }
        
        // Default max string length
        if (is_string($value) && strlen($value) > MAX_STRING_LENGTH && !isset($rules['max'])) {
            $errors[] = "Field '$field' exceeds maximum length";
            continue;
        }
        
        // Pattern validation
        if (isset($rules['pattern']) && is_string($value)) {
            if (!preg_match($rules['pattern'], $value)) {
                $errors[] = "Field '$field' has invalid format";
                continue;
            }
        }
        
        // Enum validation
        if (isset($rules['enum']) && !in_array($value, $rules['enum'], true)) {
            $allowed = implode(', ', $rules['enum']);
            $errors[] = "Field '$field' must be one of: $allowed";
            continue;
        }
        
        // Sanitize and add to validated data
        $validated[$field] = sanitize_value($value, $type);
    }
    
    if (!empty($errors)) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'error' => 'VALIDATION_ERROR',
            'message' => 'Input validation failed',
            'errors' => $errors
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    return $validated;
}

/**
 * Validate value type
 * 
 * @param mixed $value
 * @param string $type
 * @param string $field
 * @return string|null Error message or null if valid
 */
function validate_type($value, string $type, string $field): ?string {
    switch ($type) {
        case 'string':
            if (!is_string($value) && !is_numeric($value)) {
                return "Field '$field' must be a string";
            }
            break;
            
        case 'int':
        case 'integer':
            if (!is_numeric($value) || (int)$value != $value) {
                return "Field '$field' must be an integer";
            }
            break;
            
        case 'float':
        case 'number':
            if (!is_numeric($value)) {
                return "Field '$field' must be a number";
            }
            break;
            
        case 'bool':
        case 'boolean':
            if (!is_bool($value) && $value !== 0 && $value !== 1 && $value !== '0' && $value !== '1') {
                return "Field '$field' must be a boolean";
            }
            break;
            
        case 'array':
            if (!is_array($value)) {
                return "Field '$field' must be an array";
            }
            break;
            
        case 'email':
            if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                return "Field '$field' must be a valid email address";
            }
            break;
            
        case 'phone':
            $cleaned = preg_replace('/[^\d+]/', '', $value);
            if (strlen($cleaned) < 9 || strlen($cleaned) > 15) {
                return "Field '$field' must be a valid phone number";
            }
            break;
            
        case 'url':
            if (!filter_var($value, FILTER_VALIDATE_URL)) {
                return "Field '$field' must be a valid URL";
            }
            break;
    }
    
    return null;
}

/**
 * Sanitize value based on type
 * 
 * @param mixed $value
 * @param string $type
 * @return mixed Sanitized value
 */
function sanitize_value($value, string $type) {
    switch ($type) {
        case 'string':
            return trim((string)$value);
            
        case 'int':
        case 'integer':
            return (int)$value;
            
        case 'float':
        case 'number':
            return (float)$value;
            
        case 'bool':
        case 'boolean':
            return (bool)$value;
            
        case 'email':
            return strtolower(trim((string)$value));
            
        case 'phone':
            return preg_replace('/[^\d+]/', '', $value);
            
        case 'url':
            return filter_var($value, FILTER_SANITIZE_URL);
            
        default:
            return $value;
    }
}

/**
 * Quick validation for common patterns
 */
function validate_required(array $data, array $fields): void {
    $missing = [];
    foreach ($fields as $field) {
        if (!isset($data[$field]) || $data[$field] === '') {
            $missing[] = $field;
        }
    }
    
    if (!empty($missing)) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'error' => 'VALIDATION_ERROR',
            'message' => 'Missing required fields: ' . implode(', ', $missing)
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/**
 * Validate ID format (integer or UUID)
 * 
 * @param mixed $id
 * @param string $field
 * @return int|string Validated ID
 */
function validate_id($id, string $field = 'id') {
    if (is_numeric($id) && (int)$id > 0) {
        return (int)$id;
    }
    
    // UUID format
    if (is_string($id) && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $id)) {
        return $id;
    }
    
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'error' => 'VALIDATION_ERROR',
        'message' => "Invalid $field format"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
