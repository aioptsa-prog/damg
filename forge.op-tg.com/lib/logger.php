<?php
/**
 * Structured Logger with Correlation ID
 * 
 * Features:
 * - Correlation ID per request/job
 * - Structured JSON logs
 * - Secret masking
 * - Log levels (debug, info, warn, error)
 */

class Logger {
    private static ?string $correlationId = null;
    private static string $logFile = '';
    private static bool $initialized = false;
    
    // Patterns to mask in logs
    private static array $secretPatterns = [
        '/(["\']?(?:api_key|apikey|secret|token|password|auth|bearer|jwt)["\']?\s*[:=]\s*["\']?)([^"\'&\s]{8,})(["\']?)/i',
        '/(sk-[a-zA-Z0-9]{20,})/',
        '/(AIza[a-zA-Z0-9_-]{30,})/',
        '/(ghp_[a-zA-Z0-9]{30,})/',
        '/(postgresql:\/\/[^:]+:)([^@]+)(@)/',
    ];
    
    public static function init(?string $correlationId = null): void {
        if (self::$initialized && self::$correlationId) {
            return;
        }
        
        self::$correlationId = $correlationId ?? self::generateCorrelationId();
        self::$logFile = __DIR__ . '/../logs/' . date('Y-m-d') . '.log';
        
        // Ensure logs directory exists
        $logDir = dirname(self::$logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        self::$initialized = true;
    }
    
    public static function getCorrelationId(): string {
        if (!self::$correlationId) {
            self::init();
        }
        return self::$correlationId;
    }
    
    public static function setCorrelationId(string $id): void {
        self::$correlationId = $id;
    }
    
    private static function generateCorrelationId(): string {
        return sprintf(
            '%s-%s',
            date('Ymd-His'),
            bin2hex(random_bytes(4))
        );
    }
    
    public static function maskSecrets(string $message): string {
        $masked = $message;
        
        // Mask known secret patterns
        $masked = preg_replace(self::$secretPatterns[0], '$1***MASKED***$3', $masked);
        $masked = preg_replace(self::$secretPatterns[1], 'sk-***MASKED***', $masked);
        $masked = preg_replace(self::$secretPatterns[2], 'AIza***MASKED***', $masked);
        $masked = preg_replace(self::$secretPatterns[3], 'ghp_***MASKED***', $masked);
        $masked = preg_replace(self::$secretPatterns[4], '$1***MASKED***$3', $masked);
        
        return $masked;
    }
    
    public static function log(string $level, string $message, array $context = []): void {
        if (!self::$initialized) {
            self::init();
        }
        
        // Mask secrets in message and context
        $message = self::maskSecrets($message);
        $context = json_decode(self::maskSecrets(json_encode($context)), true) ?? $context;
        
        $entry = [
            'timestamp' => date('c'),
            'level' => strtoupper($level),
            'correlation_id' => self::$correlationId,
            'message' => $message,
        ];
        
        if (!empty($context)) {
            $entry['context'] = $context;
        }
        
        // Add request info if available
        if (php_sapi_name() !== 'cli') {
            $entry['request'] = [
                'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
                'uri' => $_SERVER['REQUEST_URI'] ?? '',
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ];
        }
        
        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        
        // Write to file
        file_put_contents(self::$logFile, $line, FILE_APPEND | LOCK_EX);
        
        // Also output to stderr for CLI
        if (php_sapi_name() === 'cli' && in_array($level, ['error', 'warn'])) {
            fwrite(STDERR, $line);
        }
    }
    
    public static function debug(string $message, array $context = []): void {
        self::log('debug', $message, $context);
    }
    
    public static function info(string $message, array $context = []): void {
        self::log('info', $message, $context);
    }
    
    public static function warn(string $message, array $context = []): void {
        self::log('warn', $message, $context);
    }
    
    public static function error(string $message, array $context = []): void {
        self::log('error', $message, $context);
    }
    
    /**
     * Log job-specific events with job context
     */
    public static function job(string $level, int $jobId, string $message, array $context = []): void {
        $context['job_id'] = $jobId;
        self::log($level, "[Job:$jobId] $message", $context);
    }
    
    /**
     * Start a new span for timing operations
     */
    public static function startSpan(string $name): array {
        return [
            'name' => $name,
            'start' => microtime(true),
            'correlation_id' => self::$correlationId,
        ];
    }
    
    /**
     * End a span and log the duration
     */
    public static function endSpan(array $span, array $context = []): float {
        $duration = round((microtime(true) - $span['start']) * 1000, 2);
        $context['duration_ms'] = $duration;
        self::info("Span completed: {$span['name']}", $context);
        return $duration;
    }
}

// Helper function for quick logging
function log_info(string $message, array $context = []): void {
    Logger::info($message, $context);
}

function log_error(string $message, array $context = []): void {
    Logger::error($message, $context);
}

function log_warn(string $message, array $context = []): void {
    Logger::warn($message, $context);
}
