<?php
/**
 * Archive API - Main Entry Point
 * 
 * Endpoints:
 * - POST /api/index.php?action=upload - Upload file
 * - POST /api/index.php?action=create_directory - Create directory
 * - GET /api/index.php?action=list&user_id=X&path=... - List files and directories
 * - GET /api/index.php?action=download&id=X - Download file
 * - DELETE /api/index.php?action=delete_file&id=X - Delete file
 * - DELETE /api/index.php?action=delete_directory&id=X - Delete directory
 * - POST /api/index.php?action=move_file - Move file
 * - POST /api/index.php?action=copy_file - Copy file
 */

require_once __DIR__ . '/config.php';

// Performance headers for large file uploads
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Connection: keep-alive');
header('Keep-Alive: timeout=300, max=1000');
header('X-Accel-Buffering: no'); // Disable nginx buffering for real-time progress

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Get action - handle DELETE requests
$action = $_GET['action'] ?? $_POST['action'] ?? '';
if (empty($action) && $_SERVER['REQUEST_METHOD'] === 'DELETE') {
    parse_str(file_get_contents('php://input'), $deleteParams);
    $action = $_GET['action'] ?? $deleteParams['action'] ?? '';
}

try {
    $db = getDbConnection();
    
    switch ($action) {
        case 'upload':
            handleUpload($db);
            break;
            
        case 'create_directory':
            handleCreateDirectory($db);
            break;
            
        case 'list':
            handleList($db);
            break;
            
        case 'download':
            handleDownload($db);
            break;
            
        case 'delete_file':
            handleDeleteFile($db);
            break;
            
        case 'delete_directory':
            handleDeleteDirectory($db);
            break;
            
        case 'move_file':
            handleMoveFile($db);
            break;
            
        case 'copy_file':
            handleCopyFile($db);
            break;
            
        case 'bulk_delete_files':
            handleBulkDeleteFiles($db);
            break;
            
        case 'bulk_delete_directories':
            handleBulkDeleteDirectories($db);
            break;
            
        case 'get_all_directories':
            handleGetAllDirectories($db);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * Handle file upload
 */
function handleUpload($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }
    
    $user_id = $_POST['user_id'] ?? null;
    $username = $_POST['username'] ?? null;
    $directory_path = $_POST['directory_path'] ?? '';
    
    if (!$user_id || !$username) {
        http_response_code(400);
        echo json_encode(['error' => 'user_id and username are required']);
        return;
    }
    
    if (!isset($_FILES['file'])) {
        http_response_code(400);
        echo json_encode(['error' => 'No file uploaded']);
        return;
    }
    
    $file = $_FILES['file'];
    
    // Handle upload errors with detailed messages
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize directive',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE directive',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'File upload stopped by extension',
        ];
        
        $errorMsg = $errorMessages[$file['error']] ?? 'Unknown upload error';
        http_response_code(400);
        echo json_encode(['error' => $errorMsg, 'error_code' => $file['error']]);
        return;
    }
    
    // Check file size
    if ($file['size'] > MAX_FILE_SIZE) {
        http_response_code(400);
        $maxSizeMB = MAX_FILE_SIZE / (1024 * 1024);
        echo json_encode(['error' => "File size exceeds maximum allowed size of {$maxSizeMB}MB"]);
        return;
    }
    
    // Check if connection is still alive
    if (connection_aborted()) {
        http_response_code(499);
        echo json_encode(['error' => 'Client disconnected during upload']);
        return;
    }
    
    // Normalize username (lowercase)
    $username = strtolower(trim($username));
    
    // Ensure user's main directory exists
    $userMainDir = UPLOAD_DIR . $username . '/';
    if (!file_exists($userMainDir)) {
        mkdir($userMainDir, 0755, true);
    }
    
    // Build full directory path
    $fullDirectoryPath = $userMainDir;
    if (!empty($directory_path)) {
        $directory_path = trim($directory_path, '/');
        $pathParts = explode('/', $directory_path);
        $currentPath = $userMainDir;
        
        foreach ($pathParts as $part) {
            $part = sanitizePath($part);
            if (!empty($part)) {
                $currentPath .= $part . '/';
                if (!file_exists($currentPath)) {
                    mkdir($currentPath, 0755, true);
                }
            }
        }
        
        $fullDirectoryPath = $currentPath;
    }
    
    // Generate unique filename
    $originalName = $file['name'];
    $extension = pathinfo($originalName, PATHINFO_EXTENSION);
    $baseName = pathinfo($originalName, PATHINFO_FILENAME);
    $storedName = $baseName . '_' . time() . '_' . uniqid() . '.' . $extension;
    
    // Move uploaded file with retry logic for connection drops
    $targetPath = $fullDirectoryPath . $storedName;
    $maxRetries = 3;
    $retryDelay = 1; // seconds
    $moved = false;
    
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        // Check connection before each attempt
        if (connection_aborted()) {
            // Clean up temp file if exists
            if (file_exists($file['tmp_name'])) {
                @unlink($file['tmp_name']);
            }
            http_response_code(499);
            echo json_encode(['error' => 'Client disconnected during upload', 'attempt' => $attempt]);
            return;
        }
        
        // Try to move file
        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            $moved = true;
            break;
        }
        
        // If not last attempt, wait before retry
        if ($attempt < $maxRetries) {
            sleep($retryDelay);
            $retryDelay *= 2; // Exponential backoff
        }
    }
    
    if (!$moved) {
        // Check if file was partially written
        if (file_exists($targetPath)) {
            @unlink($targetPath);
        }
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to save file after ' . $maxRetries . ' attempts',
            'check_disk_space' => disk_free_space($fullDirectoryPath)
        ]);
        return;
    }
    
    // Save to database with retry logic
    $relativePath = $username . '/' . ($directory_path ? $directory_path . '/' : '') . $storedName;
    $dbPath = $directory_path;
    
    $maxDbRetries = 3;
    $dbRetryDelay = 1;
    $fileId = null;
    
    for ($dbAttempt = 1; $dbAttempt <= $maxDbRetries; $dbAttempt++) {
        try {
            // Reconnect if connection was lost
            try {
                $db->query('SELECT 1');
            } catch (PDOException $e) {
                $db = getDbConnection();
            }
            
            $stmt = $db->prepare("
                INSERT INTO archive_files 
                (user_id, username, original_name, stored_name, path, directory_path, mime, size)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $user_id,
                $username,
                $originalName,
                $storedName,
                $relativePath,
                $dbPath,
                $file['type'] ?? 'application/octet-stream',
                $file['size']
            ]);
            
            $fileId = $db->lastInsertId();
            break; // Success
            
        } catch (PDOException $e) {
            if ($dbAttempt < $maxDbRetries) {
                sleep($dbRetryDelay);
                $dbRetryDelay *= 2;
                continue;
            }
            
            // Last attempt failed - rollback file
            if (file_exists($targetPath)) {
                @unlink($targetPath);
            }
            http_response_code(500);
            echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
            return;
        }
    }
    
    if (!$fileId) {
        // Rollback file if database insert failed
        if (file_exists($targetPath)) {
            @unlink($targetPath);
        }
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save file record to database']);
        return;
    }
    
    echo json_encode([
        'success' => true,
        'file' => [
            'id' => $fileId,
            'original_name' => $originalName,
            'stored_name' => $storedName,
            'path' => $relativePath,
            'directory_path' => $dbPath,
            'mime' => $file['type'] ?? 'application/octet-stream',
            'size' => $file['size'],
            'created_at' => date('Y-m-d H:i:s')
        ]
    ]);
}

