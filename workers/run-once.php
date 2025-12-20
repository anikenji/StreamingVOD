<?php
/**
 * One-Shot Video Processing Worker
 * Run this script to process all pending encoding jobs then exit
 * Usage: php run-once.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/FFmpegEncoder.php';

// Check if running from CLI
if (php_sapi_name() !== 'cli') {
    die("This script must be run from command line\n");
}

echo "[" . date('Y-m-d H:i:s') . "] One-Shot Video Processing Worker Started\n";

// Create lock file
$lockFile = __DIR__ . '/worker.lock';
file_put_contents($lockFile, date('Y-m-d H:i:s') . " - PID: " . getmypid());

$db = db();
$processedCount = 0;
$failedCount = 0;

// Process all pending jobs
while (true) {
    // Get next pending job
    $sql = "SELECT ej.*, v.original_path 
            FROM encoding_jobs ej
            JOIN videos v ON ej.video_id = v.id
            WHERE ej.status = 'pending'
            ORDER BY ej.created_at ASC
            LIMIT 1";

    $jobs = $db->query($sql, []);

    // Ensure we have an array
    if (!is_array($jobs) || empty($jobs)) {
        break; // No more pending jobs
    }

    $job = $jobs[0];
    $videoId = $job['video_id'];
    $inputPath = $job['original_path'];

    echo "[" . date('Y-m-d H:i:s') . "] Processing video: $videoId\n";
    echo "    Input: $inputPath\n";

    // Update video status to processing
    $db->execute("UPDATE videos SET status = 'processing', started_at = NOW() 
                  WHERE id = ? AND status = 'pending'", [$videoId]);

    // Create encoder and process
    $encoder = new FFmpegEncoder($videoId, $inputPath);
    $success = $encoder->encodeToHLS($db);

    if ($success) {
        // Mark video as completed
        $playlistPath = FFmpegEncoder::getPlaylistPath($videoId);
        $embedUrl = BASE_URL . '/embed/' . $videoId;
        $embedCode = sprintf(
            '<iframe src="%s" width="640" height="360" frameborder="0" allowfullscreen allow="autoplay"></iframe>',
            htmlspecialchars($embedUrl)
        );

        $db->execute("UPDATE videos SET 
                      status = 'completed',
                      master_playlist_path = ?,
                      embed_url = ?,
                      embed_code = ?,
                      completed_at = NOW()
                      WHERE id = ?", [$playlistPath, $embedUrl, $embedCode, $videoId]);

        // Delete original file to save storage
        $uploadDir = dirname($inputPath);
        if (is_dir($uploadDir) && strpos($uploadDir, $videoId) !== false) {
            deleteDirectory($uploadDir);
            echo "[" . date('Y-m-d H:i:s') . "] 🗑️ Deleted original: $uploadDir\n";
            logMessage("Deleted original upload folder: $uploadDir", 'INFO');
        }

        echo "[" . date('Y-m-d H:i:s') . "] ✅ Completed: $videoId\n";
        logMessage("Video encoding completed: $videoId", 'INFO');
        $processedCount++;
    } else {
        $db->execute("UPDATE videos SET status = 'failed' WHERE id = ?", [$videoId]);
        echo "[" . date('Y-m-d H:i:s') . "] ❌ Failed: $videoId\n";
        logMessage("Video encoding failed: $videoId", 'ERROR');
        $failedCount++;
    }
}

// Summary
echo "\n";
echo "[" . date('Y-m-d H:i:s') . "] Worker finished\n";
echo "    Processed: $processedCount\n";
echo "    Failed: $failedCount\n";

// Remove lock file
if (file_exists($lockFile)) {
    unlink($lockFile);
}

// Exit with error code if any failed
exit($failedCount > 0 ? 1 : 0);
