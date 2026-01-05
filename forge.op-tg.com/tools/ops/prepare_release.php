<?php
// Prepare a clean release build: reset database settings, seed primary accounts, and emit credentials summary.
// Usage: php tools/ops/prepare_release.php

declare(strict_types=1);
if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}
require_once __DIR__ . '/../../bootstrap.php';
$pdo = db();

// Helper to set settings consistently
function put_setting(PDO $pdo, string $key, string $value): void {
    $pdo->prepare("INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value")
        ->execute([$key, $value]);
}

$releaseDomain = 'forge.sotwsora.net';
$baseUrl = 'https://' . $releaseDomain;
$now = date('Y-m-d H:i:s');
$secret = 'forge-' . bin2hex(random_bytes(16));

// Core settings
$settings = [
    'brand_name' => 'Forge SotwSora',
    'brand_tagline_ar' => 'منصة SotwSora Forge لاستخراج البيانات',
    'brand_tagline_en' => 'SotwSora Forge — Data extraction and automation',
    'internal_server_enabled' => '1',
    'internal_secret' => $secret,
    'worker_base_url' => $baseUrl,
    'system_global_stop' => '0',
    'system_pause_enabled' => '0',
    'MAX_MULTI_LOCATIONS' => '10',
    'force_https' => '1',
    'security_csrf_auto' => '1',
    'worker_pull_interval_sec' => '30',
    'worker_lease_sec' => '180',
    'per_worker_secret_required' => '0',
    'workers_online_window_sec' => '120',
    'maintenance_secret' => 'mtn-' . bin2hex(random_bytes(8)),
    'alert_email' => 'ops@forge.sotwsora.net',
];
foreach ($settings as $k => $v) {
    put_setting($pdo, $k, $v);
}

// Create primary accounts
$accounts = [
    [
        'mobile' => '590000000',
        'username' => 'forge-admin',
        'name' => 'Operations Admin',
        'role' => 'admin',
        'is_superadmin' => 1,
        'password' => 'Forge@2025!'
    ],
    [
        'mobile' => '590000001',
        'username' => 'forge-agent',
        'name' => 'Field Agent',
        'role' => 'agent',
        'is_superadmin' => 0,
        'password' => 'Forge@2025!'
    ],
];

foreach ($accounts as $acct) {
    $hash = password_hash($acct['password'], PASSWORD_BCRYPT, ['cost' => 12]);
    $pdo->prepare("INSERT INTO users(mobile, username, name, role, password_hash, is_superadmin, active, created_at)
                    VALUES(:mobile,:username,:name,:role,:hash,:super,1,:now)
                    ON CONFLICT(mobile) DO UPDATE SET
                        username=excluded.username,
                        name=excluded.name,
                        role=excluded.role,
                        password_hash=excluded.password_hash,
                        is_superadmin=excluded.is_superadmin,
                        active=1")
        ->execute([
            ':mobile' => $acct['mobile'],
            ':username' => $acct['username'],
            ':name' => $acct['name'],
            ':role' => $acct['role'],
            ':hash' => $hash,
            ':super' => $acct['is_superadmin'],
            ':now' => $now,
        ]);
}

// Clear operational tables to guarantee clean slate
$tables = [
    'sessions', 'leads', 'assignments', 'washeej_logs', 'place_cache',
    'search_tiles', 'usage_counters', 'internal_jobs', 'job_groups',
    'job_attempts', 'dead_letter_jobs', 'idempotency_keys', 'internal_workers',
    'hmac_replay', 'rate_limit', 'alert_events', 'audit_logs', 'category_activity_log'
];
foreach ($tables as $table) {
    try {
        $pdo->exec('DELETE FROM ' . $table);
    } catch (Throwable $e) {
        // ignore missing tables in clean installs
    }
}
try { $pdo->exec('VACUUM'); } catch (Throwable $e) {}

$output = [
    'base_url' => $baseUrl,
    'internal_secret' => $secret,
    'accounts' => array_map(function ($acct) {
        return [
            'mobile' => $acct['mobile'],
            'username' => $acct['username'],
            'role' => $acct['role'],
            'password' => $acct['password'],
        ];
    }, $accounts),
];

echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