/**
 * Handle directory creation
 */
function handleCreateDirectory($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }
    
    // Handle both form data and JSON
    $user_id = $_POST['user_id'] ?? null;
    $username = $_POST['username'] ?? null;
    $name = $_POST['name'] ?? null;
    $parent_path = $_POST['parent_path'] ?? '';
    
    // If POST is empty, try to parse JSON input
    if (empty($_POST) && !empty(file_get_contents('php://input'))) {
        $input = json_decode(file_get_contents('php://input'), true);
        if ($input) {
            $user_id = $input['user_id'] ?? $user_id;
            $username = $input['username'] ?? $username;
            $name = $input['name'] ?? $name;
            $parent_path = $input['parent_path'] ?? $parent_path;
        }
    }
    
    if (!$user_id || !$username || !$name) {
        http_response_code(400);
        echo json_encode(['error' => 'user_id, username, and name are required', 'debug' => [
            'user_id' => $user_id,
            'username' => $username,
            'name' => $name
        ]]);
        return;
    }
    
    // Normalize username
    $username = strtolower(trim($username));
    $name = sanitizePath(trim($name));
    
    if (empty($name)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid directory name']);
        return;
    }
    
    // Ensure user's main directory exists
    $userMainDir = UPLOAD_DIR . $username . '/';
    if (!file_exists($userMainDir)) {
        if (!mkdir($userMainDir, 0755, true)) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to create user main directory']);
            return;
        }
    }
    
    // Build full path
    $fullPath = $userMainDir;
    $normalizedParentPath = '';
    if (!empty($parent_path)) {
        $parent_path = trim($parent_path, '/');
        $pathParts = explode('/', $parent_path);
        foreach ($pathParts as $part) {
            $part = sanitizePath($part);
            if (!empty($part)) {
                $fullPath .= $part . '/';
                $normalizedParentPath .= ($normalizedParentPath ? '/' : '') . $part;
            }
        }
    }
    
    $fullPath .= $name . '/';
    $dbPath = ($normalizedParentPath ? $normalizedParentPath . '/' : '') . $name;
    
    // Check if directory already exists in database
    $checkStmt = $db->prepare("
        SELECT id FROM archive_directories 
        WHERE user_id = ? AND username = ? AND path = ? AND parent_path = ?
    ");
    $checkStmt->execute([$user_id, $username, $dbPath, $normalizedParentPath]);
    if ($checkStmt->fetch()) {
        http_response_code(400);
        echo json_encode(['error' => 'Directory already exists in database']);
        return;
    }
    
    // Check if directory already exists in filesystem
    if (file_exists($fullPath)) {
        http_response_code(400);
        echo json_encode(['error' => 'Directory already exists in filesystem']);
        return;
    }
    
    // Create directory
    if (!mkdir($fullPath, 0755, true)) {
        http_response_code(500);
        $error = error_get_last();
        echo json_encode(['error' => 'Failed to create directory', 'debug' => [
            'path' => $fullPath,
            'php_error' => $error ? $error['message'] : 'Unknown error'
        ]]);
        return;
    }
    
    // Save to database
    try {
        $stmt = $db->prepare("
            INSERT INTO archive_directories 
            (user_id, username, name, path, parent_path)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $user_id,
            $username,
            $name,
            $dbPath,
            $normalizedParentPath
        ]);
        
        $dirId = $db->lastInsertId();
        
        // Log success for debugging
        error_log("Archive API: Directory created successfully - ID: $dirId, Path: $dbPath, Parent: $normalizedParentPath, FullPath: $fullPath");
        
        echo json_encode([
            'success' => true,
            'directory' => [
                'id' => $dirId,
                'name' => $name,
                'path' => $dbPath,
                'parent_path' => $normalizedParentPath,
                'created_at' => date('Y-m-d H:i:s')
            ]
        ]);
    } catch (PDOException $e) {
        // Rollback: delete directory if database insert fails
        if (file_exists($fullPath)) {
            rmdir($fullPath);
        }
        error_log("Archive API: Database error - " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
}

/**
 * Check if user has admin access (user_id 1 or 13)
 */
function isAdmin($user_id) {
    return in_array((int)$user_id, [1, 13]);
}

/**
 * Handle listing files and directories
 */
function handleList($db) {
    $user_id = $_GET['user_id'] ?? null;
    $username = $_GET['username'] ?? null;
    $path = $_GET['path'] ?? '';
    $requested_user_id = $_GET['requested_user_id'] ?? $user_id; // For admin viewing other users
    
    if (!$user_id || !$username) {
        http_response_code(400);
        echo json_encode(['error' => 'user_id and username are required']);
        return;
    }
    
    // Normalize username
    $username = strtolower(trim($username));
    $path = trim($path, '/');
    
    $isAdminUser = isAdmin($user_id);
    
    // If not admin, can only view own files
    if (!$isAdminUser) {
        $requested_user_id = $user_id;
    }
    
    // Get requested user's username if admin is viewing another user
    $targetUsername = $username;
    if ($isAdminUser && $requested_user_id != $user_id) {
        $userStmt = $db->prepare("SELECT username FROM archive_files WHERE user_id = ? LIMIT 1");
        $userStmt->execute([$requested_user_id]);
        $userData = $userStmt->fetch();
        if ($userData) {
            $targetUsername = strtolower($userData['username']);
        } else {
            // Try directories
            $userStmt = $db->prepare("SELECT username FROM archive_directories WHERE user_id = ? LIMIT 1");
            $userStmt->execute([$requested_user_id]);
            $userData = $userStmt->fetch();
            if ($userData) {
                $targetUsername = strtolower($userData['username']);
            }
        }
    }
    
    // Get directories
    $dirQuery = "SELECT * FROM archive_directories WHERE user_id = ? AND username = ?";
    $dirParams = [$requested_user_id, $targetUsername];
    
    if (empty($path)) {
        $dirQuery .= " AND (parent_path = '' OR parent_path IS NULL)";
    } else {
        $dirQuery .= " AND parent_path = ?";
        $dirParams[] = $path;
    }
    
    $dirStmt = $db->prepare($dirQuery);
    $dirStmt->execute($dirParams);
    $directories = $dirStmt->fetchAll();
    
    // Get files
    $fileQuery = "SELECT * FROM archive_files WHERE user_id = ? AND username = ?";
    $fileParams = [$requested_user_id, $targetUsername];
    
    if (empty($path)) {
        $fileQuery .= " AND (directory_path = '' OR directory_path IS NULL)";
    } else {
        $fileQuery .= " AND directory_path = ?";
        $fileParams[] = $path;
    }
    
    $fileStmt = $db->prepare($fileQuery);
    $fileStmt->execute($fileParams);
    $files = $fileStmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'path' => $path,
        'directories' => $directories,
        'files' => $files,
        'is_admin' => $isAdminUser
    ]);
}

