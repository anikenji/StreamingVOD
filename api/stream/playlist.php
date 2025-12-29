<?php
/**
 * M3U8 Playlist Proxy
 * Validates token and returns playlist content with signed segment URLs
 * Generates Master Playlist with CODECS for fMP4 (AV1/HEVC) compatibility
 * 
 * GET /api/stream/playlist.php?token=xxx
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/helpers.php';

// Strict CORS - Only allow configured origins
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = defined('ALLOWED_CORS_ORIGINS') ? ALLOWED_CORS_ORIGINS : [];

// Validate origin against whitelist
$originAllowed = false;
foreach ($allowedOrigins as $allowed) {
    if (strcasecmp($origin, $allowed) === 0) {
        $originAllowed = true;
        break;
    }
}

if ($originAllowed && !empty($origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Vary: Origin');
} else if (empty($origin)) {
    // Same-origin requests (direct browser access) - allow for same-site embeds
    // But do NOT send Access-Control-Allow-Origin header
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    if ($originAllowed && !empty($origin)) {
        http_response_code(204);
    } else {
        http_response_code(403);
    }
    exit;
}

// Get and validate token
$token = $_GET['token'] ?? '';
$mediaPlaylist = $_GET['media'] ?? ''; // For media playlist request

if (empty($token)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Token required']);
    exit;
}

// Decrypt token to get video ID
$videoId = decryptVideoToken($token);

if ($videoId === false) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid or expired token']);
    exit;
}

// Check video exists and is completed
$db = db();
$video = $db->queryOne("SELECT id, status FROM videos WHERE id = ?", [$videoId]);

if (!$video || $video['status'] !== 'completed') {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Video not found']);
    exit;
}

// Path definitions
$hlsDir = HLS_OUTPUT_DIR . '/' . $videoId;
$dashManifestPath = $hlsDir . '/manifest.mpd';
$playlistPath = $hlsDir . '/video.m3u8';
$initPath = $hlsDir . '/init.mp4';

// ==================== FORMAT SELECTION ====================
// Support explicit format parameter for client-controlled format selection
// - format=dash: Force DASH (for PC/Android - AV1 quality)
// - format=hls: Force HLS (for iOS/Safari fallback)
// - no format: Auto-detect based on availability

$requestedFormat = $_GET['format'] ?? '';
$hasHLS = file_exists($playlistPath);
$hasDASH = file_exists($dashManifestPath);

// Determine which format to serve
$serveDASH = false;
if ($requestedFormat === 'dash' && $hasDASH) {
    $serveDASH = true;
} elseif ($requestedFormat === 'hls' && $hasHLS) {
    $serveDASH = false;
} elseif ($hasDASH && !$hasHLS) {
    // DASH-only video
    $serveDASH = true;
}

// ==================== DASH HANDLING ====================
if ($serveDASH) {
    $segment = $_GET['segment'] ?? '';

    if (empty($segment)) {
        // Serve the DASH manifest with rewritten segment URLs
        header('Content-Type: application/dash+xml');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Access-Control-Expose-Headers: Content-Length, Content-Range');

        $content = file_get_contents($dashManifestPath);

        // Construct proxy base URL - segments will be fetched via this endpoint
        // Include format=dash to ensure segment requests are handled correctly
        $proxyBase = BASE_URL . "api/stream/playlist.php?token=" . urlencode($token) . "&format=dash&segment=";

        // Rewrite SegmentTemplate 'initialization' attribute
        // From: initialization="init-stream$RepresentationID$.m4s"
        // To:   initialization="https://.../playlist.php?token=XXX&amp;segment=init-stream$RepresentationID$.m4s"
        $content = preg_replace_callback(
            '/initialization="([^"]+)"/',
            function ($matches) use ($proxyBase) {
                // Use htmlspecialchars to escape & as &amp; for valid XML
                return 'initialization="' . htmlspecialchars($proxyBase . $matches[1], ENT_XML1, 'UTF-8') . '"';
            },
            $content
        );

        // Rewrite SegmentTemplate 'media' attribute
        // From: media="chunk-stream$RepresentationID$-$Number%05d$.m4s"
        // To:   media="https://.../playlist.php?token=XXX&amp;segment=chunk-stream$RepresentationID$-$Number%05d$.m4s"
        $content = preg_replace_callback(
            '/media="([^"]+)"/',
            function ($matches) use ($proxyBase) {
                // Use htmlspecialchars to escape & as &amp; for valid XML
                return 'media="' . htmlspecialchars($proxyBase . $matches[1], ENT_XML1, 'UTF-8') . '"';
            },
            $content
        );

        echo $content;
        exit;
    } else {
        // Serve DASH segment (init.m4s, chunk-*.m4s)
        $filename = basename($segment); // Security: prevent path traversal
        $filePath = $hlsDir . '/' . $filename;

        if (!file_exists($filePath)) {
            http_response_code(404);
            die('Segment not found: ' . htmlspecialchars($filename));
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        // Set appropriate MIME type
        if ($ext === 'mpd') {
            header('Content-Type: application/dash+xml');
        } elseif ($ext === 'm4s') {
            // Init segments need video/mp4, media segments use video/iso.segment
            if (strpos($filename, 'init-') === 0) {
                header('Content-Type: video/mp4');
            } else {
                header('Content-Type: video/iso.segment');
            }
        } elseif ($ext === 'mp4') {
            header('Content-Type: video/mp4');
        } else {
            header('Content-Type: application/octet-stream');
        }

        header('Cache-Control: public, max-age=3600');
        header('Content-Length: ' . filesize($filePath));
        header('Access-Control-Expose-Headers: Content-Length, Content-Range');

        readfile($filePath);
        exit;
    }
}

// ==================== HLS HANDLING ====================
// Check if HLS playlist exists
$isFmp4 = file_exists($initPath);

if (!file_exists($playlistPath)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Playlist not found (no HLS or DASH manifest)']);
    exit;
}

/**
 * Detect video/audio codec from init.mp4 using ffprobe
 * Returns codec string for CODECS parameter
 */
