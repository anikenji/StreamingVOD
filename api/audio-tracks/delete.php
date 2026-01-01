<?php
/**
 * Audio Track Delete API
 * Deletes an audio track and its files
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

header('Content-Type: application/json');

try {
    requireAuth();

    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Method not allowed');
    }

    // Support both DELETE method and POST with _method=DELETE
    $data = json_decode(file_get_contents('php://input'), true);
    $trackId = $data['id'] ?? $_POST['id'] ?? $_GET['id'] ?? '';

    if (empty($trackId)) {
        throw new Exception('Missing track id');
    }

    $db = Database::getInstance();

    // Get track info first
    $sql = "SELECT * FROM audio_tracks WHERE id = ?";
    $tracks = $db->query($sql, [$trackId]);

    if (empty($tracks)) {
        throw new Exception('Audio track not found');
    }

    $track = $tracks[0];
    $videoId = $track['video_id'];

    // Delete files
    $audioDir = HLS_OUTPUT_DIR . '/' . $videoId . '/audio';
    $language = $track['language'];

    // Delete HLS files
    $filesToDelete = glob($audioDir . '/audio_' . $language . '*');
    $filesToDelete[] = $audioDir . '/init_' . $language . '.mp4';
    $filesToDelete[] = $audioDir . '/' . $language . '.m3u8';

    foreach ($filesToDelete as $file) {
        if (file_exists($file)) {
            unlink($file);
        }
    }

    // Delete from database
    $sql = "DELETE FROM audio_tracks WHERE id = ?";
    $db->execute($sql, [$trackId]);

    logMessage("Deleted audio track: {$language} for video {$videoId}", 'INFO');

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
