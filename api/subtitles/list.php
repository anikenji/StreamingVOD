<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json');

try {
    requireAuth();

    $videoId = $_GET['video_id'] ?? '';

    if (empty($videoId)) {
        throw new Exception('Missing video_id');
    }

    $db = Database::getInstance();

    // Get subtitles
    $sql = "SELECT * FROM subtitles WHERE video_id = ? ORDER BY language ASC";
    $subtitles = $db->query($sql, [$videoId]);

    // Generate token for preview
    // Note: This token allows access to the video segments too
    $token = encryptVideoToken($videoId);

    // Add full URL to file_path
    foreach ($subtitles as &$sub) {
        // Use proxy for external file access
        $sub['url'] = BASE_URL . 'api/stream/subtitle.php?token=' . urlencode($token) . '&id=' . $sub['id'];
    }

    echo json_encode(['success' => true, 'subtitles' => $subtitles]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
