<?php
/**
 * Export movie links in various formats
 * GET /api/movies/export-links.php?id=X&type=embed|m3u8|both
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
$type = $_GET['type'] ?? 'both'; // embed, m3u8, or both

if (!$movieId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Movie ID required']);
    exit;
}

$db = db();

try {
    // Verify movie ownership
    $movie = $db->queryOne("SELECT * FROM movies WHERE id = ? AND user_id = ?", [$movieId, $userId]);
    if (!$movie) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Movie not found']);
        exit;
    }

    // Get episodes
    $episodes = $db->query(
        "SELECT id, episode_number, episode_title, original_filename, status 
         FROM videos 
         WHERE movie_id = ? AND status = 'completed'
         ORDER BY episode_number ASC",
        [$movieId]
    );

    $lines = [];

    foreach ($episodes as $ep) {
        $epNum = $ep['episode_number'] ?? 0;
        $embedUrl = BASE_URL . 'embed/' . $ep['id'];
        $m3u8Url = HLS_BASE_URL . '/' . $ep['id'] . '/video.m3u8';

        // Format: episode_number|link|type
        if ($type === 'embed' || $type === 'both') {
            $lines[] = "{$epNum}|{$embedUrl}|embed";
        }
        if ($type === 'm3u8' || $type === 'both') {
            $lines[] = "{$epNum}|{$m3u8Url}|m3u8";
        }
    }

    echo json_encode([
        'success' => true,
        'movie' => [
            'id' => $movie['id'],
            'title' => $movie['title'],
            'slug' => $movie['slug']
        ],
        'type' => $type,
        'episode_count' => count($episodes),
        'links' => $lines,
        'formatted' => implode("\n", $lines)
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
