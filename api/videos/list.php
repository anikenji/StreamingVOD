<?php
/**
 * Get Video List API
 * GET /api/videos/list.php
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

enableCORS();

// Require authentication
requireAuth();

$userId = getCurrentUserId();
$isAdminUser = isAdmin();

// Get query parameters
$status = $_GET['status'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = min(100, max(1, intval($_GET['limit'] ?? 20)));
$offset = ($page - 1) * $limit;

$db = db();

// Build query
$where = [];
$params = [];

// Non-admin users can only see their own videos
if (!$isAdminUser) {
    $where[] = "v.user_id = ?";
    $params[] = $userId;
}

if ($status && in_array($status, ['pending', 'processing', 'completed', 'failed'])) {
    $where[] = "v.status = ?";
    $params[] = $status;
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Get total count
$countSql = "SELECT COUNT(*) as total FROM videos v $whereClause";
$countResult = $db->queryOne($countSql, $params);
$total = $countResult['total'];

// Get videos
$sql = "SELECT v.*, u.username 
        FROM videos v 
        LEFT JOIN users u ON v.user_id = u.id 
        $whereClause 
        ORDER BY v.created_at DESC 
        LIMIT $limit OFFSET $offset";

$videos = $db->query($sql, $params);

// Format video data
$formattedVideos = array_map(function($video) {
    return [
        'id' => $video['id'],
        'original_filename' => $video['original_filename'],
        'status' => $video['status'],
        'file_size' => intval($video['file_size']),
        'file_size_formatted' => formatBytes($video['file_size']),
        'duration' => floatval($video['duration']),
        'duration_formatted' => formatDuration($video['duration']),
        'thumbnail_url' => $video['thumbnail_path'] ? THUMBNAIL_BASE_URL . '/' . $video['id'] . '.jpg' : null,
        'embed_url' => $video['status'] === 'completed' ? BASE_URL . '/embed/' . $video['id'] : null,
        'master_playlist_url' => $video['status'] === 'completed' ? HLS_BASE_URL . '/' . $video['id'] . '/video.m3u8' : null,
        'created_at' => $video['created_at'],
        'completed_at' => $video['completed_at'],
        'uploader' => $video['username']
    ];
}, $videos);

successResponse([
    'videos' => $formattedVideos,
    'pagination' => [
        'total' => intval($total),
        'page' => $page,
        'limit' => $limit,
        'pages' => ceil($total / $limit)
    ]
]);
