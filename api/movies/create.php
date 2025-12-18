<?php
/**
 * Create new movie/series
 * POST /api/movies/create.php
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

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);
$title = trim($input['title'] ?? '');
$description = trim($input['description'] ?? '');
$status = $input['status'] ?? 'ongoing';
$posterUrl = trim($input['poster_url'] ?? '');

if (empty($title)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Title is required']);
    exit;
}

// Generate slug from title
$slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $title));
$slug = trim($slug, '-');

// Make slug unique
$baseSlug = $slug;
$counter = 1;
while (true) {
    $existing = $db->queryOne("SELECT id FROM movies WHERE slug = ?", [$slug]);
    if (!$existing)
        break;
    $slug = $baseSlug . '-' . $counter++;
}

try {
    $sql = "INSERT INTO movies (user_id, title, slug, description, poster_url, status) VALUES (?, ?, ?, ?, ?, ?)";
    $db->execute($sql, [$userId, $title, $slug, $description, $posterUrl, $status]);

    $movieId = $db->lastInsertId();

    echo json_encode([
        'success' => true,
        'movie' => [
            'id' => $movieId,
            'title' => $title,
            'slug' => $slug,
            'description' => $description,
            'poster_url' => $posterUrl,
            'status' => $status
        ]
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
