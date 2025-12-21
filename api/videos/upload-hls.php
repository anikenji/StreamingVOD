<?php
/**
 * Upload HLS M3U8 File API
 * For uploading pre-encoded m3u8 files (segments on external host)
 * POST /api/videos/upload-hls.php
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

enableCORS();

// Require authentication
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Method not allowed', 405);
}

$userId = getCurrentUserId();

// Get parameters
$title = trim($_POST['title'] ?? '');
$movieId = isset($_POST['movie_id']) ? intval($_POST['movie_id']) : null;
$episodeNumber = isset($_POST['episode_number']) ? intval($_POST['episode_number']) : null;

// Check for m3u8 file upload
if (!isset($_FILES['m3u8']) || $_FILES['m3u8']['error'] !== UPLOAD_ERR_OK) {
    errorResponse('No m3u8 file uploaded');
}

$file = $_FILES['m3u8'];
$filename = $file['name'];
$extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

// Validate file type
if ($extension !== 'm3u8') {
    errorResponse('Only .m3u8 files are allowed');
}

// Validate m3u8 content
$content = file_get_contents($file['tmp_name']);
if (strpos($content, '#EXTM3U') === false) {
    errorResponse('Invalid m3u8 file format');
}

// Generate video ID
$videoId = generateUUID();

// Create output directory
$outputDir = HLS_OUTPUT_DIR . '/' . $videoId;
ensureDirectory($outputDir);

// Save m3u8 file
$m3u8Path = $outputDir . '/video.m3u8';
if (!move_uploaded_file($file['tmp_name'], $m3u8Path)) {
    errorResponse('Failed to save m3u8 file', 500);
}

// Generate embed URL and code (rtrim to avoid double slash)
$baseUrl = rtrim(BASE_URL, '/');
$hlsBaseUrl = rtrim(HLS_BASE_URL, '/');
$embedUrl = $baseUrl . '/embed/' . $videoId;
$embedCode = sprintf(
    '<iframe src="%s" width="640" height="360" frameborder="0" allowfullscreen allow="autoplay"></iframe>',
    htmlspecialchars($embedUrl)
);
$m3u8Url = $hlsBaseUrl . '/' . $videoId . '/video.m3u8';

// Determine title
if (empty($title)) {
    $title = pathinfo($filename, PATHINFO_FILENAME);
}

// Create video record (already completed - no encoding needed)
$db = db();

try {
    // original_path and file_size are NOT NULL, use m3u8 file info
    $fileSize = filesize($m3u8Path) ?: 0;

    $sql = "INSERT INTO videos (id, user_id, movie_id, episode_number, original_filename, original_path, file_size, master_playlist_path, embed_url, embed_code, status, created_at, completed_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', NOW(), NOW())";

    $result = $db->execute($sql, [
        $videoId,
        $userId,
        $movieId,
        $episodeNumber,
        $title,
        $m3u8Path,      // original_path = m3u8 path
        $fileSize,      // file_size = m3u8 file size
        $m3u8Path,      // master_playlist_path
        $embedUrl,
        $embedCode
    ]);

    if ($result === false) {
        logMessage("HLS upload DB insert failed for video: $videoId", 'ERROR');
        errorResponse('Database insert failed', 500);
    }
} catch (Exception $e) {
    logMessage("HLS upload exception: " . $e->getMessage(), 'ERROR');
    errorResponse('Database error: ' . $e->getMessage(), 500);
}

logMessage("HLS uploaded: $videoId - $title (User: $userId)", 'INFO');

successResponse([
    'video_id' => $videoId,
    'title' => $title,
    'embed_url' => $embedUrl,
    'embed_code' => $embedCode,
    'm3u8_url' => $m3u8Url
], 'HLS file uploaded successfully');
