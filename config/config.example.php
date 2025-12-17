<?php
/**
 * HLS Streaming Service - Configuration Template
 * Copy this file to config.php and update with your settings
 */

// ============================================
// Database Configuration
// ============================================
define('DB_HOST', 'localhost');
define('DB_NAME', 'hls_streaming');
define('DB_USER', 'root');
define('DB_PASS', ''); // Update with your password
define('DB_CHARSET', 'utf8mb4');

// ============================================
// Storage Paths (E: Drive)
// ============================================
define('UPLOAD_DIR', 'E:/videos/uploads');
define('HLS_OUTPUT_DIR', 'E:/videos/hls');
define('THUMBNAIL_DIR', 'E:/videos/thumbnails');
define('TEMP_DIR', 'E:/videos/temp');

// ============================================
// FFmpeg Configuration
// ============================================
define('FFMPEG_PATH', 'C:/ffmpeg/bin/ffmpeg.exe'); // Update with your path
define('FFPROBE_PATH', 'C:/ffmpeg/bin/ffprobe.exe'); // Update with your path

// ============================================
// Server Configuration
// ============================================
define('BASE_URL', 'http://localhost'); // Update for production
define('API_BASE', BASE_URL . '/api');
define('HLS_BASE_URL', BASE_URL . '/videos/hls');
define('THUMBNAIL_BASE_URL', BASE_URL . '/videos/thumbnails');

// ============================================
// JWPlayer Configuration
// ============================================
define('JWPLAYER_KEY', 'YOUR_JWPLAYER_LICENSE_KEY'); // Replace with your actual key
define('JWPLAYER_CDN', 'https://cdn.jwplayer.com/libraries/YOUR_KEY.js');

// ============================================
// Upload Configuration
// ============================================
define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024 * 1024); // 5GB in bytes
define('ALLOWED_EXTENSIONS', ['mp4', 'mkv', 'avi', 'mov', 'webm', 'flv']);
define('CHUNK_SIZE', 5 * 1024 * 1024); // 5MB chunks

// ============================================
// Session Configuration
// ============================================
define('SESSION_LIFETIME', 86400); // 24 hours in seconds
define('SESSION_NAME', 'HLS_SESSION');

// ============================================
// Encoding Configuration
// ============================================
// Single quality encoding - maintain source resolution
define('ENCODE_QUALITY', 'source'); // Use 'source' to maintain original resolution

// Encoding Profile (based on original convert.bat)
define('ENCODING_PROFILE', [
    'video_bitrate' => '4000k',
    'max_bitrate' => '4500k',
    'buffer_size' => '8000k',
    'audio_bitrate' => '192k',
    'preset' => 'fast',
]);

// HLS Segment Configuration
define('HLS_SEGMENT_DURATION', 2); // seconds
define('HLS_PLAYLIST_TYPE', 'vod'); // vod or event

// ============================================
// Application Settings
// ============================================
define('APP_NAME', 'HLS Streaming Service');
define('APP_VERSION', '1.0.0');
define('TIMEZONE', 'Asia/Ho_Chi_Minh');

// Set timezone
date_default_timezone_set(TIMEZONE);

// ============================================
// Error Reporting (Development)
// ============================================
// Set to 0 for production
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ============================================
// CORS Configuration (if needed)
// ============================================
define('ENABLE_CORS', true);
define('CORS_ORIGIN', '*'); // Change to specific domain in production

// ============================================
// Worker Configuration
// ============================================
define('WORKER_INTERVAL', 5); // seconds between worker checks
define('MAX_CONCURRENT_ENCODES', 3); // max simultaneous encoding jobs

// ============================================
// Helper Functions
// ============================================

/**
 * Get encoding profile
 */
function getEncodingProfile() {
    return ENCODING_PROFILE;
}

/**
 * Get encoding quality level
 */
function getEncodingQuality() {
    return ENCODE_QUALITY;
}

/**
 * Format bytes to human readable size
 */
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Format duration from seconds to HH:MM:SS
 */
function formatDuration($seconds) {
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $seconds = $seconds % 60;
    return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
}
