<?php
/**
 * Get movie details with episodes
 * GET /api/movies/detail.php?id=X
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

header('Content-Type: application/json');
enableCORS();

// Require authentication
requireAuth();

$userId = getCurrentUserId();
$movieId = $_GET['id'] ?? null;

if (!$movieId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Movie ID required']);
    exit;
}

$db = db();

try {
    // Get movie
    $movie = $db->queryOne("SELECT * FROM movies WHERE id = ? AND user_id = ?", [$movieId, $userId]);

    if (!$movie) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Movie not found']);
        exit;
    }

    // Get episodes (videos in this movie) with metadata
    $episodes = $db->query(
        "SELECT id, original_filename, episode_number, episode_title, 
                subtitle_url, intro_start, intro_end, outro_start, outro_end,
                duration, status, created_at 
         FROM videos 
         WHERE movie_id = ? 
         ORDER BY episode_number ASC, created_at ASC",
        [$movieId]
    );

    // Format episodes
    foreach ($episodes as &$ep) {
        $ep['embed_url'] = BASE_URL . 'embed/' . $ep['id'];
        $ep['m3u8_url'] = HLS_BASE_URL . '/' . $ep['id'] . '/video.m3u8';
        $ep['duration_formatted'] = formatDuration($ep['duration'] ?? 0);
        // Cast numeric fields
        $ep['intro_start'] = $ep['intro_start'] !== null ? floatval($ep['intro_start']) : null;
        $ep['intro_end'] = $ep['intro_end'] !== null ? floatval($ep['intro_end']) : null;
        $ep['outro_start'] = $ep['outro_start'] !== null ? floatval($ep['outro_start']) : null;
        $ep['outro_end'] = $ep['outro_end'] !== null ? floatval($ep['outro_end']) : null;
    }

    $movie['episodes'] = $episodes;
    $movie['episode_count'] = count($episodes);

    echo json_encode([
        'success' => true,
        'movie' => $movie
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
