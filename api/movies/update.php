<?php
/**
 * Update movie
 * POST /api/movies/update.php
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
$movieId = $input['id'] ?? null;
$title = trim($input['title'] ?? '');
$description = trim($input['description'] ?? '');
$status = $input['status'] ?? 'ongoing';
$posterUrl = trim($input['poster_url'] ?? '');
$totalEpisodes = intval($input['total_episodes'] ?? 0);

if (!$movieId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Movie ID required']);
    exit;
}

try {
    // Verify ownership
    $movie = $db->queryOne("SELECT id FROM movies WHERE id = ? AND user_id = ?", [$movieId, $userId]);
    if (!$movie) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Movie not found']);
        exit;
    }

    $sql = "UPDATE movies SET title = ?, description = ?, status = ?, poster_url = ?, total_episodes = ? WHERE id = ?";
    $db->execute($sql, [$title, $description, $status, $posterUrl, $totalEpisodes, $movieId]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
