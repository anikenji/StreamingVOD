<?php
/**
 * M3U8 Playlist Proxy
 * Validates token and returns playlist content with signed segment URLs
 * 
 * GET /api/stream/playlist.php?token=xxx
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/security.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Get and validate token
$token = $_GET['token'] ?? '';

if (empty($token)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Token required']);
    exit;
}

// Decrypt token to get video ID
$videoId = decryptVideoToken($token);

if ($videoId === false) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid or expired token']);
    exit;
}

// Check video exists and is completed
$db = db();
$video = $db->queryOne("SELECT id, status FROM videos WHERE id = ?", [$videoId]);

if (!$video || $video['status'] !== 'completed') {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Video not found']);
    exit;
}

// Read m3u8 file from disk
$playlistPath = HLS_OUTPUT_DIR . '/' . $videoId . '/video.m3u8';

if (!file_exists($playlistPath)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Playlist not found']);
    exit;
}

$content = file_get_contents($playlistPath);

// Rewrite segment URLs to use signed proxy
$baseUrl = BASE_URL . 'api/stream/segment.php';
$content = preg_replace_callback(
    '/^([^#].*\.ts.*)$/m',
    function ($matches) use ($videoId, $baseUrl) {
        $segmentFile = trim($matches[1]);

        // Generate signed URL for segment (4 hours expiry for pause/resume)
        $sig = generateStreamSignature($videoId, $segmentFile, 14400);

        return $baseUrl . '?v=' . urlencode($videoId)
            . '&f=' . urlencode($segmentFile)
            . '&e=' . $sig['expires']
            . '&s=' . $sig['signature'];
    },
    $content
);

// Return modified playlist
header('Content-Type: application/vnd.apple.mpegurl');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Content-Type-Options: nosniff');

echo $content;
