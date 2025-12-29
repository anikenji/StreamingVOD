<?php
/**
 * Helper Functions
 * Utility functions used throughout the application
 */

require_once __DIR__ . '/../config/config.php';

/**
 * Generate UUID v4
 */
function generateUUID()
{
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff)
    );
}

/**
 * Validate video file extension
 */
function validateVideoFile($filename)
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, ALLOWED_EXTENSIONS);
}

/**
 * Get file extension from filename
 */
function getFileExtension($filename)
{
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
}

/**
 * Sanitize filename
 */
function sanitizeFilename($filename)
{
    // Remove path info and special characters
    $filename = basename($filename);
    $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
    return $filename;
}

/**
 * Get video duration using ffprobe
 */
function getVideoDuration($filePath)
{
    if (!file_exists($filePath)) {
        return null;
    }

    $cmd = sprintf(
        '"%s" -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 "%s"',
        FFPROBE_PATH,
        $filePath
    );

    $output = shell_exec($cmd);
    return $output ? floatval(trim($output)) : null;
}

/**
 * Get video resolution using ffprobe
 */
function getVideoResolution($filePath)
{
    if (!file_exists($filePath)) {
        return null;
    }

    $cmd = sprintf(
        '"%s" -v error -select_streams v:0 -show_entries stream=width,height -of csv=s=x:p=0 "%s"',
        FFPROBE_PATH,
        $filePath
    );

    $output = shell_exec($cmd);
    if ($output) {
        list($width, $height) = explode('x', trim($output));
        return ['width' => intval($width), 'height' => intval($height)];
    }
    return null;
}

/**
 * Generate thumbnail from video
 */
function generateThumbnail($videoPath, $outputPath, $timeOffset = 5)
{
    if (!file_exists($videoPath)) {
        return false;
    }

    $outputDir = dirname($outputPath);
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0777, true);
    }

    $cmd = sprintf(
        '"%s" -ss %d -i "%s" -vframes 1 -q:v 2 "%s" 2>&1',
        FFMPEG_PATH,
        $timeOffset,
        $videoPath,
        $outputPath
    );

    exec($cmd, $output, $returnCode);
    return $returnCode === 0 && file_exists($outputPath);
}

/**
 * Send JSON response
 */
function jsonResponse($data, $statusCode = 200)
{
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Send error response
 */
function errorResponse($message, $statusCode = 400)
{
    jsonResponse([
        'success' => false,
        'error' => $message
    ], $statusCode);
}

/**
 * Send success response
 */
function successResponse($data = [], $message = '')
{
    $response = ['success' => true];
    if ($message) {
        $response['message'] = $message;
    }
    if (!empty($data)) {
        $response = array_merge($response, $data);
    }
    jsonResponse($response);
}

/**
 * Enable CORS if configured
 * Uses ALLOWED_CORS_ORIGINS for strict origin validation
 */
function enableCORS()
{
    if (!ENABLE_CORS) {
        return;
    }

    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowedOrigins = defined('ALLOWED_CORS_ORIGINS') ? ALLOWED_CORS_ORIGINS : [];

    // Validate origin against whitelist
    $originAllowed = false;
    foreach ($allowedOrigins as $allowed) {
        if (strcasecmp($origin, $allowed) === 0) {
            $originAllowed = true;
            break;
        }
    }

    if ($originAllowed && !empty($origin)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Access-Control-Allow-Credentials: true');
        header('Vary: Origin');
    }

    // Handle preflight requests
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        if ($originAllowed && !empty($origin)) {
            http_response_code(200);
        } else {
            http_response_code(403);
        }
        exit;
    }
}

/**
 * Validate email format
 */
function validateEmail($email)
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate username (alphanumeric and underscore only)
 */
function validateUsername($username)
{
    return preg_match('/^[a-zA-Z0-9_]{3,50}$/', $username);
}

/**
 * Validate password strength
 */
function validatePassword($password)
{
    // Minimum 6 characters
    return strlen($password) >= 6;
}

/**
 * Create directory if not exists
 */
function ensureDirectory($path)
{
    if (!is_dir($path)) {
        mkdir($path, 0777, true);
    }
    return is_dir($path);
}

/**
 * Delete directory recursively
 */
function deleteDirectory($dir)
{
    if (!is_dir($dir)) {
        return false;
    }

    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $path = $dir . DIRECTORY_SEPARATOR . $file;
        is_dir($path) ? deleteDirectory($path) : unlink($path);
    }

    return rmdir($dir);
}

/**
 * Get file size in bytes
 */
function getFileSize($path)
{
    return file_exists($path) ? filesize($path) : 0;
}

/**
 * Log message to file
 */
function logMessage($message, $type = 'INFO')
{
    $logFile = __DIR__ . '/../logs/app.log';
    $logDir = dirname($logFile);

    if (!is_dir($logDir)) {
        mkdir($logDir, 0777, true);
    }

    $timestamp = date('Y-m-d H:i:s');
    $logEntry = sprintf("[%s] [%s] %s\n", $timestamp, $type, $message);

    file_put_contents($logFile, $logEntry, FILE_APPEND);
}
