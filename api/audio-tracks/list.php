<?php
/**
 * Audio Track List API
 * Returns all audio tracks for a video
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/security.php';

header('Content-Type: application/json');

try {
    requireAuth();

    $videoId = $_GET['video_id'] ?? '';

    if (empty($videoId)) {
        throw new Exception('Missing video_id');
    }

    $db = Database::getInstance();

    // Get audio tracks
    $sql = "SELECT * FROM audio_tracks WHERE video_id = ? ORDER BY is_default DESC, language ASC";
    $tracks = $db->query($sql, [$videoId]);

    // Generate token for streaming
    $token = encryptVideoToken($videoId);

    // Add full URL to each track
    foreach ($tracks as &$track) {
        $track['url'] = BASE_URL . 'api/stream/?token=' . urlencode($token) . '&file=audio/' . $track['language'] . '.m3u8';
    }

    echo json_encode(['success' => true, 'audio_tracks' => $tracks]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
