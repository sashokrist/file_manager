-- Archive API Database Schema
-- Create this database before using the API

CREATE DATABASE IF NOT EXISTS archive_api CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE archive_api;

-- Files table
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Directories table
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