/**
 * Get all directories from all users (for admin)
 */
function handleGetAllDirectories($db) {
    $user_id = $_GET['user_id'] ?? null;
    
    if (!$user_id) {
        http_response_code(400);
        echo json_encode(['error' => 'user_id is required']);
        return;
    }
    
    if (!isAdmin($user_id)) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        return;
    }
    
    // Get all directories from all users
    $stmt = $db->prepare("SELECT * FROM archive_directories ORDER BY username, path");
    $stmt->execute();
    $allDirectories = $stmt->fetchAll();
    
    // Build tree structure
    $tree = [];
    foreach ($allDirectories as $dir) {
        $displayName = $dir['name'];
        if (empty($dir['parent_path'])) {
            $displayName = '[' . $dir['username'] . '] ' . $displayName;
        }
        
        $tree[] = [
            'id' => $dir['id'],
            'name' => $dir['name'],
            'path' => $dir['path'],
            'parent_path' => $dir['parent_path'],
            'username' => $dir['username'],
            'user_id' => $dir['user_id'],
            'display_name' => $displayName,
        ];
    }
    
    echo json_encode([
        'success' => true,
        'directories' => $tree
    ]);
}

/**
 * Handle file download
 */
function handleDownload($db) {
    $id = $_GET['id'] ?? null;
    
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'File ID is required']);
        return;
    }
    
    $stmt = $db->prepare("SELECT * FROM archive_files WHERE id = ?");
    $stmt->execute([$id]);
    $file = $stmt->fetch();
    
    if (!$file) {
        http_response_code(404);
        echo json_encode(['error' => 'File not found']);
        return;
    }
    
    $filePath = UPLOAD_DIR . $file['path'];
    
    if (!file_exists($filePath)) {
        http_response_code(404);
        echo json_encode(['error' => 'File not found on disk']);
        return;
    }
    
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . addslashes($file['original_name']) . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    
    readfile($filePath);
    exit;
}

