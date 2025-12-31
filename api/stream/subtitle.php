<?php
/**
 * Subtitle Proxy
 * Serves subtitle files from secure storage (HLS_OUTPUT_DIR)
 * 
 * GET /api/stream/subtitle.php?token=xxx&id=123
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/security.php';

// Strict CORS
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = defined('ALLOWED_CORS_ORIGINS') ? ALLOWED_CORS_ORIGINS : [];

$originAllowed = false;
foreach ($allowedOrigins as $allowed) {
    if (strcasecmp($origin, $allowed) === 0) {
        $originAllowed = true;
        break;
    }
}

if ($originAllowed && !empty($origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Vary: Origin');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

$token = $_GET['token'] ?? '';
$subtitleId = $_GET['id'] ?? '';

if (empty($token) || empty($subtitleId)) {
    http_response_code(400);
    die('Missing parameters');
}

// Validate token
$videoId = decryptVideoToken($token);
if (!$videoId) {
    http_response_code(403);
    die('Invalid token');
}

$db = Database::getInstance();

// Get subtitle info
$sql = "SELECT * FROM subtitles WHERE id = ? AND video_id = ?";
$sub = $db->queryOne($sql, [$subtitleId, $videoId]);

if (!$sub) {
    http_response_code(404);
    die('Subtitle not found');
}

// Determine file path
// If stored path starts with 'storage/hls', strip it relative to HLS_OUTPUT_DIR if needed
// But since upload.php hardcoded 'storage/hls/...', and HLS_OUTPUT_DIR is E:/movie/hls
// We need to resolve where the file actually is.

// Current DB state: 'storage/hls/{videoId}/subs/...'
// Actual file location: 'E:/movie/hls/{videoId}/subs/...'
// We need to extract {videoId}/subs/... from the DB path

$storedPath = $sub['file_path'];
// Remove 'storage/hls/' prefix if present (flexible handling)
$relativePath = str_replace('storage/hls/', '', $storedPath);
// Or strictly: if we know format is storage/hls/ID/subs/file
// We can just use the ID from token to reconstruct path

$actualPath = HLS_OUTPUT_DIR . '/' . $videoId . '/subs/' . basename($storedPath);

if (!file_exists($actualPath)) {
    // Try fallback if my path logic is wrong
    // Maybe DB uses forward slashes but OS is Windows?
    $actualPath = str_replace('/', DIRECTORY_SEPARATOR, $actualPath);

    if (!file_exists($actualPath)) {
        http_response_code(404);
        die('File not found on server');
    }
}

// Serve file
header('Content-Type: text/vtt');
header('Content-Length: ' . filesize($actualPath));
header('Cache-Control: public, max-age=3600');
readfile($actualPath);
exit;
