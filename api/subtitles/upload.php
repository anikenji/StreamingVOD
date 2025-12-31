<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php'; // For logging if needed

header('Content-Type: application/json');

try {
    requireAuth();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Method not allowed');
    }

    $videoId = $_POST['video_id'] ?? '';
    $language = $_POST['language'] ?? ''; // e.g., 'vi', 'en'
    $label = $_POST['label'] ?? '';       // e.g., 'Tiếng Việt'

    if (empty($videoId) || empty($language) || empty($label)) {
        throw new Exception('Missing required fields (video_id, language, label)');
    }

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('File upload failed');
    }

    $file = $_FILES['file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    $allowedExts = ['vtt', 'srt', 'ass', 'ssa'];
    if (!in_array($ext, $allowedExts)) {
        throw new Exception('Invalid file format. Allowed: .vtt, .srt, .ass');
    }

    // Determine output paths
    // Define HLS_OUTPUT_DIR if not defined (should be in config)
    if (!defined('HLS_OUTPUT_DIR')) {
        define('HLS_OUTPUT_DIR', __DIR__ . '/../../storage/hls');
    }

    $videoDir = HLS_OUTPUT_DIR . '/' . $videoId;
    $subsDir = $videoDir . '/subs';

    if (!is_dir($subsDir)) {
        if (!mkdir($subsDir, 0777, true)) {
            throw new Exception('Failed to create subtitles directory');
        }
    }

    $targetFilename = $language . '.vtt';
    $targetPath = $subsDir . '/' . $targetFilename;
    $relativePath = 'storage/hls/' . $videoId . '/subs/' . $targetFilename;

    // Process file
    if ($ext === 'vtt') {
        // Already VTT, just move
        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            throw new Exception('Failed to save VTT file');
        }
    } else {
        // Convert using FFmpeg
        $ffmpegPath = defined('FFMPEG_PATH') ? FFMPEG_PATH : 'ffmpeg';
        // Handle Windows paths if needed
        $ffmpegPath = str_replace('/', '\\', $ffmpegPath);
        $inputPath = $file['tmp_name'];
        $outputPath = $targetPath;

        // Escape paths
        $cmd = sprintf(
            '"%s" -i "%s" -f webvtt "%s" -y',
            $ffmpegPath,
            $inputPath,
            $outputPath
        );

        $output = [];
        exec($cmd . ' 2>&1', $output, $exitCode);

        if ($exitCode !== 0) {
            error_log("FFmpeg subtitle conversion failed: " . implode("\n", $output));
            throw new Exception("Failed to convert subtitle to VTT");
        }
    }

    // Update Database
    $db = Database::getInstance();

    // Check if distinct entry exists
    $sql = "INSERT INTO subtitles (video_id, language, label, file_path, mime_type) 
            VALUES (?, ?, ?, ?, 'text/vtt')
            ON DUPLICATE KEY UPDATE 
            label = VALUES(label), 
            file_path = VALUES(file_path),
            created_at = NOW()";

    $db->execute($sql, [$videoId, $language, $label, $relativePath]);

    echo json_encode(['success' => true, 'file_path' => $relativePath]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
