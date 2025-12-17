<?php
/**
 * Get Video Progress API
 * GET /api/progress/poll.php?video_id=xxx
 * Used for AJAX polling
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

enableCORS();

// Require authentication
requireAuth();

$videoId = $_GET['video_id'] ?? '';

if (empty($videoId)) {
    errorResponse('Video ID required');
}

$userId = getCurrentUserId();
$isAdminUser = isAdmin();

$db = db();

// Check access
$videoSql = "SELECT user_id, status FROM videos WHERE id = ?";
$video = $db->queryOne($videoSql, [$videoId]);

if (!$video) {
    errorResponse('Video not found', 404);
}

if (!$isAdminUser && $video['user_id'] != $userId) {
    errorResponse('Access denied', 403);
}

// Get encoding jobs with progress
$sql = "SELECT quality, status, progress, current_frame, total_frames, fps, bitrate, estimated_time_remaining 
        FROM encoding_jobs 
        WHERE video_id = ? 
        ORDER BY FIELD(quality, '1080p', '720p', '360p')";

$jobs = $db->query($sql, [$videoId]);

// Format jobs
$formattedJobs = array_map(function($job) {
    return [
        'quality' => $job['quality'],
        'status' => $job['status'],
        'progress' => round(floatval($job['progress']), 2),
        'current_frame' => intval($job['current_frame']),
        'total_frames' => intval($job['total_frames']),
        'fps' => round(floatval($job['fps']), 2),
        'bitrate' => $job['bitrate'],
        'estimated_time_remaining' => $job['estimated_time_remaining'],
        'eta_formatted' => $job['estimated_time_remaining'] ? formatDuration($job['estimated_time_remaining']) : null
    ];
}, $jobs);

// Calculate overall progress
$totalProgress = array_sum(array_column($jobs, 'progress'));
$overallProgress = count($jobs) > 0 ? $totalProgress / count($jobs) : 0;

successResponse([
    'video_id' => $videoId,
    'video_status' => $video['status'],
    'overall_progress' => round($overallProgress, 2),
    'jobs' => $formattedJobs
]);
