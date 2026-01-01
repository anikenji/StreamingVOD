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
            // Detect video info and generate Master Playlist
            $videoInfo = $this->detectVideoBitrate();
            $this->generateMasterPlaylist($outputDir, $videoInfo);

            // Update job with completion info - point to master.m3u8
            $playlistPath = $outputDir . '/master.m3u8';
            $this->updateJobCompletion($jobId, $playlistPath, $db);

            logMessage("Encode completed for video {$this->videoId}", 'INFO');
        } else {
            $this->updateJobError($jobId, 'Encoding failed', $db);
            logMessage("Encode failed for video {$this->videoId}", 'ERROR');
        }

        return $success;
    }

    /**
     * Build FFmpeg command for video encoding
     * Supports: DASH + HLS (for modern codecs), HLS-only (for H.264)
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

        // Check if codec should use DASH (AV1, VP9 - now supported by Shaka Player)
        $useDash = $videoCodec ? $this->shouldUseDash($videoCodec) : false;

        // Check if codec requires fMP4 segments for HLS (HEVC)
        $requiresFmp4 = $videoCodec ? $this->requiresFmp4Segments($videoCodec) : false;

        // Determine effective mode
        $useCopy = false; // Default to encode

        if ($useDash) {
            // AV1/VP9 detected - use DASH for Shaka Player native support
            logMessage("Detected codec '{$videoCodec}' - using DASH output for Shaka Player.", 'INFO');
            $useCopy = true; // Stream copy for DASH
        } elseif ($encodeType === 'copy') {
            $useCopy = true;
            if ($requiresFmp4) {
                logMessage("Stream copy mode: Detected codec '{$videoCodec}' requires fMP4 segments.", 'INFO');
            }
        } elseif ($encodeType === 'auto') {
            // Auto mode: Use copy if NO subtitles, otherwise encode (to burn subs)
            $useCopy = !$hasSubtitle;
            if ($useCopy && $requiresFmp4) {
                logMessage("Auto mode: Detected codec '{$videoCodec}'. Using stream copy with fMP4.", 'INFO');
            }
        }
        // If 'encode', useCopy remains false

        if ($hasSubtitle) {
            if (!$useCopy) {
                // Need to escape path for FFmpeg filter (use forward slashes and escape colons/backslashes)
                $escapedInputPath = str_replace('\\', '/', $this->inputPath);
                $escapedInputPath = str_replace(':', '\\:', $escapedInputPath);
                $subtitleFilter = sprintf('-vf "subtitles=\'%s\':si=0"', $escapedInputPath);
                logMessage("Hardsub enabled for video {$this->videoId}", 'INFO');
            }
        } else {
            if ($useCopy && !$useDash && !$requiresFmp4) {
                logMessage("Auto mode: No subtitles detected. Using 'stream copy' for efficiency.", 'INFO');
            }
        }

        // Build command based on streaming method
        if ($useCopy) {
            if ($useDash) {
                // DASH mode for AV1/VP9 (Shaka Player native support)
                $cmd = $this->buildDashCommand($ffmpegPath, $inputPath, $outputDirWin);
            } elseif ($requiresFmp4) {
                // fMP4 HLS mode for HEVC
                $cmd = $this->buildFmp4HlsCommand($ffmpegPath, $inputPath, $outputDirWin);
            } else {
                // Standard TS HLS mode for H.264
                $cmd = $this->buildTsHlsCommand($ffmpegPath, $inputPath, $outputDirWin);
            }
        } else {
            // Re-encode Mode - always output to HLS with TS segments (H.264 output)
            $cmd = $this->buildReencodeCommand($ffmpegPath, $inputPath, $outputDirWin, $profile, $subtitleFilter, $maxRate, $bufSize);
        }

        return $cmd;
    }

    /**
     * Build DASH command for AV1/VP9 codecs
     * Creates DASH manifest with fMP4 segments
     * ALSO generates HLS fallback (re-encode to H.264) for iOS/Safari
     */
    private function buildDashCommand($ffmpegPath, $inputPath, $outputDirWin)
    {
        // IMPORTANT: Use forward slashes for FFmpeg paths to avoid escape sequence issues
        // (e.g., \v becomes vertical tab, \n becomes newline)
        $ffmpegPathSafe = str_replace('\\', '/', $ffmpegPath);
        $inputPathSafe = str_replace('\\', '/', $inputPath);
        $outputDirSafe = str_replace('\\', '/', $outputDirWin);

        // Get encoding profile for HLS fallback
        $profile = getEncodingProfile();

        // Combined command: DASH (stream copy) + HLS fallback (re-encode to H.264)
        // Uses multiple outputs in single FFmpeg call
        $cmd = '"' . $ffmpegPathSafe . '" -i "' . $inputPathSafe . '" ' .
            // Output 1: DASH stream copy (AV1/VP9 native)
            '-map 0:v:0 -map 0:a:0 ' .
            '-c:v copy -c:a copy ' .
            '-f dash ' .
            '-seg_duration ' . HLS_SEGMENT_DURATION . ' ' .
            '"' . $outputDirSafe . '/manifest.mpd" ' .
            // Output 2: HLS fallback (re-encode to H.264 fMP4 for iOS/Safari)
            '-map 0:v:0 -map 0:a:0 ' .
            '-c:v libx264 -preset ' . $profile['preset'] . ' ' .
            '-b:v ' . $profile['video_bitrate'] . ' ' .
            '-maxrate ' . $profile['max_bitrate'] . ' ' .
            '-bufsize ' . $profile['buffer_size'] . ' ' .
            '-g 48 -keyint_min 48 -sc_threshold 0 ' .
            '-c:a aac -b:a ' . $profile['audio_bitrate'] . ' -ar 48000 ' .
            '-f hls ' .
            '-hls_time ' . HLS_SEGMENT_DURATION . ' -hls_playlist_type ' . HLS_PLAYLIST_TYPE . ' ' .
            '-hls_segment_type fmp4 ' .
            '-hls_fmp4_init_filename "init.mp4" ' .
            '-hls_segment_filename "' . $outputDirSafe . '/seg_%04d.m4s" ' .
            '"' . $outputDirSafe . '/video.m3u8" ' .
            '-y';

        logMessage("Using DASH + HLS fallback for video {$this->videoId}", 'INFO');
        logMessage("Dual output command: $cmd", 'DEBUG');
        return $cmd;
    }

    /**
     * Build fMP4 HLS command for HEVC streams
     */
    private function buildFmp4HlsCommand($ffmpegPath, $inputPath, $outputDirWin)
    {
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

        return $cmd;
    }

    /**
     * Build fMP4 HLS command for H.264 stream copy
     * Uses fMP4 segments instead of TS to avoid mux.js transmuxing issues in Shaka Player
     * fMP4 segments work natively with browser MediaSource API
     */
    private function buildTsHlsCommand($ffmpegPath, $inputPath, $outputDirWin)
    {
        // Use forward slashes for FFmpeg (Windows backslashes can cause issues)
        $ffmpegPathSafe = str_replace('\\', '/', $ffmpegPath);
        $inputPathSafe = str_replace('\\', '/', $inputPath);
        $outputDirSafe = str_replace('\\', '/', $outputDirWin);

        // Detect audio codec - browsers only support AAC/MP3, not EAC3/DTS/AC3
        $audioInfo = $this->detectAudioCodec();
        $audioCodec = $audioInfo['codec'];
        $audioChannels = $audioInfo['channels'];

        // Browser-compatible audio codecs (can stream copy)
        $compatibleCodecs = ['aac', 'mp3', 'mp4a'];

        if (in_array($audioCodec, $compatibleCodecs)) {
            $audioParams = '-c:a copy';
            logMessage("Audio codec '{$audioCodec}' is browser-compatible - stream copy", 'INFO');
        } else {
            // Re-encode to AAC, preserving channel count (max 6 for 5.1)
            $targetChannels = min($audioChannels, 6);
            $bitrate = ($targetChannels >= 6) ? '384k' : '192k';
            $audioParams = "-c:a aac -b:a {$bitrate} -ar 48000 -ac {$targetChannels}";
            logMessage("Audio codec '{$audioCodec}' not browser-compatible - re-encoding to AAC {$targetChannels}ch", 'INFO');
        }

        // Use fMP4 segments for better Shaka Player compatibility
        $cmd = sprintf(
            '"%s" -i "%s" ' .
            '-c:v copy %s ' .
            '-movflags +frag_keyframe+empty_moov+default_base_moof ' .
            '-f hls -hls_time %d -hls_playlist_type %s ' .
            '-hls_segment_type fmp4 ' .
            '-hls_fmp4_init_filename "init.mp4" ' .
            '-hls_segment_filename "%s/seg_%%04d.m4s" ' .
            '"%s/video.m3u8" -y',
            $ffmpegPathSafe,
            $inputPathSafe,
            $audioParams,
            HLS_SEGMENT_DURATION,
            HLS_PLAYLIST_TYPE,
            $outputDirSafe,
            $outputDirSafe
        );

        logMessage("Using fMP4 HLS segments for H.264 stream copy", 'INFO');
        logMessage("fMP4 command: $cmd", 'DEBUG');
        return $cmd;
    }

    /**
     * Build re-encode command (H.264 output with fMP4 segments)
     * Uses fMP4 for consistency with stream copy mode
     */
    private function buildReencodeCommand($ffmpegPath, $inputPath, $outputDirWin, $profile, $subtitleFilter, $maxRate, $bufSize)
    {
        // Use forward slashes for FFmpeg (Windows backslashes can cause issues)
        $ffmpegPathSafe = str_replace('\\', '/', $ffmpegPath);
        $inputPathSafe = str_replace('\\', '/', $inputPath);
        $outputDirSafe = str_replace('\\', '/', $outputDirWin);

        $cmd = sprintf(
            '"%s" -i "%s" ' .
            '-c:v libx264 -preset %s ' .
            '%s ' . // Subtitle filter (if any)
            '-b:v %s -maxrate %s -bufsize %s ' .
            '-g 48 -keyint_min 48 -sc_threshold 0 ' .
            '-c:a aac -b:a %s -ar 48000 ' .
            '-f hls ' .
            '-hls_time %d -hls_playlist_type %s ' .
            '-hls_segment_type fmp4 ' .
            '-hls_fmp4_init_filename init.mp4 ' .
            '-hls_segment_filename "%s/seg_%%04d.m4s" ' .
            '"%s/video.m3u8" ' .
            '-y',
            $ffmpegPathSafe,
            $inputPathSafe,
            $profile['preset'],
            $subtitleFilter,
            $profile['video_bitrate'],
            $maxRate,
            $bufSize,
            $profile['audio_bitrate'],
            HLS_SEGMENT_DURATION,
            HLS_PLAYLIST_TYPE,
            $outputDirSafe,
            $outputDirSafe
        );

        return $cmd;
    }

    /**
     * Check if codec should use DASH output (for native AV1/VP9 support)
     * Now enabled for Shaka Player which supports DASH natively
     */
    private function shouldUseDash($codec)
    {
        // Shaka Player supports DASH natively - enable for AV1/VP9
        $dashCodecs = ['av1', 'vp9', 'vp09'];
        return in_array($codec, $dashCodecs);
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
     * Detect primary audio codec using ffprobe
     * Returns array with: codec (e.g., 'aac', 'eac3'), channels (e.g., 6)
     */
    private function detectAudioCodec(): array
    {
        $result = ['codec' => 'aac', 'channels' => 2];

        if (defined('FFPROBE_PATH')) {
            $ffprobePath = str_replace('/', '\\', FFPROBE_PATH);
        } else {
            $ffprobePath = str_replace('ffmpeg', 'ffprobe', FFMPEG_PATH);
            $ffprobePath = str_replace('/', '\\', $ffprobePath);
        }
        $inputPath = str_replace('/', '\\', $this->inputPath);

        if (!file_exists($this->inputPath)) {
            return $result;
        }

        $cmd = sprintf(
            '"%s" -v error -select_streams a:0 -show_entries stream=codec_name,channels -of csv=p=0 "%s"',
            $ffprobePath,
            $inputPath
        );

        $output = [];
        $exitCode = -1;
        exec($cmd . ' 2>&1', $output, $exitCode);

        if ($exitCode === 0 && !empty($output[0])) {
            $parts = explode(',', trim($output[0]));
            if (count($parts) >= 1) {
                $result['codec'] = strtolower(trim($parts[0]));
            }
            if (count($parts) >= 2) {
                $result['channels'] = intval(trim($parts[1]));
            }
            logMessage("detectAudioCodec: {$result['codec']}, {$result['channels']}ch", 'DEBUG');
        }

        return $result;
    }

    /**
     * Check if the video codec requires re-encoding for player compatibility
     * With Shaka Player, AV1/VP9 can use DASH natively (no re-encoding needed)
     */
    private function requiresReencode($codec)
    {
        // Shaka Player supports AV1/VP9 via DASH - no re-encoding needed
        // Only truly unsupported codecs would go here
        $unsupportedCodecs = [];
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
     * Detect video bitrate, audio bitrate, resolution, and codec profile using ffprobe
     * Returns array with: video_bitrate, audio_bitrate, width, height, codec_profile
     * Used for generating Master Playlist with accurate BANDWIDTH and CODECS
     */
    private function detectVideoBitrate(): array
    {
        $result = [
            'video_bitrate' => 0,
            'audio_bitrate' => 0,
            'width' => 0,
            'height' => 0,
            'codec_profile' => 'avc1.640029', // Default H.264 High Level 4.1
            'frame_rate' => 0,
        ];

        // Use FFPROBE_PATH constant if defined
        if (defined('FFPROBE_PATH')) {
            $ffprobePath = str_replace('/', '\\', FFPROBE_PATH);
        } else {
            $ffprobePath = str_replace('ffmpeg', 'ffprobe', FFMPEG_PATH);
            $ffprobePath = str_replace('/', '\\', $ffprobePath);
        }
        $inputPath = str_replace('/', '\\', $this->inputPath);

        if (!file_exists($this->inputPath)) {
            logMessage("detectVideoBitrate: Input file not found: {$this->inputPath}", 'WARNING');
            return $result;
        }

        // Get video stream info (bitrate, resolution, profile, level)
        $cmdVideo = sprintf(
            '"%s" -v error -select_streams v:0 -show_entries stream=bit_rate,width,height,profile,level,r_frame_rate -of json "%s"',
            $ffprobePath,
            $inputPath
        );

        $output = [];
        exec($cmdVideo . ' 2>&1', $output, $exitCode);
        $jsonOutput = implode('', $output);
        $data = json_decode($jsonOutput, true);

        if ($exitCode === 0 && isset($data['streams'][0])) {
            $stream = $data['streams'][0];
            $result['video_bitrate'] = isset($stream['bit_rate']) ? intval($stream['bit_rate']) : 0;
            $result['width'] = isset($stream['width']) ? intval($stream['width']) : 0;
            $result['height'] = isset($stream['height']) ? intval($stream['height']) : 0;

            // Parse frame rate (e.g., "24000/1001" -> 23.976)
            if (isset($stream['r_frame_rate'])) {
                $parts = explode('/', $stream['r_frame_rate']);
                if (count($parts) === 2 && $parts[1] > 0) {
                    $result['frame_rate'] = round($parts[0] / $parts[1], 3);
                }
            }

            // Build codec string from profile and level (e.g., avc1.64001f for High@3.1)
            if (isset($stream['profile']) && isset($stream['level'])) {
                $result['codec_profile'] = $this->buildH264CodecString($stream['profile'], $stream['level']);
            }
        }

        // Get audio stream info (bitrate)
        $cmdAudio = sprintf(
            '"%s" -v error -select_streams a:0 -show_entries stream=bit_rate -of json "%s"',
            $ffprobePath,
            $inputPath
        );

        $output = [];
        exec($cmdAudio . ' 2>&1', $output, $exitCode);
        $jsonOutput = implode('', $output);
        $data = json_decode($jsonOutput, true);

        if ($exitCode === 0 && isset($data['streams'][0]['bit_rate'])) {
            $result['audio_bitrate'] = intval($data['streams'][0]['bit_rate']);
        }

        // If video bitrate not found, try getting from format (container level)
        if ($result['video_bitrate'] === 0) {
            $cmdFormat = sprintf(
                '"%s" -v error -show_entries format=bit_rate -of json "%s"',
                $ffprobePath,
                $inputPath
            );

            $output = [];
            exec($cmdFormat . ' 2>&1', $output, $exitCode);
            $jsonOutput = implode('', $output);
            $data = json_decode($jsonOutput, true);

            if ($exitCode === 0 && isset($data['format']['bit_rate'])) {
                // Total bitrate - subtract audio to get video estimate
                $totalBitrate = intval($data['format']['bit_rate']);
                $result['video_bitrate'] = max(0, $totalBitrate - $result['audio_bitrate']);
            }
        }

        logMessage("detectVideoBitrate: video={$result['video_bitrate']}, audio={$result['audio_bitrate']}, " .
            "{$result['width']}x{$result['height']}, codec={$result['codec_profile']}", 'DEBUG');

        return $result;
    }

    /**
     * Build H.264 codec string from profile and level
     * Format: avc1.XXYYZZ where XX=profile_idc, YY=constraint_flags, ZZ=level_idc
     */
    private function buildH264CodecString(string $profile, int $level): string
    {
        // Profile IDC values
        $profileMap = [
            'Baseline' => '42',
            'Constrained Baseline' => '42',
            'Main' => '4d',
            'High' => '64',
            'High 10' => '6e',
            'High 4:2:2' => '7a',
            'High 4:4:4' => 'f4',
        ];

        $profileHex = $profileMap[$profile] ?? '64'; // Default to High

        // Level is stored as level * 10 (e.g., 31 = Level 3.1)
        $levelHex = sprintf('%02x', $level);

        // Constraint flags (00 for most cases)
        $constraintHex = '00';

        return "avc1.{$profileHex}{$constraintHex}{$levelHex}";
    }

    /**
     * Generate Master Playlist (master.m3u8) with BANDWIDTH and CODECS info
     * This enables Shaka Player to display bitrate and select quality properly
     */
    private function generateMasterPlaylist(string $outputDir, array $videoInfo): void
    {
        $masterPath = $outputDir . '/master.m3u8';

        // Calculate total bandwidth (video + audio) in bits per second
        $bandwidth = $videoInfo['video_bitrate'] + $videoInfo['audio_bitrate'];

        // If bandwidth detection failed, use a reasonable default
        if ($bandwidth < 100000) {
            $bandwidth = 2000000; // 2 Mbps default
            logMessage("generateMasterPlaylist: Using default bandwidth (2Mbps)", 'WARNING');
        }

        // Build resolution string
        $resolution = '';
        if ($videoInfo['width'] > 0 && $videoInfo['height'] > 0) {
            $resolution = "RESOLUTION={$videoInfo['width']}x{$videoInfo['height']},";
        }

        // Build frame rate string
        $frameRate = '';
        if ($videoInfo['frame_rate'] > 0) {
            $frameRate = "FRAME-RATE={$videoInfo['frame_rate']},";
        }

        // Codec string: video codec + AAC audio
        $codecs = "\"{$videoInfo['codec_profile']},mp4a.40.2\"";

        // Generate master playlist content
        $content = "#EXTM3U\n";
        $content .= "#EXT-X-VERSION:3\n";
        $content .= "\n";
        $content .= "#EXT-X-STREAM-INF:BANDWIDTH={$bandwidth},{$resolution}{$frameRate}CODECS={$codecs}\n";
        $content .= "video.m3u8\n";

        // Write master playlist
        file_put_contents($masterPath, $content);

        logMessage("Generated Master Playlist: {$masterPath} (bandwidth: {$bandwidth})", 'INFO');
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
     * Prefers master.m3u8 (with BANDWIDTH info), fallback to video.m3u8 for older videos
     */
    public static function getPlaylistPath($videoId)
    {
        $baseDir = HLS_OUTPUT_DIR . '/' . $videoId;
        $masterPath = $baseDir . '/master.m3u8';
        $videoPath = $baseDir . '/video.m3u8';

        // Prefer master.m3u8 if it exists (has BANDWIDTH/CODECS for Shaka Player)
        if (file_exists($masterPath)) {
            return $masterPath;
        }

        // Fallback to video.m3u8 for backward compatibility
        return $videoPath;
    }
}
