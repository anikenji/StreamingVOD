<?php
/**
 * Video Upload API
 * POST /api/videos/upload.php
 * Supports chunked upload for large files
 */

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
if (isset($_POST['chunk'])) {
    handleChunkedUpload();
} else {
    handleSingleUpload();
}

/**
 * Handle chunked upload
 */
function handleChunkedUpload() {
    global $userId;
    
    $videoId = $_POST['video_id'] ?? '';
    $chunkIndex = intval($_POST['chunk_index'] ?? 0);
    $totalChunks = intval($_POST['total_chunks'] ?? 1);
    $originalFilename = $_POST['original_filename'] ?? '';
    
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
        // Merge chunks
        $finalPath = mergeChunks($videoId, $originalFilename, $totalChunks);
        
        if ($finalPath) {
            // Create video record and encoding jobs
            createVideoRecord($videoId, $userId, $originalFilename, $finalPath);
            
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
function handleSingleUpload() {
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
    createVideoRecord($videoId, $userId, $originalFilename, $finalPath);
    
    successResponse([
        'video_id' => $videoId
    ], 'Upload successful. Encoding started.');
}

/**
 * Merge uploaded chunks into single file
 */
function mergeChunks($videoId, $originalFilename, $totalChunks) {
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
function createVideoRecord($videoId, $userId, $originalFilename, $filePath) {
    $db = db();
    
    // Get video info
    $fileSize = getFileSize($filePath);
    $duration = getVideoDuration($filePath);
    
    // Create video record
    $sql = "INSERT INTO videos (id, user_id, original_filename, original_path, file_size, duration, status) 
            VALUES (?, ?, ?, ?, ?, ?, 'pending')";
    
    $db->execute($sql, [$videoId, $userId, $originalFilename, $filePath, $fileSize, $duration]);
    
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
}