/**
 * Handle file deletion
 */
function handleDeleteFile($db) {
    // Accept both POST and DELETE methods
    if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'DELETE'])) {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }
    
    // Get ID from GET, POST, or parsed input
    $id = $_GET['id'] ?? $_POST['id'] ?? null;
    if (!$id && $_SERVER['REQUEST_METHOD'] === 'DELETE') {
        parse_str(file_get_contents('php://input'), $deleteParams);
        $id = $deleteParams['id'] ?? null;
    }
    
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'File ID is required']);
        return;
    }
    
    $stmt = $db->prepare("SELECT * FROM archive_files WHERE id = ?");
    $stmt->execute([$id]);
    $file = $stmt->fetch();
    
    if (!$file) {
        http_response_code(404);
        echo json_encode(['error' => 'File not found']);
        return;
    }
    
    $filePath = UPLOAD_DIR . $file['path'];
    
    // Delete file from disk
    if (file_exists($filePath)) {
        unlink($filePath);
    }
    
    // Delete from database
    $deleteStmt = $db->prepare("DELETE FROM archive_files WHERE id = ?");
    $deleteStmt->execute([$id]);
    
    echo json_encode(['success' => true, 'message' => 'File deleted successfully']);
}

/**
 * Handle directory deletion
 */
function handleDeleteDirectory($db) {
    // Accept both POST and DELETE methods
    if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'DELETE'])) {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }
    
    // Get ID from GET, POST, or parsed input
    $id = $_GET['id'] ?? $_POST['id'] ?? null;
    if (!$id && $_SERVER['REQUEST_METHOD'] === 'DELETE') {
        parse_str(file_get_contents('php://input'), $deleteParams);
        $id = $deleteParams['id'] ?? null;
    }
    
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'Directory ID is required']);
        return;
    }
    
    $stmt = $db->prepare("SELECT * FROM archive_directories WHERE id = ?");
    $stmt->execute([$id]);
    $directory = $stmt->fetch();
    
    if (!$directory) {
        http_response_code(404);
        echo json_encode(['error' => 'Directory not found']);
        return;
    }
    
    // Check if directory has files or subdirectories
    $fileCheck = $db->prepare("SELECT COUNT(*) as count FROM archive_files WHERE user_id = ? AND username = ? AND directory_path LIKE ?");
    $fileCheck->execute([$directory['user_id'], $directory['username'], $directory['path'] . '%']);
    $fileCount = $fileCheck->fetch()['count'];
    
    $dirCheck = $db->prepare("SELECT COUNT(*) as count FROM archive_directories WHERE user_id = ? AND username = ? AND parent_path LIKE ?");
    $dirCheck->execute([$directory['user_id'], $directory['username'], $directory['path'] . '%']);
    $dirCount = $dirCheck->fetch()['count'];
    
    if ($fileCount > 0 || $dirCount > 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Directory is not empty']);
        return;
    }
    
    // Delete directory from disk
    $username = strtolower($directory['username']);
    $dirPath = UPLOAD_DIR . $username . '/' . $directory['path'];
    if (file_exists($dirPath)) {
        rmdir($dirPath);
    }
    
    // Delete from database
    $deleteStmt = $db->prepare("DELETE FROM archive_directories WHERE id = ?");
    $deleteStmt->execute([$id]);
    
    echo json_encode(['success' => true, 'message' => 'Directory deleted successfully']);
}

