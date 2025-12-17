<?php
/**
 * Get Video Details API
 * GET /api/videos/detail.php?id=xxx
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

enableCORS();

// Require authentication
requireAuth();

$videoId = $_GET['id'] ?? '';

if (empty($videoId)) {
    errorResponse('Video ID required');
}

$userId = getCurrentUserId();
$isAdminUser = isAdmin();

$db = db();

// Get video details
$sql = "SELECT v.*, u.username 
        FROM videos v 
        LEFT JOIN users u ON v.user_id = u.id 
        WHERE v.id = ?";

$video = $db->queryOne($sql, [$videoId]);

if (!$video) {
    errorResponse('Video not found', 404);
}

// Check access permission
if (!$isAdminUser && $video['user_id'] != $userId) {
    errorResponse('Access denied', 403);
}

// Get encoding jobs
$jobsSql = "SELECT * FROM encoding_jobs WHERE video_id = ? ORDER BY 
            FIELD(quality, '1080p', '720p', '360p')";
$jobs = $db->query($jobsSql, [$videoId]);

// Format encoding jobs
$formattedJobs = array_map(function($job) {
    return [
        'quality' => $job['quality'],
        'status' => $job['status'],
        'progress' => floatval($job['progress']),
        'current_frame' => intval($job['current_frame']),
        'total_frames' => intval($job['total_frames']),
        'fps' => floatval($job['fps']),
        'bitrate' => $job['bitrate'],
        'estimated_time_remaining' => $job['estimated_time_remaining'],
        'error_message' => $job['error_message'],
        'started_at' => $job['started_at'],
        'completed_at' => $job['completed_at']
    ];
}, $jobs);

// Calculate overall progress (same as job progress for single quality)
$overallProgress = 0;
if (count($jobs) > 0) {
    $overallProgress = floatval($jobs[0]['progress']);
}

successResponse([
    'video' => [
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
        'started_at' => $video['started_at'],
        'completed_at' => $video['completed_at'],
        'uploader' => $video['username'],
        'overall_progress' => round($overallProgress, 2),
        'completed_jobs' => $completedJobs,
        'total_jobs' => count($jobs)
    ],
    'encoding_jobs' => $formattedJobs
]);
