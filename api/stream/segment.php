<?php
/**
 * TS Segment Proxy
 * Validates signature and streams segment content
 * 
 * GET /api/stream/segment.php?v=video_id&f=filename&e=expires&s=signature
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/security.php';

// SECURITY CHECK 1: Block direct browser navigation
$secFetchDest = $_SERVER['HTTP_SEC_FETCH_DEST'] ?? '';
if ($secFetchDest === 'document') {
    http_response_code(403);
    exit('Direct access not allowed');
}

// SECURITY CHECK 2: Verify Referer from allowed domains
$referer = $_SERVER['HTTP_REFERER'] ?? '';
$allowedDomains = ['anikenji.live', 'service.anikenji.live', 'localhost', '127.0.0.1'];
$refererValid = false;

foreach ($allowedDomains as $domain) {
    if (stripos($referer, $domain) !== false) {
        $refererValid = true;
        break;
    }
}

// Block if no valid referer (direct access from browser, VLC, etc.)
if (empty($referer) || !$refererValid) {
    http_response_code(403);
    exit('Access denied - invalid referer');
}

// CORS header
header('Access-Control-Allow-Origin: *');

$videoId = $_GET['v'] ?? '';
$filename = $_GET['f'] ?? '';
$expires = intval($_GET['e'] ?? 0);
$signature = $_GET['s'] ?? '';

// Validate parameters
if (empty($videoId) || empty($filename) || empty($expires) || empty($signature)) {
    http_response_code(400);
    exit('Bad request');
}

// Validate signature
if (!validateStreamSignature($videoId, $filename, $expires, $signature)) {
    http_response_code(403);
    exit('Forbidden');
}

// Sanitize filename to prevent directory traversal
$filename = basename($filename);

// Build file path
$filePath = HLS_OUTPUT_DIR . '/' . $videoId . '/' . $filename;

if (!file_exists($filePath)) {
    http_response_code(404);
    exit('Not found');
}

// Get file info
$fileSize = filesize($filePath);
$mimeType = 'video/mp2t';

// Handle range requests for seeking
$start = 0;
$end = $fileSize - 1;

if (isset($_SERVER['HTTP_RANGE'])) {
    $range = $_SERVER['HTTP_RANGE'];
    if (preg_match('/bytes=(\d*)-(\d*)/i', $range, $matches)) {
        $start = $matches[1] !== '' ? intval($matches[1]) : 0;
        $end = $matches[2] !== '' ? intval($matches[2]) : $fileSize - 1;
    }

    http_response_code(206);
    header("Content-Range: bytes $start-$end/$fileSize");
}

$length = $end - $start + 1;

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . $length);
header('Accept-Ranges: bytes');
header('Cache-Control: public, max-age=86400');

// Stream file
$fp = fopen($filePath, 'rb');
fseek($fp, $start);

$bufferSize = 8192;
$remaining = $length;

while ($remaining > 0 && !feof($fp) && connection_status() === 0) {
    $readSize = min($bufferSize, $remaining);
    echo fread($fp, $readSize);
    $remaining -= $readSize;
    flush();
}

fclose($fp);