/**
 * Handle file move
 */
function handleMoveFile($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }
    
    $user_id = $_POST['user_id'] ?? null;
    $file_id = $_POST['file_id'] ?? null;
    $target_directory_path = $_POST['target_directory_path'] ?? '';
    
    if (!$user_id || !$file_id) {
        http_response_code(400);
        echo json_encode(['error' => 'user_id and file_id are required']);
        return;
    }
    
    // Get file info
    $stmt = $db->prepare("SELECT * FROM archive_files WHERE id = ?");
    $stmt->execute([$file_id]);
    $file = $stmt->fetch();
    
    if (!$file) {
        http_response_code(404);
        echo json_encode(['error' => 'File not found']);
        return;
    }
    
    // Check permissions: user can only move own files, unless admin
    if (!isAdmin($user_id) && (int)$file['user_id'] !== (int)$user_id) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        return;
    }
    
    $username = strtolower(trim($file['username']));
    $target_directory_path = trim($target_directory_path, '/');
    
    // Build old and new paths
    $oldFullPath = UPLOAD_DIR . $file['path'];
    $userMainDir = UPLOAD_DIR . $username . '/';
    
    $newDirectoryPath = $userMainDir;
    if (!empty($target_directory_path)) {
        $pathParts = explode('/', $target_directory_path);
        foreach ($pathParts as $part) {
            $part = sanitizePath($part);
            if (!empty($part)) {
                $newDirectoryPath .= $part . '/';
            }
        }
    }
    
    $newFullPath = $newDirectoryPath . $file['stored_name'];
    $newRelativePath = $username . '/' . ($target_directory_path ? $target_directory_path . '/' : '') . $file['stored_name'];
    
    // Check if target file already exists
    if (file_exists($newFullPath)) {
        http_response_code(400);
        echo json_encode(['error' => 'File with same name already exists in target directory']);
        return;
    }
    
    // Ensure target directory exists
    if (!file_exists($newDirectoryPath)) {
        if (!mkdir($newDirectoryPath, 0755, true)) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to create target directory']);
            return;
        }
    }
    
    // Move file
    if (!rename($oldFullPath, $newFullPath)) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to move file']);
        return;
    }
    
    // Update database
    try {
        $updateStmt = $db->prepare("
            UPDATE archive_files 
            SET path = ?, directory_path = ?
            WHERE id = ?
        ");
        $updateStmt->execute([
            $newRelativePath,
            $target_directory_path,
            $file_id
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'File moved successfully',
            'file' => [
                'id' => $file['id'],
                'path' => $newRelativePath,
                'directory_path' => $target_directory_path
            ]
        ]);
    } catch (PDOException $e) {
        // Rollback: move file back
        rename($newFullPath, $oldFullPath);
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
}

/**
 * Handle file copy
 */
function handleCopyFile($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }
    
    $user_id = $_POST['user_id'] ?? null;
    $file_id = $_POST['file_id'] ?? null;
    $target_directory_path = $_POST['target_directory_path'] ?? '';
    
    if (!$user_id || !$file_id) {
        http_response_code(400);
        echo json_encode(['error' => 'user_id and file_id are required']);
        return;
    }
    
    // Get file info
    $stmt = $db->prepare("SELECT * FROM archive_files WHERE id = ?");
    $stmt->execute([$file_id]);
    $file = $stmt->fetch();
    
    if (!$file) {
        http_response_code(404);
        echo json_encode(['error' => 'File not found']);
        return;
    }
    
    // Check permissions: user can only copy own files, unless admin
    if (!isAdmin($user_id) && (int)$file['user_id'] !== (int)$user_id) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        return;
    }
    
    $username = strtolower(trim($file['username']));
    $target_directory_path = trim($target_directory_path, '/');
    
    // Build source and target paths
    $sourceFullPath = UPLOAD_DIR . $file['path'];
    
    if (!file_exists($sourceFullPath)) {
        http_response_code(404);
        echo json_encode(['error' => 'Source file not found on disk']);
        return;
    }
    
    $userMainDir = UPLOAD_DIR . $username . '/';
    
    $newDirectoryPath = $userMainDir;
    if (!empty($target_directory_path)) {
        $pathParts = explode('/', $target_directory_path);
        foreach ($pathParts as $part) {
            $part = sanitizePath($part);
            if (!empty($part)) {
                $newDirectoryPath .= $part . '/';
            }
        }
    }
    
    // Generate unique filename for the copy
    $originalName = $file['original_name'];
    $extension = pathinfo($originalName, PATHINFO_EXTENSION);
    $baseName = pathinfo($originalName, PATHINFO_FILENAME);
    $storedName = $baseName . '_' . time() . '_' . uniqid() . '.' . $extension;
    
    $newFullPath = $newDirectoryPath . $storedName;
    $newRelativePath = $username . '/' . ($target_directory_path ? $target_directory_path . '/' : '') . $storedName;
    
    // Ensure target directory exists
    if (!file_exists($newDirectoryPath)) {
        if (!mkdir($newDirectoryPath, 0755, true)) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to create target directory']);
            return;
        }
    }
    
    // Copy file
    if (!copy($sourceFullPath, $newFullPath)) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to copy file']);
        return;
    }
    
    // Create new database entry
    try {
        $insertStmt = $db->prepare("
            INSERT INTO archive_files 
            (user_id, username, original_name, stored_name, path, directory_path, mime, size)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insertStmt->execute([
            $file['user_id'],
            $file['username'],
            $originalName,
            $storedName,
            $newRelativePath,
            $target_directory_path,
            $file['mime'] ?? 'application/octet-stream',
            $file['size']
        ]);
        
        $newFileId = $db->lastInsertId();
        
        echo json_encode([
            'success' => true,
            'message' => 'File copied successfully',
            'file' => [
                'id' => $newFileId,
                'original_name' => $originalName,
                'stored_name' => $storedName,
                'path' => $newRelativePath,
                'directory_path' => $target_directory_path,
                'mime' => $file['mime'] ?? 'application/octet-stream',
                'size' => $file['size'],
                'created_at' => date('Y-m-d H:i:s')
            ]
        ]);
    } catch (PDOException $e) {
        // Rollback: delete copied file
        if (file_exists($newFullPath)) {
            unlink($newFullPath);
        }
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
}

/**
 * Handle bulk file deletion
 */
function handleBulkDeleteFiles($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }
    
    $user_id = $_POST['user_id'] ?? null;
    $file_ids = $_POST['file_ids'] ?? null;
    
    if (!$user_id) {
        http_response_code(400);
        echo json_encode(['error' => 'user_id is required']);
        return;
    }
    
    if (!$file_ids || !is_array($file_ids) || empty($file_ids)) {
        http_response_code(400);
        echo json_encode(['error' => 'file_ids array is required']);
        return;
    }
    
    $isAdminUser = isAdmin($user_id);
    
    // Get all files
    $placeholders = str_repeat('?,', count($file_ids) - 1) . '?';
    $stmt = $db->prepare("SELECT * FROM archive_files WHERE id IN ($placeholders)");
    $stmt->execute($file_ids);
    $files = $stmt->fetchAll();
    
    if (empty($files)) {
        http_response_code(404);
        echo json_encode(['error' => 'No files found']);
        return;
    }
    
    $deleted = 0;
    $errors = [];
    
    foreach ($files as $file) {
        // Check permissions: user can only delete own files, unless admin
        if (!$isAdminUser && (int)$file['user_id'] !== (int)$user_id) {
            $errors[] = "Access denied for file: {$file['original_name']}";
            continue;
        }
        $filePath = UPLOAD_DIR . $file['path'];
        
        // Delete file from disk
        if (file_exists($filePath)) {
            if (!unlink($filePath)) {
                $errors[] = "Failed to delete file: {$file['original_name']}";
                continue;
            }
        }
        
        // Delete from database
        $deleteStmt = $db->prepare("DELETE FROM archive_files WHERE id = ?");
        $deleteStmt->execute([$file['id']]);
        $deleted++;
    }
    
    echo json_encode([
        'success' => true,
        'deleted' => $deleted,
        'total' => count($files),
        'errors' => $errors
    ]);
}

