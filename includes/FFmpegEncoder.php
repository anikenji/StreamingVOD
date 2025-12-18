<?php
/**
 * FFmpeg Encoder Class
 * Handles video encoding to HLS format with adaptive bitrate
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/helpers.php';

class FFmpegEncoder
{
    private $videoId;
    private $inputPath;
    private $outputBaseDir;

    public function __construct($videoId, $inputPath)
    {
        $this->videoId = $videoId;
        $this->inputPath = $inputPath;
        $this->outputBaseDir = HLS_OUTPUT_DIR . '/' . $videoId;
    }

    /**
     * Encode video to HLS format (source resolution)
     */
    public function encodeToHLS($db)
    {
        $profile = getEncodingProfile();
        $quality = getEncodingQuality();

        // Create output directory
        $outputDir = $this->outputBaseDir;
        ensureDirectory($outputDir);

        // Get encoding job ID
        $jobId = $this->getEncodingJobId($db);

        if (!$jobId) {
            logMessage("Failed to get encoding job ID for video {$this->videoId}", 'ERROR');
            return false;
        }

        // Update job status to processing
        $this->updateJobStatus($jobId, 'processing', $db);

        // Build FFmpeg command
        $cmd = $this->buildFFmpegCommand($profile, $outputDir);

        logMessage("Starting encode for video {$this->videoId} (source resolution)", 'INFO');
        logMessage("Command: $cmd", 'DEBUG');

        // Execute with progress tracking
        $success = $this->executeWithProgress($cmd, $jobId, $db);

        if ($success) {
            // Update job with completion info
            $playlistPath = $outputDir . '/video.m3u8';
            $this->updateJobCompletion($jobId, $playlistPath, $db);

            logMessage("Encode completed for video {$this->videoId}", 'INFO');
        } else {
            $this->updateJobError($jobId, 'Encoding failed', $db);
            logMessage("Encode failed for video {$this->videoId}", 'ERROR');
        }

        return $success;
    }

    /**
     * Build FFmpeg command for HLS encoding (source resolution)
     */
    private function buildFFmpegCommand($profile, $outputDir)
    {
        $maxRate = $profile['max_bitrate'];
        $bufSize = $profile['buffer_size'];

        // Normalize paths to Windows backslash format
        $ffmpegPath = str_replace('/', '\\', FFMPEG_PATH);
        $inputPath = str_replace('/', '\\', $this->inputPath);
        $outputDirWin = str_replace('/', '\\', $outputDir);

        // Build command without 2>&1 (using separate pipe for stderr)
        $cmd = sprintf(
            '"%s" -i "%s" ' .
            '-c:v libx264 -preset %s ' .
            '-b:v %s -maxrate %s -bufsize %s ' .
            '-g 48 -keyint_min 48 -sc_threshold 0 ' .
            '-c:a aac -b:a %s -ar 48000 ' .
            '-hls_time %d -hls_playlist_type %s ' .
            '-hls_segment_filename "%s\\seg_%%04d.ts" ' .
            '-f hls "%s\\video.m3u8" ' .
            '-y',
            $ffmpegPath,
            $inputPath,
            $profile['preset'],
            $profile['video_bitrate'],
            $maxRate,
            $bufSize,
            $profile['audio_bitrate'],
            HLS_SEGMENT_DURATION,
            HLS_PLAYLIST_TYPE,
            $outputDirWin,
            $outputDirWin
        );

        return $cmd;
    }

    /**
     * Execute FFmpeg command (simplified for Windows compatibility)
     */
    private function executeWithProgress($cmd, $jobId, $db)
    {
        // Update progress to show encoding started
        $this->updateJobProgress($jobId, ['progress' => 5, 'current_frame' => 0, 'fps' => 0], $db);

        // Execute FFmpeg using exec() for Windows compatibility
        $output = [];
        $exitCode = 0;

        // Add 2>&1 to capture both stdout and stderr
        $cmdWithRedirect = $cmd . ' 2>&1';

        exec($cmdWithRedirect, $output, $exitCode);

        // Log output for debugging
        if ($exitCode !== 0) {
            logMessage("FFmpeg exit code: $exitCode", 'ERROR');
            logMessage("FFmpeg output: " . implode("\n", array_slice($output, -10)), 'ERROR');
        }

        return $exitCode === 0;
    }

    /**
     * Parse FFmpeg progress file content
     */
    private function parseProgressFile($content, $duration)
    {
        $progress = [];

        // Extract frame
        if (preg_match('/frame=(\d+)/', $content, $matches)) {
            $progress['current_frame'] = intval($matches[1]);
        }

        // Extract fps
        if (preg_match('/fps=([\d.]+)/', $content, $matches)) {
            $progress['fps'] = floatval($matches[1]);
        }

        // Extract bitrate
        if (preg_match('/bitrate=\s*([\d.]+\w+)/', $content, $matches)) {
            $progress['bitrate'] = $matches[1];
        }

        // Extract out_time for progress calculation
        if (preg_match('/out_time_ms=(\d+)/', $content, $matches)) {
            $outTimeMs = intval($matches[1]);
            $currentTime = $outTimeMs / 1000000; // Convert microseconds to seconds

            if ($duration > 0) {
                $progress['progress'] = min(99, ($currentTime / $duration) * 100);

                // Calculate ETA
                if (isset($progress['fps']) && $progress['fps'] > 0) {
                    $remainingTime = $duration - $currentTime;
                    $progress['estimated_time_remaining'] = intval($remainingTime);
                }
            }
        }

        return !empty($progress) ? $progress : null;
    }

    /**
     * Parse FFmpeg progress output
     */
    private function parseProgress($line, $duration)
    {
        // FFmpeg progress format: frame=1234 fps=28.5 q=23.0 size=10240kB time=00:01:23.45 bitrate=4000kbps

        $progress = [];

        // Extract frame number
        if (preg_match('/frame=\s*(\d+)/', $line, $matches)) {
            $progress['current_frame'] = intval($matches[1]);
        }

        // Extract FPS
        if (preg_match('/fps=\s*([\d.]+)/', $line, $matches)) {
            $progress['fps'] = floatval($matches[1]);
        }

        // Extract bitrate
        if (preg_match('/bitrate=\s*([\d.]+\w+)/', $line, $matches)) {
            $progress['bitrate'] = $matches[1];
        }

        // Extract time
        if (preg_match('/time=\s*(\d+):(\d+):(\d+\.\d+)/', $line, $matches)) {
            $hours = intval($matches[1]);
            $minutes = intval($matches[2]);
            $seconds = floatval($matches[3]);
            $currentTime = $hours * 3600 + $minutes * 60 + $seconds;

            if ($duration > 0) {
                $progress['progress'] = min(100, ($currentTime / $duration) * 100);

                // Calculate ETA
                if (isset($progress['fps']) && $progress['fps'] > 0) {
                    $remainingTime = $duration - $currentTime;
                    $progress['estimated_time_remaining'] = intval($remainingTime);
                }
            }
        }

        return !empty($progress) ? $progress : null;
    }

    /**
     * Get video duration
     */
    private function getVideoDuration()
    {
        return getVideoDuration($this->inputPath) ?? 0;
    }

    /**
     * Get encoding job ID from database
     */
    private function getEncodingJobId($db)
    {
        $sql = "SELECT id FROM encoding_jobs WHERE video_id = ?";
        $result = $db->queryOne($sql, [$this->videoId]);
        return $result ? $result['id'] : null;
    }

    /**
     * Update job status
     */
    private function updateJobStatus($jobId, $status, $db)
    {
        $sql = "UPDATE encoding_jobs SET status = ?, started_at = NOW() WHERE id = ?";
        $db->execute($sql, [$status, $jobId]);
    }

    /**
     * Update job progress
     */
    private function updateJobProgress($jobId, $progress, $db)
    {
        $sql = "UPDATE encoding_jobs SET 
                progress = ?,
                current_frame = ?,
                fps = ?,
                bitrate = ?,
                estimated_time_remaining = ?
                WHERE id = ?";

        $db->execute($sql, [
            $progress['progress'] ?? 0,
            $progress['current_frame'] ?? 0,
            $progress['fps'] ?? 0,
            $progress['bitrate'] ?? null,
            $progress['estimated_time_remaining'] ?? null,
            $jobId
        ]);
    }

    /**
     * Update job completion
     */
    private function updateJobCompletion($jobId, $outputPath, $db)
    {
        $sql = "UPDATE encoding_jobs SET 
                status = 'completed',
                progress = 100,
                output_path = ?,
                completed_at = NOW()
                WHERE id = ?";

        $db->execute($sql, [$outputPath, $jobId]);
    }

    /**
     * Update job error
     */
    private function updateJobError($jobId, $error, $db)
    {
        $sql = "UPDATE encoding_jobs SET 
                status = 'failed',
                error_message = ?
                WHERE id = ?";

        $db->execute($sql, [$error, $jobId]);
    }

    /**
     * Get HLS playlist path for completed video
     */
    public static function getPlaylistPath($videoId)
    {
        return HLS_OUTPUT_DIR . '/' . $videoId . '/video.m3u8';
    }
}
