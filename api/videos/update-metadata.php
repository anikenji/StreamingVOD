<?php
/**
 * Update video/episode metadata
 * POST /api/videos/update-metadata.php
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

    // Build update fields
    $updateFields = [];
    $params = [];

    // Episode info
    if (isset($input['episode_number'])) {
        $updateFields[] = 'episode_number = ?';
        $params[] = intval($input['episode_number']) ?: null;
    }
    if (isset($input['episode_title'])) {
        $updateFields[] = 'episode_title = ?';
        $params[] = trim($input['episode_title']) ?: null;
    }

    // Subtitle
    if (isset($input['subtitle_url'])) {
        $updateFields[] = 'subtitle_url = ?';
        $params[] = trim($input['subtitle_url']) ?: null;
    }

    // Intro timing (in seconds)
    if (isset($input['intro_start'])) {
        $updateFields[] = 'intro_start = ?';
        $params[] = floatval($input['intro_start']) ?: null;
    }
    if (isset($input['intro_end'])) {
        $updateFields[] = 'intro_end = ?';
        $params[] = floatval($input['intro_end']) ?: null;
    }

    // Outro timing
    if (isset($input['outro_start'])) {
        $updateFields[] = 'outro_start = ?';
        $params[] = floatval($input['outro_start']) ?: null;
    }
    if (isset($input['outro_end'])) {
        $updateFields[] = 'outro_end = ?';
        $params[] = floatval($input['outro_end']) ?: null;
    }

    if (empty($updateFields)) {
        echo json_encode(['success' => true, 'message' => 'No fields to update']);
        exit;
    }

    $params[] = $videoId;
    $sql = "UPDATE videos SET " . implode(', ', $updateFields) . " WHERE id = ?";
    $db->execute($sql, $params);

    echo json_encode(['success' => true, 'message' => 'Metadata updated']);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