function detectCodecsFromInit($initPath)
{
    if (!file_exists($initPath) || !defined('FFPROBE_PATH')) {
        return null;
    }

    // Get video codec
    $cmd = sprintf(
        '"%s" -v error -select_streams v:0 -show_entries stream=codec_name -of csv=p=0 "%s"',
        FFPROBE_PATH,
        $initPath
    );
    $videoCodec = trim(shell_exec($cmd . ' 2>&1') ?? '');

    // Get audio codec
    $cmd = sprintf(
        '"%s" -v error -select_streams a:0 -show_entries stream=codec_name -of csv=p=0 "%s"',
        FFPROBE_PATH,
        $initPath
    );
    $audioCodec = trim(shell_exec($cmd . ' 2>&1') ?? '');

    // Map to RFC 6381 codec strings
    $videoCodecStr = '';
    $audioCodecStr = '';

    // Video codec mapping
    switch (strtolower($videoCodec)) {
        case 'av1':
            $videoCodecStr = 'av01.0.31M.08'; // AV1 Main Profile, Level 5.1
            break;
        case 'hevc':
        case 'h265':
            $videoCodecStr = 'hvc1.1.6.L120.90'; // HEVC Main Profile
            break;
        case 'h264':
        case 'avc':
            $videoCodecStr = 'avc1.64001f'; // H.264 High Profile Level 3.1
            break;
        default:
            $videoCodecStr = 'avc1.64001f'; // Fallback to H.264
    }

    // Audio codec mapping
    switch (strtolower($audioCodec)) {
        case 'aac':
            $audioCodecStr = 'mp4a.40.2'; // AAC-LC
            break;
        case 'opus':
            $audioCodecStr = 'opus';
            break;
        case 'mp3':
            $audioCodecStr = 'mp4a.40.34';
            break;
        default:
            $audioCodecStr = 'mp4a.40.2'; // Fallback to AAC
    }

    return $videoCodecStr . ',' . $audioCodecStr;
}

// Rewrite segment URLs helper
function rewriteMediaPlaylist($content, $videoId, $baseUrl)
{
    // Rewrite EXT-X-MAP (init.mp4 for fMP4 segments)
    $content = preg_replace_callback(
        '/#EXT-X-MAP:URI="([^"]+)"/',
        function ($matches) use ($videoId, $baseUrl) {
            $initFile = trim($matches[1]);
            $sig = generateStreamSignature($videoId, $initFile, 14400);
            $signedUrl = $baseUrl . '?v=' . urlencode($videoId)
                . '&f=' . urlencode($initFile)
                . '&e=' . $sig['expires']
                . '&s=' . $sig['signature'];
            return '#EXT-X-MAP:URI="' . $signedUrl . '"';
        },
        $content
    );

    // Rewrite segment URLs (.ts and .m4s files)
    $content = preg_replace_callback(
        '/^([^#].*\.(ts|m4s).*)$/m',
        function ($matches) use ($videoId, $baseUrl) {
            $segmentFile = trim($matches[1]);
            $sig = generateStreamSignature($videoId, $segmentFile, 14400);
            return $baseUrl . '?v=' . urlencode($videoId)
                . '&f=' . urlencode($segmentFile)
                . '&e=' . $sig['expires']
                . '&s=' . $sig['signature'];
        },
        $content
    );

    return $content;
}

$baseUrl = BASE_URL . 'api/stream/segment.php';

// If requesting media playlist directly (for Master Playlist reference)
if ($mediaPlaylist === '1') {
    $content = file_get_contents($playlistPath);
    $content = rewriteMediaPlaylist($content, $videoId, $baseUrl);

    header('Content-Type: application/vnd.apple.mpegurl');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo $content;
    exit;
}

// For fMP4, generate Master Playlist with CODECS
if ($isFmp4) {
    $codecs = detectCodecsFromInit($initPath);

    // Build Master Playlist
    $masterPlaylist = "#EXTM3U\n";
    $masterPlaylist .= "#EXT-X-VERSION:7\n";

    // Media playlist URL with token
    $mediaPlaylistUrl = BASE_URL . 'api/stream/playlist.php?token=' . urlencode($token) . '&media=1';

    if ($codecs) {
        $masterPlaylist .= '#EXT-X-STREAM-INF:BANDWIDTH=5000000,CODECS="' . $codecs . '"' . "\n";
    } else {
        $masterPlaylist .= "#EXT-X-STREAM-INF:BANDWIDTH=5000000\n";
    }
    $masterPlaylist .= $mediaPlaylistUrl . "\n";

    header('Content-Type: application/vnd.apple.mpegurl');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    echo $masterPlaylist;
    exit;
}

// Standard TS playlist (no Master Playlist needed)
$content = file_get_contents($playlistPath);
$content = rewriteMediaPlaylist($content, $videoId, $baseUrl);

header('Content-Type: application/vnd.apple.mpegurl');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Content-Type-Options: nosniff');
echo $content;

