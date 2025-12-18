<?php
/**
 * Remove video from movie
 * POST /api/movies/remove-video.php
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

header('Content-Type: application/json');
enableCORS();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Require authentication
requireAuth();

$userId = getCurrentUserId();
$db = db();

$input = json_decode(file_get_contents('php://input'), true);
$videoId = $input['video_id'] ?? null;

if (!$videoId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Video ID required']);
    exit;
}

try {
    // Verify video ownership
    $video = $db->queryOne("SELECT id FROM videos WHERE id = ? AND user_id = ?", [$videoId, $userId]);
    if (!$video) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Video not found']);
        exit;
    }

    // Remove from movie
    $sql = "UPDATE videos SET movie_id = NULL, episode_number = NULL, episode_title = NULL WHERE id = ?";
    $db->execute($sql, [$videoId]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
