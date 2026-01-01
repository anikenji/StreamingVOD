<?php
/**
 * Audio Track Upload API
 * Uploads and encodes audio file to HLS format
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

header('Content-Type: application/json');

try {
    requireAuth();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Method not allowed');
    }

    $videoId = $_POST['video_id'] ?? '';
    $language = $_POST['language'] ?? '';  // e.g., 'vi', 'en', 'ja'
    $label = $_POST['label'] ?? '';        // e.g., 'Tiếng Việt', 'English'
    $isDefault = isset($_POST['is_default']) && $_POST['is_default'] === 'true';

    if (empty($videoId) || empty($language) || empty($label)) {
        throw new Exception('Missing required fields (video_id, language, label)');
    }

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('File upload failed');
    }

    $file = $_FILES['file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    $allowedExts = ['mp3', 'aac', 'm4a', 'wav', 'flac', 'ogg', 'opus'];
    if (!in_array($ext, $allowedExts)) {
        throw new Exception('Invalid audio format. Allowed: mp3, aac, m4a, wav, flac, ogg, opus');
    }

    // Create audio output directory
    $videoDir = HLS_OUTPUT_DIR . '/' . $videoId;
    $audioDir = $videoDir . '/audio';

    if (!is_dir($audioDir)) {
        if (!mkdir($audioDir, 0777, true)) {
            throw new Exception('Failed to create audio directory');
        }
    }

    // FFmpeg encode to HLS
    $ffmpegPath = defined('FFMPEG_PATH') ? FFMPEG_PATH : 'ffmpeg';
    // Use forward slashes for FFmpeg paths on Windows
    $ffmpegPathSafe = str_replace('\\', '/', $ffmpegPath);
    $inputPath = $file['tmp_name'];
    $playlistPath = str_replace('\\', '/', $audioDir . '/' . $language . '.m3u8');
    $audioDirSafe = str_replace('\\', '/', $audioDir);

    // Detect audio channels from file
    $ffprobePath = defined('FFPROBE_PATH') ? FFPROBE_PATH : str_replace('ffmpeg', 'ffprobe', $ffmpegPath);
    $ffprobePathSafe = str_replace('\\', '/', $ffprobePath);
    $probeCmd = sprintf(
        '"%s" -v error -select_streams a:0 -show_entries stream=channels -of csv=p=0 "%s"',
        $ffprobePathSafe,
        $inputPath
    );
    $detectedChannels = (int) trim(shell_exec($probeCmd . ' 2>&1'));

    // Use audio_type from form to override target channels
    $audioType = $_POST['audio_type'] ?? 'stereo';  // 'stereo' or 'surround'
    $targetChannels = ($audioType === 'surround') ? 6 : 2;

    // Use detected channels if less than target (don't upmix)
    $channels = min($detectedChannels > 0 ? $detectedChannels : $targetChannels, $targetChannels);

    logMessage("Audio upload: detected={$detectedChannels}ch, type={$audioType}, target={$channels}ch", 'DEBUG');

    // Encode to AAC HLS
    $cmd = sprintf(
        '"%s" -i "%s" ' .
        '-c:a aac -b:a 192k -ar 48000 -ac %d ' .
        '-f hls -hls_time 2 -hls_playlist_type vod ' .
        '-hls_segment_type fmp4 ' .
        '-hls_fmp4_init_filename "init_%s.mp4" ' .
        '-hls_segment_filename "%s/audio_%s_%%04d.m4s" ' .
        '"%s" -y 2>&1',
        $ffmpegPathSafe,
        $inputPath,
        min($channels, 6),  // Max 6 channels (5.1)
        $language,
        $audioDirSafe,
        $language,
        $playlistPath
    );

    logMessage("Audio encode command: $cmd", 'DEBUG');

    $output = [];
    exec($cmd, $output, $exitCode);

    if ($exitCode !== 0) {
        logMessage("FFmpeg audio encoding failed: " . implode("\n", $output), 'ERROR');
        throw new Exception('Failed to encode audio file');
    }

    // Save to database
    $db = Database::getInstance();

    // If setting as default, unset other defaults first
    if ($isDefault) {
        $sql = "UPDATE audio_tracks SET is_default = FALSE WHERE video_id = ?";
        $db->execute($sql, [$videoId]);
    }

    $relativePath = 'audio/' . $language . '.m3u8';

    $sql = "INSERT INTO audio_tracks (video_id, language, label, channels, codec, is_default, file_path) 
            VALUES (?, ?, ?, ?, 'aac', ?, ?)
            ON DUPLICATE KEY UPDATE 
            label = VALUES(label),
            channels = VALUES(channels),
            is_default = VALUES(is_default),
            file_path = VALUES(file_path)";

    $db->execute($sql, [$videoId, $language, $label, $channels, $isDefault, $relativePath]);

    logMessage("Audio track uploaded: {$language} ({$label}) for video {$videoId}", 'INFO');

    // Regenerate master playlist with audio tracks
    regenerateMasterPlaylist($videoId, $db);

    echo json_encode([
        'success' => true,
        'file_path' => $relativePath,
        'channels' => $channels
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

/**
 * Regenerate master.m3u8 with audio tracks
 */
function regenerateMasterPlaylist($videoId, $db)
{
    $videoDir = HLS_OUTPUT_DIR . '/' . $videoId;
    $masterPath = $videoDir . '/master.m3u8';

    // Read existing master playlist to get video info
    if (!file_exists($masterPath)) {
        return;
    }

    $existingContent = file_get_contents($masterPath);

    // Extract STREAM-INF line
    preg_match('/#EXT-X-STREAM-INF:(.+)\n(.+\.m3u8)/', $existingContent, $matches);
    if (empty($matches)) {
        return;
    }

    $streamInf = $matches[1];
    $videoPlaylist = $matches[2];

    // Get audio tracks from database
    $sql = "SELECT * FROM audio_tracks WHERE video_id = ? ORDER BY is_default DESC, language ASC";
    $audioTracks = $db->query($sql, [$videoId]);

    // Build new master playlist
    $content = "#EXTM3U\n";
    $content .= "#EXT-X-VERSION:7\n";
    $content .= "#EXT-X-INDEPENDENT-SEGMENTS\n";
    $content .= "\n";

    if (!empty($audioTracks)) {
        // Add EXT-X-MEDIA for each audio track
        foreach ($audioTracks as $track) {
            $default = $track['is_default'] ? 'YES' : 'NO';
            $channels = $track['channels'];
            $content .= sprintf(
                '#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID="audio",NAME="%s",LANGUAGE="%s",CHANNELS="%d",AUTOSELECT=YES,DEFAULT=%s,URI="%s"' . "\n",
                $track['label'],
                $track['language'],
                $channels,
                $default,
                $track['file_path']
            );
        }
        $content .= "\n";

        // Add AUDIO group to STREAM-INF
        if (strpos($streamInf, 'AUDIO=') === false) {
            $streamInf .= ',AUDIO="audio"';
        }
    }

    $content .= "#EXT-X-STREAM-INF:{$streamInf}\n";
    $content .= "{$videoPlaylist}\n";

    file_put_contents($masterPath, $content);
    logMessage("Regenerated master playlist with " . count($audioTracks) . " audio tracks for video {$videoId}", 'INFO');
}
