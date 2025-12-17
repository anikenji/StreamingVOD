<?php
/**
 * Delete Video API
 * DELETE /api/videos/delete.php?id=xxx
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

enableCORS();

// Require authentication
requireAuth();

$videoId = $_GET['id'] ?? $_POST['id'] ?? '';

if (empty($videoId)) {
    errorResponse('Video ID required');
}

$userId = getCurrentUserId();
$isAdminUser = isAdmin();

$db = db();

// Get video
$sql = "SELECT * FROM videos WHERE id = ?";
$video = $db->queryOne($sql, [$videoId]);

if (!$video) {
    errorResponse('Video not found', 404);
}

// Check permission
if (!$isAdminUser && $video['user_id'] != $userId) {
    errorResponse('Access denied', 403);
}

// Delete files
$uploadDir = UPLOAD_DIR . '/' . $videoId;
$hlsDir = HLS_OUTPUT_DIR . '/' . $videoId;
$thumbnailPath = THUMBNAIL_DIR . '/' . $videoId . '.jpg';

if (is_dir($uploadDir)) {
    deleteDirectory($uploadDir);
}

if (is_dir($hlsDir)) {
    deleteDirectory($hlsDir);
}

if (file_exists($thumbnailPath)) {
    unlink($thumbnailPath);
}

// Delete database records (cascade will delete encoding_jobs)
$deleteSql = "DELETE FROM videos WHERE id = ?";
$result = $db->execute($deleteSql, [$videoId]);

if ($result) {
    logMessage("Video deleted: $videoId by user $userId", 'INFO');
    successResponse([], 'Video deleted successfully');
} else {
    errorResponse('Failed to delete video', 500);
}
