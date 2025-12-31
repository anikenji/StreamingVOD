<?php
/**
 * Shaka Player Embed Page with Netflix Skin
 * Supports: /embed/{id} or /embed.php?id=xxx
 * Features: Continue watching, gradient controls, secured streaming, DASH support
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

// Try to get video ID from query string first
$videoId = $_GET['id'] ?? '';

// If not in query string, try to parse from URL path: /embed/{id}
if (empty($videoId)) {
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    $path = parse_url($requestUri, PHP_URL_PATH);
    if (preg_match('#/embed/([a-zA-Z0-9_-]+)#', $path, $matches)) {
        $videoId = $matches[1];
    }
}

if (empty($videoId)) {
    die('Video ID required');
}

// Check if videoId is an encrypted token or plain ID
$decodedId = decryptVideoToken($videoId);
if ($decodedId !== false) {
    $videoId = $decodedId;
}

$db = db();

// Get video info
$sql = "SELECT * FROM videos WHERE id = ?";
$video = $db->queryOne($sql, [$videoId]);

if (!$video) {
    die('Video not found');
}

if ($video['status'] !== 'completed') {
    die('Video is still processing. Please check back later.');
}

// Generate encrypted stream token for secure playback
$streamToken = encryptVideoToken($videoId);
// HLS URL for iOS/Safari (fallback)
$securedPlaylistUrl = BASE_URL . 'api/stream/playlist.php?token=' . urlencode($streamToken) . '&format=hls';
// DASH URL for PC/Android (AV1 quality)
$securedDashUrl = BASE_URL . 'api/stream/playlist.php?token=' . urlencode($streamToken) . '&format=dash';

// Check if DASH manifest exists
$dashManifestExists = file_exists(HLS_OUTPUT_DIR . '/' . $videoId . '/manifest.mpd');

$title = htmlspecialchars($video['original_filename']);
$thumbnailUrl = $video['thumbnail_path'] ? THUMBNAIL_BASE_URL . '/' . $videoId . '.jpg' : '';

// Episode metadata
$subtitleUrl = $video['subtitle_url'] ?? null;
// Fetch multi-language subtitles
$subSql = "SELECT id, language, label, file_path, mime_type FROM subtitles WHERE video_id = ? ORDER BY language ASC";
$dbSubtitles = $db->query($subSql, [$videoId]);
$subtitles = [];

// Add single subtitleUrl if exists (legacy)
if ($subtitleUrl) {
    $subtitles[] = [
        'language' => 'vi', // Assume default is Vietnamese
        'label' => 'Tiếng Việt',
        'url' => $subtitleUrl,
        'mime' => 'text/vtt'
    ];
}

foreach ($dbSubtitles as $sub) {
    // URL via Proxy
    // We reused streamToken which is already generated for video
    // api/stream/subtitle.php?token=...&id=...
    $url = BASE_URL . 'api/stream/subtitle.php?token=' . urlencode($streamToken) . '&id=' . $sub['id'];

    $subtitles[] = [
        'language' => $sub['language'],
        'label' => $sub['label'],
        'url' => $url,
        'mime' => $sub['mime_type'] ?: 'text/vtt'
    ];
}


$introStart = $video['intro_start'] ?? null;
$introEnd = $video['intro_end'] ?? null;
$outroStart = $video['outro_start'] ?? null;
$outroEnd = $video['outro_end'] ?? null;
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $title ?> - Streaming</title>

    <!-- Shaka Player CSS -->
    <link rel="stylesheet" href="/js/shaka/styles.css?v=<?= time() ?>">
    <script>
        // 1. Vô hiệu hóa chuột phải
        document.addEventListener('contextmenu', function (e) {
            e.preventDefault();
        });
        // 2. Vô hiệu hóa các phím tắt phổ biến để mở DevTools
        document.addEventListener('keydown', function (e) {
            // F12
            if (e.keyCode === 123) {
                e.preventDefault();
            }
            // Ctrl+Shift+I
            if (e.ctrlKey && e.shiftKey && e.code === 'KeyI') {
                e.preventDefault();
            }
            // Ctrl+Shift+C
            if (e.ctrlKey && e.shiftKey && e.code === 'KeyC') {
                e.preventDefault();
            }
            // Ctrl+Shift+J
            if (e.ctrlKey && e.shiftKey && e.code === 'KeyJ') {
                e.preventDefault();
            }
            // Ctrl+U
            if (e.ctrlKey && e.code === 'KeyU') {
                e.preventDefault();
            }
        });
        // const devtools = {
        //     isOpen: false,
        //     orientation: null
        // };

        // const threshold = 160;

        // // Skip devtools detection on mobile devices (false positives from dynamic toolbars)
        // const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
        // const isAndroid = /Android/i.test(navigator.userAgent);
        // const isSafari = /^((?!chrome|android).)*safari/i.test(navigator.userAgent);
        // const isMobile = isIOS || isAndroid || /Mobile|webOS|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
        // const skipDevtoolsCheck = isMobile || isSafari;

        // const emitEvent = (isOpen, orientation) => {
        //     window.dispatchEvent(new CustomEvent('devtoolschange', {
        //         detail: {
        //             isOpen,
        //             orientation
        //         }
        //     }));
        // };

        // const checkDevTools = () => {
        //     // Skip on iOS/Safari to prevent false positives
        //     if (skipDevtoolsCheck) return;

        //     const widthThreshold = window.outerWidth - window.innerWidth > threshold;
        //     const heightThreshold = window.outerHeight - window.innerHeight > threshold;
        //     const orientation = widthThreshold ? 'vertical' : 'horizontal';

        //     if (
        //         !(heightThreshold && widthThreshold) &&
        //         ((window.Firebug && window.Firebug.chrome && window.Firebug.chrome.isInitialized) || widthThreshold || heightThreshold)
        //     ) {
        //         if (!devtools.isOpen || devtools.orientation !== orientation) {
        //             emitEvent(true, orientation);
        //         }
        //         devtools.isOpen = true;
        //         devtools.orientation = orientation;
        //     } else {
        //         if (devtools.isOpen) {
        //             emitEvent(false, null);
        //         }
        //         devtools.isOpen = false;
        //         devtools.orientation = null;
        //     }
        // };

        // setInterval(checkDevTools, 1);

        // // Lắng nghe sự kiện và thực hiện hành động
        // window.addEventListener('devtoolschange', event => {
        //     if (event.detail.isOpen) {
        //         // Hide body immediately for instant effect
        //         document.body.style.display = 'none';
        //         window.location.reload();
        //     }
        // });
    </script>
</head>

<body class="shaka-embed">
    <!-- Player Container -->
    <div class="shaka-player-container paused" id="playerContainer">
        <!-- Video Element -->
        <video id="video" poster="<?= $thumbnailUrl ?>" preload="metadata"></video>

        <!-- Loading Spinner -->
        <div class="shaka-spinner"></div>

        <!-- Center Play Button -->
        <div class="shaka-center-play">
            <svg viewBox="0 0 24 24">
                <path d="M8 5v14l11-7z" />
            </svg>
        </div>

        <!-- Skip Intro Button -->
        <button class="shaka-skip-button shaka-skip-intro">Bỏ qua Opening</button>

        <!-- Skip Outro Button -->
        <button class="shaka-skip-button shaka-skip-outro">Bỏ qua Ending</button>

        <!-- Resume Backdrop (blur effect) -->
        <div class="shaka-resume-backdrop" id="resumeBackdrop"></div>

        <!-- Resume Modal -->
        <div class="shaka-resume-modal" id="resumeModal">
            <h3>Tiếp tục xem?</h3>
            <p>Tiếp tục từ <span class="time">0:00</span></p>
            <div class="shaka-modal-btns">
                <button class="shaka-modal-btn primary shaka-btn-resume">Tiếp tục</button>
                <button class="shaka-modal-btn secondary shaka-btn-restart">Xem lại từ đầu</button>
            </div>
        </div>

        <!-- Stats Overlay -->
        <div class="shaka-stats-overlay" id="statsOverlay"></div>

        <!-- Controls Overlay -->
        <div class="shaka-controls-overlay">
            <!-- Progress Bar -->
            <div class="shaka-progress-container">
                <div class="shaka-progress-buffered"></div>
                <div class="shaka-progress-played"></div>
                <div class="shaka-progress-handle"></div>
            </div>

            <!-- Control Bar -->
            <div class="shaka-control-bar">
                <!-- Left Controls -->
                <div class="shaka-control-group">
                    <!-- Play/Pause -->
                    <button class="shaka-btn shaka-btn-play" title="Play/Pause">
                        <svg viewBox="0 0 24 24">
                            <path d="M8 5v14l11-7z" />
                        </svg>
                    </button>

                    <!-- Rewind 10s -->
                    <button class="shaka-btn shaka-btn-rewind" title="Rewind 10s">
                        <svg viewBox="0 0 24 24">
                            <path d="M11 18V6l-8.5 6 8.5 6zm.5-6l8.5 6V6l-8.5 6z" />
                        </svg>
                    </button>

                    <!-- Forward 10s -->
                    <button class="shaka-btn shaka-btn-forward" title="Forward 10s">
                        <svg viewBox="0 0 24 24" style="transform: scaleX(-1)">
                            <path d="M11 18V6l-8.5 6 8.5 6zm.5-6l8.5 6V6l-8.5 6z" />
                        </svg>
                    </button>

                    <!-- Volume -->
                    <div class="shaka-volume-container">
                        <button class="shaka-btn shaka-btn-mute" title="Mute">
                            <svg viewBox="0 0 24 24">
                                <path
                                    d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z" />
                            </svg>
                        </button>
                        <div class="shaka-volume-slider-container">
                            <div class="shaka-volume-slider">
                                <div class="shaka-volume-level" style="width: 100%"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Time Display -->
                    <span class="shaka-time-display">0:00 / 0:00</span>
                </div>

                <!-- Right Controls -->
                <div class="shaka-control-group">
                    <!-- Settings -->
                    <button class="shaka-btn shaka-btn-settings" title="Settings">
                        <svg viewBox="0 0 24 24">
                            <path
                                d="M19.14 12.94c.04-.31.06-.63.06-.94 0-.31-.02-.63-.06-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.04.31-.06.63-.06.94s.02.63.06.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z" />
                        </svg>
                    </button>

                    <!-- PiP -->
                    <button class="shaka-btn shaka-btn-pip" title="Picture in Picture">
                        <svg viewBox="0 0 24 24">
                            <path
                                d="M19 7h-8v6h8V7zm2-4H3c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h18c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H3V5h18v14z" />
                        </svg>
                    </button>

                    <!-- Fullscreen -->
                    <button class="shaka-btn shaka-btn-fullscreen" title="Fullscreen">
                        <svg viewBox="0 0 24 24">
                            <path d="M7 14H5v5h5v-2H7v-3zm-2-4h2V7h3V5H5v5zm12 7h-3v2h5v-5h-2v3zM14 5v2h3v3h2V5h-5z" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>

        <!-- Settings Menu -->
        <div class="shaka-settings-menu" id="settingsMenu"></div>
    </div>

    <!-- Shaka Player Library -->
    <script src="/js/shaka/shaka-player.compiled.min.js"></script>

    <!-- Custom Player Controller -->
    <script src="/js/shaka/player.js?v=<?= time() ?>"></script>

    <script>
        // Initialize Shaka Player
        document.addEventListener('DOMContentLoaded', function () {
            const video = document.getElementById('video');

            const playerController = new ShakaPlayerController(video, {
                videoId: <?= json_encode($videoId) ?>,
                manifestUrl: <?= json_encode($securedPlaylistUrl) ?>,
                dashUrl: <?= json_encode($dashManifestExists ? $securedDashUrl : '') ?>,
                posterUrl: <?= json_encode($thumbnailUrl) ?>,
                subtitles: <?= json_encode($subtitles) ?>,
                introStart: <?= json_encode($introStart !== null ? floatval($introStart) : null) ?>,
                introEnd: <?= json_encode($introEnd !== null ? floatval($introEnd) : null) ?>,
                outroStart: <?= json_encode($outroStart !== null ? floatval($outroStart) : null) ?>,
                outroEnd: <?= json_encode($outroEnd !== null ? floatval($outroEnd) : null) ?>
            });

            // Expose for debugging
            window.shakaController = playerController;
        });
    </script>
</body>

</html>