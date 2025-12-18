<?php
/**
 * Add video to movie
 * POST /api/movies/add-video.php
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
$movieId = $input['movie_id'] ?? null;
$videoId = $input['video_id'] ?? null;
$episodeNumber = intval($input['episode_number'] ?? 0);
$episodeTitle = trim($input['episode_title'] ?? '');

if (!$movieId || !$videoId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Movie ID and Video ID required']);
    exit;
}

try {
    // Verify movie ownership
    $movie = $db->queryOne("SELECT id FROM movies WHERE id = ? AND user_id = ?", [$movieId, $userId]);
    if (!$movie) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Movie not found']);
        exit;
    }

    // Verify video ownership
    $video = $db->queryOne("SELECT id FROM videos WHERE id = ? AND user_id = ?", [$videoId, $userId]);
    if (!$video) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Video not found']);
        exit;
    }

    // Auto-assign episode number if not provided
    if ($episodeNumber <= 0) {
        $maxEp = $db->queryOne("SELECT MAX(episode_number) as max_ep FROM videos WHERE movie_id = ?", [$movieId]);
        $episodeNumber = ($maxEp['max_ep'] ?? 0) + 1;
    }

    // Update video
    $sql = "UPDATE videos SET movie_id = ?, episode_number = ?, episode_title = ? WHERE id = ?";
    $db->execute($sql, [$movieId, $episodeNumber, $episodeTitle, $videoId]);

    echo json_encode([
        'success' => true,
        'episode_number' => $episodeNumber
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
