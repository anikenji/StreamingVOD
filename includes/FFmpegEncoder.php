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
     * Includes hardsub support for ASS/SSA subtitles
     */
    private function buildFFmpegCommand($profile, $outputDir)
    {
        $maxRate = $profile['max_bitrate'];
        $bufSize = $profile['buffer_size'];

        // Normalize paths to Windows backslash format
        $ffmpegPath = str_replace('/', '\\', FFMPEG_PATH);
        $inputPath = str_replace('/', '\\', $this->inputPath);
        $outputDirWin = str_replace('/', '\\', $outputDir);

        // Check encoding type
        $encodeType = defined('ENCODE_TYPE') ? ENCODE_TYPE : 'auto';

        // Check for subtitle streams
        $hasSubtitle = $this->detectSubtitleStream();
        $subtitleFilter = '';

        // Check video codec for compatibility
        $videoCodec = $this->detectVideoCodec();

        // AV1 requires re-encoding (JWPlayer doesn't support AV1)
        $requiresReencode = $videoCodec ? $this->requiresReencode($videoCodec) : false;

        // HEVC/H.265 can use fMP4 stream copy (JWPlayer supports HEVC)
        $requiresFmp4 = $videoCodec ? $this->requiresFmp4Segments($videoCodec) : false;

        // Determine effective mode
        $useCopy = false; // Default to encode

        if ($requiresReencode) {
            // AV1 codec detected - must re-encode to H.264 for JWPlayer compatibility
            logMessage("Detected codec '{$videoCodec}' is not supported by JWPlayer. Forcing re-encode to H.264.", 'INFO');
            $useCopy = false;
        } elseif ($encodeType === 'copy') {
            $useCopy = true;
            if ($requiresFmp4) {
                logMessage("Stream copy mode: Detected codec '{$videoCodec}' requires fMP4 segments for HLS compatibility.", 'INFO');
            }
        } elseif ($encodeType === 'auto') {
            // Auto mode: Use copy if NO subtitles, otherwise encode (to burn subs)
            $useCopy = !$hasSubtitle;
            if ($useCopy && $requiresFmp4) {
                logMessage("Auto mode: Detected codec '{$videoCodec}'. Using stream copy with fMP4 segments.", 'INFO');
            }
        }
        // If 'encode', useCopy remains false

        if ($hasSubtitle) {
            if ($useCopy) {
                logMessage("Warning: Hardsub/Subtitles cannot be burned in 'stream copy' mode for video {$this->videoId}", 'WARNING');
            } else {
                // For embedded subtitles (MKV with ASS/SSA), use subtitles filter
                // Need to escape path for FFmpeg filter (use forward slashes and escape colons/backslashes)
                $escapedInputPath = str_replace('\\', '/', $this->inputPath);
                $escapedInputPath = str_replace(':', '\\:', $escapedInputPath);
                $subtitleFilter = sprintf('-vf "subtitles=\'%s\':si=0"', $escapedInputPath);
                logMessage("Hardsub enabled for video {$this->videoId}", 'INFO');
            }
        } else {
            if ($useCopy && !$requiresFmp4 && !$requiresReencode) {
                logMessage("Auto mode: No subtitles detected. Using 'stream copy' for efficiency.", 'INFO');
            }
        }

        // Build command
        if ($useCopy) {
            // Stream Copy Mode
            if ($requiresFmp4) {
                // Use fMP4 segments for H.265/AV1 compatibility
                // Note: init.mp4 uses relative filename only - FFmpeg places it with the m3u8
                // Using full path in hls_fmp4_init_filename causes EXT-X-MAP:URI to have full path which breaks playback
                $cmd = sprintf(
                    '"%s" -i "%s" ' .
                    '-c:v copy -c:a copy ' .
                    '-f hls ' .
                    '-hls_time %d -hls_playlist_type %s ' .
                    '-hls_segment_type fmp4 ' .
                    '-hls_fmp4_init_filename init.mp4 ' .
                    '-hls_segment_filename "%s\\seg_%%04d.m4s" ' .
                    '"%s\\video.m3u8" ' .
                    '-y',
                    $ffmpegPath,
                    $inputPath,
                    HLS_SEGMENT_DURATION,
                    HLS_PLAYLIST_TYPE,
                    $outputDirWin,
                    $outputDirWin
                );
            } else {
                // Use TS segments for H.264 (standard)
                $cmd = sprintf(
                    '"%s" -i "%s" ' .
                    '-c:v copy -c:a copy ' .
                    '-hls_time %d -hls_playlist_type %s ' .
                    '-hls_segment_filename "%s\\seg_%%04d.ts" ' .
                    '-f hls "%s\\video.m3u8" ' .
                    '-y',
                    $ffmpegPath,
                    $inputPath,
                    HLS_SEGMENT_DURATION,
                    HLS_PLAYLIST_TYPE,
                    $outputDirWin,
                    $outputDirWin
                );
            }
        } else {
            // Re-encode Mode
            $cmd = sprintf(
                '"%s" -i "%s" ' .
                '-c:v libx264 -preset %s ' .
                '%s ' . // Subtitle filter (if any)
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
                $subtitleFilter,
                $profile['video_bitrate'],
                $maxRate,
                $bufSize,
                $profile['audio_bitrate'],
                HLS_SEGMENT_DURATION,
                HLS_PLAYLIST_TYPE,
                $outputDirWin,
                $outputDirWin
            );
        }

        return $cmd;
    }

    /**
     * Detect if video has subtitle streams
     */
    private function detectSubtitleStream()
    {
        $ffprobePath = str_replace('ffmpeg', 'ffprobe', FFMPEG_PATH);
        $ffprobePath = str_replace('/', '\\', $ffprobePath);
        $inputPath = str_replace('/', '\\', $this->inputPath);

        // Check if input file exists
        if (!file_exists($this->inputPath)) {
            logMessage("detectSubtitleStream: Input file not found: {$this->inputPath}", 'WARNING');
            return false;
        }

        // Use ffprobe to check for subtitle streams
        // Note: Don't redirect stderr to stdout (remove 2>&1) so we only get actual data
        $cmd = sprintf(
            '"%s" -v error -select_streams s -show_entries stream=index,codec_name -of csv=p=0 "%s"',
            $ffprobePath,
            $inputPath
        );

        $output = [];
        $exitCode = -1;
        exec($cmd, $output, $exitCode);

        // Only consider we have subtitles if:
        // 1. Exit code is 0 (command succeeded)
        // 2. Output is not empty and contains valid stream data (numbers, not error messages)
        $outputStr = trim(implode('', $output));

        // Valid subtitle output looks like: "0,ass" or "0,subrip" (index,codec)
        // Error messages would NOT match this pattern
        $hasValidSubtitleData = $exitCode === 0
            && !empty($outputStr)
            && preg_match('/^\d+,\w+/', $outputStr);

        if ($hasValidSubtitleData) {
            logMessage("Detected subtitle stream in video {$this->videoId}: $outputStr", 'DEBUG');
        } else {
            logMessage("No subtitle stream detected in video {$this->videoId} (exitCode: $exitCode)", 'DEBUG');
        }

        return $hasValidSubtitleData;
    }

    /**
     * Detect video codec using ffprobe
     * Returns the codec name (e.g., 'h264', 'hevc', 'av1') or null if detection fails
     */
    private function detectVideoCodec()
    {
        // Use FFPROBE_PATH constant if defined, otherwise derive from FFMPEG_PATH
        if (defined('FFPROBE_PATH')) {
            $ffprobePath = str_replace('/', '\\', FFPROBE_PATH);
        } else {
            $ffprobePath = str_replace('ffmpeg', 'ffprobe', FFMPEG_PATH);
            $ffprobePath = str_replace('/', '\\', $ffprobePath);
        }
        $inputPath = str_replace('/', '\\', $this->inputPath);

        // Check if input file exists
        if (!file_exists($this->inputPath)) {
            logMessage("detectVideoCodec: Input file not found: {$this->inputPath}", 'WARNING');
            return null;
        }

        // Use ffprobe to get video codec
        $cmd = sprintf(
            '"%s" -v error -select_streams v:0 -show_entries stream=codec_name -of csv=p=0 "%s"',
            $ffprobePath,
            $inputPath
        );

        logMessage("detectVideoCodec command: $cmd", 'DEBUG');

        $output = [];
        $exitCode = -1;
        exec($cmd . ' 2>&1', $output, $exitCode);

        $codec = trim(implode('', $output));

        logMessage("detectVideoCodec output: '$codec' (exitCode: $exitCode)", 'DEBUG');

        if ($exitCode === 0 && !empty($codec)) {
            logMessage("Detected video codec for video {$this->videoId}: $codec", 'DEBUG');
            return strtolower($codec);
        }

        logMessage("Failed to detect video codec for video {$this->videoId} (exitCode: $exitCode, output: $codec)", 'WARNING');
        return null;
    }

    /**
     * Check if the video codec requires re-encoding for JWPlayer compatibility
     * Currently no codecs require forced re-encoding - we use fMP4 for modern codecs
     */
    private function requiresReencode($codec)
    {
        // AV1 and VP9 require re-encoding to H.264 because:
        // - JWPlayer doesn't support AV1 in HLS
        // - VP9 is not supported in HLS standard (Apple never added support)
        // HEVC can use fMP4 stream copy (JWPlayer supports HEVC with fMP4)
        $unsupportedCodecs = ['av1', 'vp9', 'vp09'];
        return in_array($codec, $unsupportedCodecs);
    }



    /**
     * Check if the video codec requires fMP4 segments for HLS
     * AV1 and HEVC (H.265) are not supported in TS containers, but work with fMP4
     */
    private function requiresFmp4Segments($codec)
    {
        // Both AV1 and HEVC need fMP4 segments (TS doesn't support these codecs)
        $fmp4Codecs = ['hevc', 'h265', 'av1'];
        return in_array($codec, $fmp4Codecs);
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
