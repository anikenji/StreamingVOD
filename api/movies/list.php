<?php
/**
 * List all movies for current user
 * GET /api/movies/list.php
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
$db = db();

try {
    $sql = "SELECT m.*, 
            (SELECT COUNT(*) FROM videos v WHERE v.movie_id = m.id) as video_count
            FROM movies m 
            WHERE m.user_id = ? 
            ORDER BY m.updated_at DESC";

    $movies = $db->query($sql, [$userId]);

    echo json_encode([
        'success' => true,
        'movies' => $movies
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
