<?php
/**
 * =====================================================================
 * HOTEL SAI NEST — HOTEL MANAGEMENT SYSTEM
 * Core Configuration
 * =====================================================================
 * Edit the database credentials below before first use.
 * When you move this project to a subdomain (e.g. manage.sainest.org),
 * you do NOT need to change anything else in this file — BASE_URL is
 * detected automatically from the request.
 * =====================================================================
 */

// ---------------------------------------------------------------------
// 1. DATABASE CREDENTIALS — update these to match your hosting account
// ---------------------------------------------------------------------
define('DB_HOST', 'localhost');
define('DB_NAME', 'sainest_hms');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// ---------------------------------------------------------------------
// 2. APPLICATION SETTINGS
// ---------------------------------------------------------------------
define('APP_NAME', 'Hotel Sai Nest — Management System');
define('APP_TIMEZONE', 'Asia/Kolkata');

// Auto-detect base URL so the app works from any folder / subdomain
// without manual editing.
if (!function_exists('hms_detect_base_url')) {
    function hms_detect_base_url() {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $script   = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        // Normalise: config.php lives in /config, so go one level up
        $root = preg_replace('#/config$#', '', $script);
        $root = rtrim($root, '/');
        return $protocol . $host . $root;
    }
}
define('BASE_URL', hms_detect_base_url());

// Absolute filesystem paths
define('ROOT_PATH', dirname(__DIR__));
define('UPLOAD_DOCS_PATH', ROOT_PATH . '/uploads/documents');
define('UPLOAD_LOGO_PATH', ROOT_PATH . '/uploads/logo');

// Max upload size for ID document photos (bytes) — 5 MB
define('MAX_DOC_UPLOAD_SIZE', 5 * 1024 * 1024);

// ---------------------------------------------------------------------
// 3. DATA RETENTION POLICY
// ---------------------------------------------------------------------
// Guests whose most recent stay is older than this many months will
// have their personal identity data (name, phone, email, ID number,
// ID photos) automatically anonymised by cron/data_retention_cleanup.php
// This value can also be overridden from Settings once the app is
// running (stored in the `settings` table as data_retention_months).
define('DEFAULT_DATA_RETENTION_MONTHS', 12);

// ---------------------------------------------------------------------
// 4. SESSION + ERROR HANDLING
// ---------------------------------------------------------------------
date_default_timezone_set(APP_TIMEZONE);

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Set to true temporarily while setting up / troubleshooting, then back to false
define('APP_DEBUG', false);
if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}
