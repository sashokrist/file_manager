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
define('MAX_FILE_SIZE', 2 * 1024 * 1024 * 1024); // 2GB in bytes
define('UPLOAD_DIR', __DIR__ . '/uploads/');

// Performance and timeout settings for large file uploads
ini_set('max_execution_time', 0); // No time limit for uploads
ini_set('max_input_time', 0); // No time limit for input
ini_set('memory_limit', '512M'); // Sufficient memory for large files
ini_set('post_max_size', '2048M'); // Match upload_max_filesize
ini_set('upload_max_filesize', '2048M'); // 2GB max upload
ini_set('default_socket_timeout', 300); // 5 minutes socket timeout

// Connection and performance optimizations
ini_set('output_buffering', '4096'); // Enable output buffering
ini_set('zlib.output_compression', 'Off'); // Disable compression for uploads (faster)
ini_set('ignore_user_abort', false); // Stop if client disconnects

// Timezone
date_default_timezone_set('Europe/Sofia');

// Error reporting (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database connection with retry logic and timeout settings
function getDbConnection() {
    static $conn = null;
    
    // If connection exists, check if it's still alive
    if ($conn !== null) {
        try {
            $conn->query('SELECT 1');
            return $conn;
        } catch (PDOException $e) {
            // Connection lost, reset and reconnect
            $conn = null;
        }
    }
    
    // Retry connection logic
    $maxRetries = 3;
    $retryDelay = 1;
    $lastException = null;
    
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        try {
            $conn = new PDO(
                "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_TIMEOUT => 10, // Connection timeout
                    PDO::ATTR_PERSISTENT => false, // Don't use persistent connections for better reliability
                ]
            );
            
            // Set additional timeout for queries
            $conn->exec("SET SESSION wait_timeout = 300");
            $conn->exec("SET SESSION interactive_timeout = 300");
            
            return $conn;
            
        } catch (PDOException $e) {
            $lastException = $e;
            
            // If not last attempt, wait before retry
            if ($attempt < $maxRetries) {
                sleep($retryDelay);
                $retryDelay *= 2; // Exponential backoff
            }
        }
    }
    
    // All retries failed
    http_response_code(500);
    echo json_encode([
        'error' => 'Database connection failed after ' . $maxRetries . ' attempts',
        'message' => $lastException ? $lastException->getMessage() : 'Unknown error'
    ]);
    exit;
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
