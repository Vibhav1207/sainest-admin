<?php
/**
 * Database connection (PDO / MySQL)
 */
require_once __DIR__ . '/../config/config.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);

            // Keep MySQL's session clock in lockstep with the app's PHP
            // timezone (Asia/Kolkata / IST, UTC+5:30). Without this, NOW(),
            // CURDATE(), CURRENT_TIMESTAMP and "ON UPDATE CURRENT_TIMESTAMP"
            // columns are computed using the DATABASE SERVER's own timezone
            // setting (often UTC on shared hosting), which can silently
            // drift from what PHP shows the user by 5.5 hours — e.g. a
            // booking's created_at timestamp not matching the check-in time
            // just entered, or "today's" dashboard totals missing rows near
            // midnight IST. Setting it explicitly here removes that class
            // of bug regardless of how the MySQL server itself is configured.
            $pdo->exec("SET time_zone = '+05:30'");
        } catch (PDOException $e) {
            if (APP_DEBUG) {
                die('Database connection failed: ' . $e->getMessage() .
                    '<br><br>Please check the credentials in <code>config/config.php</code> ' .
                    'and make sure you have imported <code>database.sql</code>.');
            }
            die('Database connection failed. Please contact the site administrator.');
        }
    }
    return $pdo;
}
