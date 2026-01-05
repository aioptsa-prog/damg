<?php
/**
 * LeadHub Production Configuration
 * 
 * قم بتعديل هذا الملف حسب بيانات الاستضافة الخاصة بك
 * واحفظه باسم .env.php في مجلد config/
 */

return [
    // ===========================================
    // Application Settings
    // ===========================================
    'APP_ENV' => 'production',
    'APP_DEBUG' => false,
    'APP_NAME' => 'LeadHub',
    'APP_URL' => 'https://yourdomain.com',      // ← غيّر هذا لدومينك

    // ===========================================
    // API Settings
    // ===========================================
    'API_URL' => 'https://yourdomain.com/v1/api',  // ← غيّر هذا لدومينك

    // ===========================================
    // Database Settings (SQLite)
    // ===========================================
    'DB_PATH' => __DIR__ . '/../storage/database.sqlite',

    // ===========================================
    // Session & Security
    // ===========================================
    'SESSION_LIFETIME' => 43200,  // 12 hours in seconds
    'REMEMBER_COOKIE' => 'leadhub_remember',
    'PUBLIC_SESSION_LIFETIME' => 43200,

    // ===========================================
    // WhatsApp/Washeej Settings (Optional)
    // ===========================================
    'WASHEEJ_API_URL' => 'https://api.washeej.com',  // أو URL الخاص بك

    // ===========================================
    // Worker Settings
    // ===========================================
    'WORKER_ENABLED' => true,
    'WORKER_POLL_INTERVAL' => 5000,  // milliseconds

    // ===========================================
    // Google Maps API (for location search)
    // ===========================================
    'GOOGLE_MAPS_API_KEY' => '',  // اختياري - للبحث بالموقع

    // ===========================================
    // Rate Limiting
    // ===========================================
    'RATE_LIMIT_ENABLED' => true,
    'RATE_LIMIT_MAX_REQUESTS' => 100,
    'RATE_LIMIT_WINDOW' => 60,  // seconds
];
