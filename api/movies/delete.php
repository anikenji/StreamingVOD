<?php
/**
 * Delete movie (videos are kept, just unlinked)
 * POST /api/movies/delete.php
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

    // Videos will be unlinked automatically due to ON DELETE SET NULL
    $db->execute("DELETE FROM movies WHERE id = ?", [$movieId]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
