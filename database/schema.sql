-- HLS Streaming Service Database Schema
-- Database: hls_streaming
-- Created: 2025-12-18

-- Create database
CREATE DATABASE IF NOT EXISTS hls_streaming CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE hls_streaming;

-- ============================================
-- Users Table
-- ============================================
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'user') DEFAULT 'user',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    INDEX idx_username (username),
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Videos Table
-- ============================================
CREATE TABLE IF NOT EXISTS videos (
    id VARCHAR(36) PRIMARY KEY,
    user_id INT NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    original_path VARCHAR(512) NOT NULL,
    file_size BIGINT NOT NULL,
    duration DECIMAL(10,2) DEFAULT NULL,
    
    -- Status tracking
    status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
    
    -- HLS outputs (adaptive bitrate paths)
    hls_360p_path VARCHAR(512) DEFAULT NULL,
    hls_720p_path VARCHAR(512) DEFAULT NULL,
    hls_1080p_path VARCHAR(512) DEFAULT NULL,
    master_playlist_path VARCHAR(512) DEFAULT NULL,
    
    -- Embed & URLs
    embed_code TEXT DEFAULT NULL,
    embed_url VARCHAR(512) DEFAULT NULL,
    
    -- Metadata
    thumbnail_path VARCHAR(512) DEFAULT NULL,
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Encoding Jobs Table (tracks individual quality encodes)
-- ============================================
CREATE TABLE IF NOT EXISTS encoding_jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    video_id VARCHAR(36) NOT NULL,
    quality ENUM('360p', '720p', '1080p') NOT NULL,
    status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
    
    -- Progress tracking
    progress DECIMAL(5,2) DEFAULT 0.00,
    current_frame INT DEFAULT 0,
    total_frames INT DEFAULT 0,
    fps DECIMAL(6,2) DEFAULT 0.00,
    bitrate VARCHAR(20) DEFAULT NULL,
    estimated_time_remaining INT DEFAULT NULL,
    
    -- Output path
    output_path VARCHAR(512) DEFAULT NULL,
    
    -- Error handling
    error_message TEXT DEFAULT NULL,
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    
    FOREIGN KEY (video_id) REFERENCES videos(id) ON DELETE CASCADE,
    INDEX idx_video_id (video_id),
    INDEX idx_status (status),
    INDEX idx_quality (quality),
    UNIQUE KEY unique_video_quality (video_id, quality)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Sessions Table (optional, for better session management)
-- ============================================
CREATE TABLE IF NOT EXISTS sessions (
    id VARCHAR(128) PRIMARY KEY,
    user_id INT NOT NULL,
    data TEXT,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_last_activity (last_activity),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Insert default admin user (password: admin123)
-- ============================================
INSERT INTO users (username, email, password_hash, role) VALUES 
('admin', 'admin@localhost', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin');

-- Note: Password is 'admin123' (hashed with bcrypt)
-- Please change this password after first login!
