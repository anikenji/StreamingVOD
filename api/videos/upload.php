<?php
/**
 * Video Upload API
 * POST /api/videos/upload.php
 * Supports chunked upload for large files
 */

// Suppress HTML error output - API must return JSON only
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Set JSON content type early
header('Content-Type: application/json');

// Custom error handler to return JSON instead of HTML
set_error_handler(function ($severity, $message, $file, $line) {
    // Log the error
    error_log("PHP Error [$severity]: $message in $file on line $line");

    // Don't die on warnings/notices, just log them
    if ($severity === E_WARNING || $severity === E_NOTICE || $severity === E_DEPRECATED) {
        return true; // Continue execution
    }

    // For fatal-ish errors, return JSON error
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// Handle uncaught exceptions
set_exception_handler(function ($e) {
    error_log("Uncaught exception: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
    exit;
});

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

enableCORS();

// Require authentication
requireAuth();

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Method not allowed', 405);
}

$userId = getCurrentUserId();

// Handle chunked upload
if (isset($_POST['chunk_index']) || isset($_FILES['chunk'])) {
    handleChunkedUpload();
} else {
    handleSingleUpload();
}

/**
 * Handle chunked upload
 */
function handleChunkedUpload()
{
    global $userId;

    $videoId = $_POST['video_id'] ?? '';
    $chunkIndex = intval($_POST['chunk_index'] ?? 0);
    $totalChunks = intval($_POST['total_chunks'] ?? 1);
    $originalFilename = $_POST['original_filename'] ?? '';
    $movieId = isset($_POST['movie_id']) ? intval($_POST['movie_id']) : null;
    $episodeNumber = isset($_POST['episode_number']) ? intval($_POST['episode_number']) : null;

    if (empty($videoId) || empty($originalFilename)) {
        errorResponse('Missing required parameters');
    }

    // Validate file extension
    if (!validateVideoFile($originalFilename)) {
        errorResponse('Invalid file type. Allowed: ' . implode(', ', ALLOWED_EXTENSIONS));
    }

    // Create temp directory for chunks
    $tempDir = TEMP_DIR . '/' . $videoId;
    ensureDirectory($tempDir);

    // Store movie_id and episode_number in metadata file (for when merging)
    $metadataFile = $tempDir . '/metadata.json';
    if ($movieId || $episodeNumber) {
        $metadata = [];
        if (file_exists($metadataFile)) {
            $metadata = json_decode(file_get_contents($metadataFile), true) ?: [];
        }
        if ($movieId)
            $metadata['movie_id'] = $movieId;
        if ($episodeNumber)
            $metadata['episode_number'] = $episodeNumber;
        file_put_contents($metadataFile, json_encode($metadata));
    }

    // Save chunk
    $chunkFile = $tempDir . '/chunk_' . str_pad($chunkIndex, 4, '0', STR_PAD_LEFT);

    if (isset($_FILES['chunk'])) {
        move_uploaded_file($_FILES['chunk']['tmp_name'], $chunkFile);
    } else {
        errorResponse('No file uploaded');
    }

    // Check if all chunks uploaded
    $uploadedChunks = count(glob($tempDir . '/chunk_*'));

    if ($uploadedChunks >= $totalChunks) {
        // Read metadata
        $metadata = [];
        if (file_exists($metadataFile)) {
            $metadata = json_decode(file_get_contents($metadataFile), true) ?: [];
        }
        $finalMovieId = $metadata['movie_id'] ?? null;
        $finalEpisodeNumber = $metadata['episode_number'] ?? null;

        // Merge chunks
        $finalPath = mergeChunks($videoId, $originalFilename, $totalChunks);

        if ($finalPath) {
            // Create video record and encoding jobs with movie assignment
            createVideoRecord($videoId, $userId, $originalFilename, $finalPath, $finalMovieId, $finalEpisodeNumber);

            successResponse([
                'video_id' => $videoId,
                'upload_complete' => true
            ], 'Upload complete. Encoding started.');
        } else {
            errorResponse('Failed to merge chunks', 500);
        }
    } else {
        // More chunks expected
        successResponse([
            'video_id' => $videoId,
            'chunk_index' => $chunkIndex,
            'uploaded_chunks' => $uploadedChunks,
            'total_chunks' => $totalChunks
        ]);
    }
}

/**
 * Handle single file upload
 */
function handleSingleUpload()
{
    global $userId;

    if (!isset($_FILES['video'])) {
        errorResponse('No file uploaded');
    }

    $file = $_FILES['video'];
    $originalFilename = sanitizeFilename($file['name']);

    // Validate file
    if (!validateVideoFile($originalFilename)) {
        errorResponse('Invalid file type. Allowed: ' . implode(', ', ALLOWED_EXTENSIONS));
    }

    if ($file['size'] > MAX_UPLOAD_SIZE) {
        errorResponse('File too large. Maximum: ' . formatBytes(MAX_UPLOAD_SIZE));
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        errorResponse('Upload error: ' . $file['error']);
    }

    // Get movie_id and episode_number if provided
    $movieId = isset($_POST['movie_id']) ? intval($_POST['movie_id']) : null;
    $episodeNumber = isset($_POST['episode_number']) ? intval($_POST['episode_number']) : null;

    // Generate video ID
    $videoId = generateUUID();

    // Create upload directory
    $uploadDir = UPLOAD_DIR . '/' . $videoId;
    ensureDirectory($uploadDir);

    // Move uploaded file
    $extension = getFileExtension($originalFilename);
    $finalPath = $uploadDir . '/original.' . $extension;

    if (!move_uploaded_file($file['tmp_name'], $finalPath)) {
        errorResponse('Failed to save file', 500);
    }

    // Create video record and encoding jobs
    createVideoRecord($videoId, $userId, $originalFilename, $finalPath, $movieId, $episodeNumber);

    successResponse([
        'video_id' => $videoId
    ], 'Upload successful. Encoding started.');
}

/**
 * Merge uploaded chunks into single file
 */
function mergeChunks($videoId, $originalFilename, $totalChunks)
{
    $tempDir = TEMP_DIR . '/' . $videoId;
    $uploadDir = UPLOAD_DIR . '/' . $videoId;
    ensureDirectory($uploadDir);

    $extension = getFileExtension($originalFilename);
    $finalPath = $uploadDir . '/original.' . $extension;

    $finalFile = fopen($finalPath, 'wb');

    if (!$finalFile) {
        return false;
    }

    for ($i = 0; $i < $totalChunks; $i++) {
        $chunkFile = $tempDir . '/chunk_' . str_pad($i, 4, '0', STR_PAD_LEFT);

        if (!file_exists($chunkFile)) {
            fclose($finalFile);
            return false;
        }

        $chunk = fopen($chunkFile, 'rb');
        stream_copy_to_stream($chunk, $finalFile);
        fclose($chunk);
        unlink($chunkFile);
    }

    fclose($finalFile);

    // Remove temp directory
    rmdir($tempDir);

    return $finalPath;
}

/**
 * Create video record in database and encoding job
 */
function createVideoRecord($videoId, $userId, $originalFilename, $filePath, $movieId = null, $episodeNumber = null)
{
    $db = db();

    // Get video info
    $fileSize = getFileSize($filePath);
    $duration = getVideoDuration($filePath);

    // Create video record with optional movie assignment
    $sql = "INSERT INTO videos (id, user_id, movie_id, episode_number, original_filename, original_path, file_size, duration, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')";

    $db->execute($sql, [$videoId, $userId, $movieId, $episodeNumber, $originalFilename, $filePath, $fileSize, $duration]);

    // Create single encoding job for source quality
    $totalFrames = $duration ? intval($duration * 30) : 0; // Estimate 30fps

    $jobSql = "INSERT INTO encoding_jobs (video_id, quality, status, total_frames) 
               VALUES (?, 'source', 'pending', ?)";
    $db->execute($jobSql, [$videoId, $totalFrames]);

    // Generate thumbnail (async, don't wait)
    $thumbnailPath = THUMBNAIL_DIR . '/' . $videoId . '.jpg';
    generateThumbnail($filePath, $thumbnailPath, 5);

    if (file_exists($thumbnailPath)) {
        $db->execute("UPDATE videos SET thumbnail_path = ? WHERE id = ?", [$thumbnailPath, $videoId]);
    }

    logMessage("Video uploaded: $videoId - $originalFilename (User: $userId)", 'INFO');

    // Trigger background worker to process the video
    triggerBackgroundWorker();
}

/**
 * Trigger background worker to process pending videos
 * Runs the run-once.php script in the background without blocking
 */
function triggerBackgroundWorker()
{
    $workerScript = realpath(__DIR__ . '/../../workers/run-once.php');
    $lockFile = __DIR__ . '/../../workers/worker.lock';

    // Check if worker script exists
    if (!$workerScript || !file_exists($workerScript)) {
        logMessage("Worker script not found", 'ERROR');
        return;
    }

    // Check if worker is already running (lock file exists and is recent < 30 min)
    if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 1800) {
        logMessage("Worker already running, skipping trigger", 'DEBUG');
        return;
    }

    // Windows: Use multiple methods to try triggering
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        // Find PHP executable
        $phpPaths = [
            'C:\\wamp64\\bin\\php\\php8.2.26\\php.exe',
            'C:\\wamp64\\bin\\php\\php8.1.0\\php.exe',
            'C:\\wamp64\\bin\\php\\php8.0.0\\php.exe',
            PHP_BINARY,
            'php'
        ];

        $phpPath = 'php';
        foreach ($phpPaths as $path) {
            if (file_exists($path)) {
                $phpPath = $path;
                break;
            }
        }

        // Create a batch file to run the worker
        $batContent = sprintf(
            '@echo off' . "\r\n" .
            '"%s" "%s"' . "\r\n",
            $phpPath,
            $workerScript
        );

        $batFile = __DIR__ . '/../../workers/auto-worker.bat';
        file_put_contents($batFile, $batContent);

        // Method 1: Use cmd.exe with start /B
        $command = sprintf('cmd.exe /c start /B "" "%s"', $batFile);
        pclose(popen($command, 'r'));

        logMessage("Background worker triggered: $command", 'INFO');
    } else {
        // Linux/Mac: Use nohup
        $command = sprintf('nohup php "%s" > /dev/null 2>&1 &', $workerScript);
        exec($command);
        logMessage("Background worker triggered via nohup", 'INFO');
    }
}


