<?php
/**
 * OFFGRID - Database Configuration & Connection
 * 
 * Centralized PDO connection to MySQL.
 * Uses utf8mb4 encoding, strict error modes, and disabled prepared statement emulation.
 * Credentials are never exposed to visitors or client-side scripts.
 */

// Centralized Database Credentials
define('DB_HOST', 'localhost');
define('DB_NAME', 'offgrid_db');
define('DB_USER', 'root');
define('DB_PASS', '');          // Default XAMPP MySQL password is empty
define('DB_CHARSET', 'utf8mb4');

// Enforce India Standard Time (IST) globally across PHP runtime
date_default_timezone_set('Asia/Kolkata');

/**
 * Initializes and returns a shared PDO connection instance.
 *
 * @return PDO
 */
function get_db_connection() {
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            // Sync MySQL session timezone with Asia/Kolkata (IST = UTC+05:30)
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+05:30'",
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Log real error internally for developer inspection
            error_log('[OFFGRID DB ERROR] ' . $e->getMessage());

            // User-friendly response without exposing server paths or database credentials
            http_response_code(503);
            ?>
            <!DOCTYPE html>
            <html lang="en-IN">
            <head>
                <meta charset="UTF-8">

                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>Database Offline - OFFGRID</title>
                <style>
                    body {
                        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                        background: #0d1117;
                        color: #c9d1d9;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        min-height: 100vh;
                        margin: 0;
                        padding: 1rem;
                        box-sizing: border-box;
                    }
                    .error-card {
                        background: #161b22;
                        border: 1px solid #30363d;
                        border-radius: 12px;
                        padding: 2rem;
                        max-width: 520px;
                        width: 100%;
                        text-align: center;
                        box-shadow: 0 10px 30px rgba(0,0,0,0.5);
                    }
                    h1 { color: #f85149; margin-top: 0; font-size: 1.5rem; }
                    p { line-height: 1.6; color: #8b949e; font-size: 0.95rem; }
                    .tip {
                        background: #0d1117;
                        border-left: 3px solid #e3b341;
                        padding: 0.75rem 1rem;
                        text-align: left;
                        border-radius: 4px;
                        margin-top: 1.5rem;
                        font-size: 0.85rem;
                        color: #e3b341;
                    }
                </style>
            </head>
            <body>
                <div class="error-card">
                    <h1>Database Connection Unavailable</h1>
                    <p>OFFGRID could not connect to the database. Please verify that MySQL is running in your XAMPP Control Panel and that <code>schema.sql</code> has been imported.</p>
                    <div class="tip">
                        <strong>Quick Fix:</strong> Open XAMPP Control Panel &rarr; Click <em>Start</em> on MySQL &rarr; Import <code>database/schema.sql</code> in phpMyAdmin.
                    </div>
                </div>
            </body>
            </html>
            <?php
            exit;
        }
    }

    return $pdo;
}

// Global PDO instance for quick access across scripts
$pdo = get_db_connection();
