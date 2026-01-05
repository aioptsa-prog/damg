<?php
/**
 * Integration Feature Flags
 * All new integration features are behind these flags for safe rollout
 * 
 * Usage:
 *   require_once __DIR__ . '/flags.php';
 *   if (integration_flag('auth_bridge')) { ... }
 */

/**
 * Check if an integration flag is enabled
 * 
 * @param string $name Flag name without 'integration_' prefix
 * @return bool True if flag is enabled ('1'), false otherwise
 */
function integration_flag(string $name): bool {
    return get_setting('integration_' . $name, '0') === '1';
}

/**
 * Get all integration flag statuses
 * 
 * @return array Associative array of flag names to boolean values
 */
function integration_flags_all(): array {
    return [
        'auth_bridge' => integration_flag('auth_bridge'),
        'survey_from_lead' => integration_flag('survey_from_lead'),
        'send_from_report' => integration_flag('send_from_report'),
        'unified_lead_view' => integration_flag('unified_lead_view'),
        'worker_enabled' => integration_flag('worker_enabled'),
        'instagram_enabled' => integration_flag('instagram_enabled'),
    ];
}

/**
 * Log enabled flags (for debugging)
 */
function integration_flags_log(): void {
    $flags = integration_flags_all();
    $enabled = array_keys(array_filter($flags));
    
    if (!empty($enabled)) {
        error_log('[FLAGS] Enabled: ' . implode(', ', $enabled));
    }
}