/**
 * Handle bulk directory deletion
 */
function handleBulkDeleteDirectories($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }
    
    $user_id = $_POST['user_id'] ?? null;
    $directory_ids = $_POST['directory_ids'] ?? null;
    
    if (!$user_id) {
        http_response_code(400);
        echo json_encode(['error' => 'user_id is required']);
        return;
    }
    
    if (!$directory_ids || !is_array($directory_ids) || empty($directory_ids)) {
        http_response_code(400);
        echo json_encode(['error' => 'directory_ids array is required']);
        return;
    }
    
    $isAdminUser = isAdmin($user_id);
    
    // Get all directories
    $placeholders = str_repeat('?,', count($directory_ids) - 1) . '?';
    $stmt = $db->prepare("SELECT * FROM archive_directories WHERE id IN ($placeholders)");
    $stmt->execute($directory_ids);
    $directories = $stmt->fetchAll();
    
    if (empty($directories)) {
        http_response_code(404);
        echo json_encode(['error' => 'No directories found']);
        return;
    }
    
    $deleted = 0;
    $errors = [];
    
    foreach ($directories as $directory) {
        // Check permissions: user can only delete own directories, unless admin
        if (!$isAdminUser && (int)$directory['user_id'] !== (int)$user_id) {
            $errors[] = "Access denied for directory: {$directory['name']}";
            continue;
        }
        // Check if directory has files or subdirectories
        $fileCheck = $db->prepare("SELECT COUNT(*) as count FROM archive_files WHERE user_id = ? AND username = ? AND directory_path LIKE ?");
        $fileCheck->execute([$directory['user_id'], $directory['username'], $directory['path'] . '%']);
        $fileCount = $fileCheck->fetch()['count'];
        
        $dirCheck = $db->prepare("SELECT COUNT(*) as count FROM archive_directories WHERE user_id = ? AND username = ? AND parent_path LIKE ?");
        $dirCheck->execute([$directory['user_id'], $directory['username'], $directory['path'] . '%']);
        $dirCount = $dirCheck->fetch()['count'];
        
        if ($fileCount > 0 || $dirCount > 0) {
            $errors[] = "Directory '{$directory['name']}' is not empty";
            continue;
        }
        
        // Delete directory from disk
        $username = strtolower($directory['username']);
        $dirPath = UPLOAD_DIR . $username . '/' . $directory['path'];
        if (file_exists($dirPath)) {
            if (!rmdir($dirPath)) {
                $errors[] = "Failed to delete directory: {$directory['name']}";
                continue;
            }
        }
        
        // Delete from database
        $deleteStmt = $db->prepare("DELETE FROM archive_directories WHERE id = ?");
        $deleteStmt->execute([$directory['id']]);
        $deleted++;
    }
    
    echo json_encode([
        'success' => true,
        'deleted' => $deleted,
        'total' => count($directories),
        'errors' => $errors
    ]);
}

/**
 * Sanitize path component
 * Allows letters (including Cyrillic), numbers, underscores, and hyphens
 */
function sanitizePath($path) {
    // Remove dangerous characters but keep Unicode letters (including Cyrillic)
    // Allow: letters (Unicode), numbers, underscores, hyphens
    $path = preg_replace('/[^\p{L}\p{N}_-]/u', '', $path);
    return $path;
}
