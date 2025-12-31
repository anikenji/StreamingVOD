<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json');

try {
    requireAuth();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Method not allowed');
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $subtitleId = $input['subtitle_id'] ?? $_POST['subtitle_id'] ?? '';

    if (empty($subtitleId)) {
        throw new Exception('Missing subtitle_id');
    }

    $db = Database::getInstance();

    // Get file path before deleting
    $sql = "SELECT file_path FROM subtitles WHERE id = ?";
    $sub = $db->queryOne($sql, [$subtitleId]);

    if (!$sub) {
        throw new Exception('Subtitle not found');
    }

    // Delete file
    $filePath = __DIR__ . '/../../' . $sub['file_path'];
    if (file_exists($filePath)) {
        if (!unlink($filePath)) {
            // Log warning but proceed with DB delete
            error_log("Failed to delete subtitle file: $filePath");
        }
    }

    // Delete from DB
    $sql = "DELETE FROM subtitles WHERE id = ?";
    $db->execute($sql, [$subtitleId]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
