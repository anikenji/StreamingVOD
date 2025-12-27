<?php
/**
 * Background Video Processing Worker
 * Run this script continuously to process encoding jobs
 * Usage: php process-video.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/FFmpegEncoder.php';
require_once __DIR__ . '/../includes/ChapterExtractor.php';

// Check if running from CLI
if (php_sapi_name() !== 'cli') {
    die("This script must be run from command line\n");
}

echo "HLS Video Processing Worker Started\n";
echo "Press Ctrl+C to stop\n\n";

$db = db();
$currentlyProcessing = [];

while (true) {
    try {
        // Get pending jobs (limit concurrent encodes)
        $sql = "SELECT ej.*, v.original_path 
                FROM encoding_jobs ej
                JOIN videos v ON ej.video_id = v.id
                WHERE ej.status = 'pending'
                ORDER BY ej.created_at ASC
                LIMIT ?";

        $availableSlots = MAX_CONCURRENT_ENCODES - count($currentlyProcessing);

        if ($availableSlots > 0) {
            $pendingJobs = $db->query($sql, [$availableSlots]);

            if (is_array($pendingJobs))
                foreach ($pendingJobs as $job) {
                    processJob($job, $db);
                }
        }

        // Check for completed videos
        checkVideoCompletion($db);

        // Clean up old temp files
        cleanupTempFiles();

    } catch (Exception $e) {
        logMessage("Worker error: " . $e->getMessage(), 'ERROR');
    }

    sleep(WORKER_INTERVAL);
}

/**
 * Process a single encoding job
 */
function processJob($job, $db)
{
    $videoId = $job['video_id'];
    $inputPath = $job['original_path'];

    echo "[" . date('Y-m-d H:i:s') . "] Processing video $videoId (source resolution)\n";

    // Update video status to processing if not already
    $db->execute("UPDATE videos SET status = 'processing', started_at = NOW() 
                  WHERE id = ? AND status = 'pending'", [$videoId]);

    // Extract chapter markers (OP/ED) from source file if available
    // Only fills empty fields, doesn't override existing values
    try {
        $extracted = ChapterExtractor::extractAndUpdateDatabase($videoId, $inputPath, $db);
        if ($extracted) {
            echo "[" . date('Y-m-d H:i:s') . "] Auto-detected OP/ED chapters for $videoId\n";
        }
    } catch (Exception $e) {
        echo "[" . date('Y-m-d H:i:s') . "] Chapter extraction failed: " . $e->getMessage() . "\n";
    }

    // Create encoder and process
    $encoder = new FFmpegEncoder($videoId, $inputPath);
    $success = $encoder->encodeToHLS($db);

    if ($success) {
        echo "[" . date('Y-m-d H:i:s') . "] Completed: $videoId\n";
    } else {
        echo "[" . date('Y-m-d H:i:s') . "] Failed: $videoId\n";
    }
}

/**
 * Check if encoding job for a video is completed
 */
function checkVideoCompletion($db)
{
    // Get videos that are still processing
    $sql = "SELECT v.id, ej.status
            FROM videos v
            JOIN encoding_jobs ej ON v.id = ej.video_id
            WHERE v.status = 'processing'";

    $videos = $db->query($sql, []);

    if (is_array($videos))
        foreach ($videos as $video) {
            $videoId = $video['id'];
            $jobStatus = $video['status'];

            // Check if job completed
            if ($jobStatus === 'completed') {
                // Get playlist path
                $playlistPath = FFmpegEncoder::getPlaylistPath($videoId);

                // Generate embed code/URL
                $embedUrl = BASE_URL . '/embed/' . $videoId;
                $embedCode = generateEmbedCode($videoId);

                // Update video status
                $db->execute("UPDATE videos SET 
                          status = 'completed',
                          master_playlist_path = ?,
                          embed_url = ?,
                          embed_code = ?,
                          completed_at = NOW()
                          WHERE id = ?", [$playlistPath, $embedUrl, $embedCode, $videoId]);

                echo "[" . date('Y-m-d H:i:s') . "] Video completed: $videoId\n";
                logMessage("Video encoding completed: $videoId", 'INFO');
            }
            // Check if job failed
            else if ($jobStatus === 'failed') {
                $db->execute("UPDATE videos SET status = 'failed' WHERE id = ?", [$videoId]);
                echo "[" . date('Y-m-d H:i:s') . "] Video failed: $videoId\n";
                logMessage("Video encoding failed: $videoId", 'ERROR');
            }
        }
}

/**
 * Generate embed code for JWPlayer
 */
function generateEmbedCode($videoId)
{
    $embedUrl = BASE_URL . '/embed/' . $videoId;
    return sprintf(
        '<iframe src="%s" width="640" height="360" frameborder="0" allowfullscreen allow="autoplay"></iframe>',
        htmlspecialchars($embedUrl)
    );
}

/**
 * Clean up old temp files (> 24 hours)
 */
function cleanupTempFiles()
{
    $tempDir = TEMP_DIR;

    if (!is_dir($tempDir)) {
        return;
    }

    $files = glob($tempDir . '/*');
    $now = time();

    foreach ($files as $file) {
        if (is_dir($file) && ($now - filemtime($file)) > 86400) {
            deleteDirectory($file);
        }
    }
}
