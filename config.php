<?php
/**
 * Archive API Configuration
 */

// Database configuration for Archive API
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'hsb2b_archive_api');
define('DB_USER', 'hsb2b_sasho');
define('DB_PASS', 'Jana009@');

// File upload settings
define('MAX_FILE_SIZE', 256 * 1024 * 1024); // 256MB in bytes
define('UPLOAD_DIR', __DIR__ . '/uploads/');

// Timezone
date_default_timezone_set('Europe/Sofia');

// Error reporting (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database connection
function getDbConnection() {
    static $conn = null;
    
    if ($conn === null) {
        try {
            $conn = new PDO(
                "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
            exit;
        }
    }
    
    return $conn;
}

// Initialize database tables if they don't exist
function initDatabase() {
    $db = getDbConnection();
    
    // Create files table
    $db->exec("
        CREATE TABLE IF NOT EXISTS archive_files (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            username VARCHAR(255) NOT NULL,
            original_name VARCHAR(500) NOT NULL,
            stored_name VARCHAR(500) NOT NULL,
            path TEXT NOT NULL,
            directory_path TEXT NOT NULL DEFAULT '',
            mime VARCHAR(255),
            size BIGINT UNSIGNED DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_username (username),
            INDEX idx_directory_path (directory_path(255))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    // Create directories table
    $db->exec("
        CREATE TABLE IF NOT EXISTS archive_directories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            username VARCHAR(255) NOT NULL,
            name VARCHAR(500) NOT NULL,
            path TEXT NOT NULL,
            parent_path TEXT NOT NULL DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_username (username),
            INDEX idx_path (path(255)),
            INDEX idx_parent_path (parent_path(255))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

// Ensure upload directory exists
if (!file_exists(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

// Initialize database on first load
initDatabase();
