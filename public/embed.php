<?php
/**
 * JWPlayer Embed Page with Netflix Skin
 * Supports: /embed/{id} or /embed.php?id=xxx
 * Features: Continue watching, gradient controls
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

// Try to get video ID from query string first
$videoId = $_GET['id'] ?? '';

// If not in query string, try to parse from URL path: /embed/{id}
if (empty($videoId)) {
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    $path = parse_url($requestUri, PHP_URL_PATH);
    if (preg_match('#/embed/([a-zA-Z0-9-]+)#', $path, $matches)) {
        $videoId = $matches[1];
    }
}

if (empty($videoId)) {
    die('Video ID required');
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

$masterPlaylistUrl = HLS_BASE_URL . '/' . $videoId . '/video.m3u8';
$title = htmlspecialchars($video['original_filename']);
$thumbnailUrl = $video['thumbnail_path'] ? THUMBNAIL_BASE_URL . '/' . $videoId . '.jpg' : '';
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $title ?> - HLS Streaming</title>

    <!-- JWPlayer Netflix Skin CSS -->
    <link rel="stylesheet" href="/js/jwplayer/styles.css">

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: #000;
            font-family: 'Segoe UI', Roboto, -apple-system, BlinkMacSystemFont, sans-serif;
            overflow: hidden;
        }

        #player-container {
            width: 100%;
            height: 100vh;
        }

        #player {
            width: 100%;
            height: 100%;
        }

        /* Hide video title */
        .jw-title,
        .jw-title-primary,
        .jw-title-secondary {
            display: none !important;
        }

        /* Gradient background for controls */
        .jwplayer .jw-controls {
            background: linear-gradient(0deg, rgba(0,0,0,0.9) 0%, rgba(0,0,0,0.6) 30%, transparent 60%) !important;
        }

        .jwplayer .jw-controlbar {
            background: transparent !important;
        }

        /* Continue Watching Modal */
        .resume-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.85);
            z-index: 9999;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(5px);
        }

        .resume-modal.active {
            display: flex;
        }

        .resume-modal-content {
            background: linear-gradient(145deg, #1a1a1a, #0d0d0d);
            border: 1px solid #333;
            border-radius: 16px;
            padding: 32px 40px;
            text-align: center;
            max-width: 420px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.8);
        }

        .resume-modal h3 {
            color: #fff;
            font-size: 22px;
            font-weight: 600;
            margin-bottom: 16px;
        }

        .resume-modal p {
            color: #b3b3b3;
            font-size: 15px;
            margin-bottom: 8px;
        }

        .resume-time {
            display: inline-block;
            background: #333;
            color: #ffd700;
            padding: 6px 14px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 24px;
        }

        .resume-buttons {
            display: flex;
            gap: 12px;
            justify-content: center;
        }

        .resume-btn {
            padding: 12px 28px;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .resume-btn-continue {
            background: #28a745;
            color: #fff;
        }

        .resume-btn-continue:hover {
            background: #218838;
            transform: scale(1.02);
        }

        .resume-btn-restart {
            background: #dc3545;
            color: #fff;
        }

        .resume-btn-restart:hover {
            background: #c82333;
            transform: scale(1.02);
        }
    </style>
</head>

<body>
    <!-- Resume Modal -->
    <div class="resume-modal" id="resumeModal">
        <div class="resume-modal-content">
            <h3>THÔNG BÁO!</h3>
            <p>Bạn đã dừng lại ở</p>
            <span class="resume-time" id="resumeTimeDisplay">0:00</span>
            <div class="resume-buttons">
                <button class="resume-btn resume-btn-continue" id="btnResume">Tiếp tục xem</button>
                <button class="resume-btn resume-btn-restart" id="btnRestart">Xem lại từ đầu</button>
            </div>
        </div>
    </div>

    <div id="player-container">
        <div id="player"></div>
    </div>

    <!-- JWPlayer Core -->
    <script src="/js/jwplayer/jwplayer-8.9.3.js"></script>
    <script>jwplayer.key = "<?= JWPLAYER_KEY ?>";</script>

    <!-- HLS.js for HLS playback -->
    <script src="/js/jwplayer/hls.min.js"></script>
    <script src="/js/jwplayer/jwplayer.hlsjs.min.js"></script>

    <script>
        const VIDEO_ID = "<?= $videoId ?>";
        const COOKIE_NAME = `watch_progress_${VIDEO_ID}`;
        
        // Cookie helpers
        function setCookie(name, value, days = 30) {
            const expires = new Date(Date.now() + days * 864e5).toUTCString();
            document.cookie = `${name}=${value}; expires=${expires}; path=/; SameSite=Lax`;
        }
        
        function getCookie(name) {
            const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
            return match ? parseFloat(match[2]) : null;
        }
        
        function deleteCookie(name) {
            document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;`;
        }
        
        // Format time display
        function formatTime(seconds) {
            const mins = Math.floor(seconds / 60);
            const secs = Math.floor(seconds % 60);
            return `${mins} phút ${secs} giây`;
        }

        // Initialize JWPlayer
        const playerInstance = jwplayer("player").setup({
            controls: true,
            displaytitle: false,
            displaydescription: false,

            skin: {
                name: "netflix"
            },

            captions: {
                color: "#FFF",
                fontSize: 14,
                backgroundOpacity: 0,
                edgeStyle: "raised"
            },

            playlist: [{
                image: "<?= $thumbnailUrl ?>",
                sources: [{
                    file: "<?= $masterPlaylistUrl ?>",
                    type: "hls"
                }]
            }],

            width: "100%",
            height: "100%",
            aspectratio: "16:9",
            autostart: false,
            primary: "html5",
            hlshtml: true,
            preload: "metadata"
        });

        // Resume watching logic
        let savedPosition = getCookie(COOKIE_NAME);
        let hasShownModal = false;

        playerInstance.on("ready", function() {
            // Check for saved position and show modal
            if (savedPosition && savedPosition > 10) {
                document.getElementById("resumeTimeDisplay").textContent = formatTime(savedPosition);
                document.getElementById("resumeModal").classList.add("active");
                hasShownModal = true;
            }
            
            // Custom controls
            try {
                const playerContainer = playerInstance.getContainer();
                const buttonContainer = playerContainer.querySelector(".jw-button-container");
                const spacer = buttonContainer.querySelector(".jw-spacer");
                const timeSlider = playerContainer.querySelector(".jw-slider-time");
                if (spacer && timeSlider) {
                    buttonContainer.replaceChild(timeSlider, spacer);
                }

                // Hide next button
                const nextBtn = playerContainer.querySelector(".jw-display-icon-next");
                if (nextBtn) {
                    nextBtn.style.display = "none";
                }
            } catch (e) {
                console.log("Skin customization error:", e);
            }
        });

        // Modal button handlers
        document.getElementById("btnResume").addEventListener("click", function() {
            document.getElementById("resumeModal").classList.remove("active");
            playerInstance.seek(savedPosition);
            playerInstance.play();
        });

        document.getElementById("btnRestart").addEventListener("click", function() {
            document.getElementById("resumeModal").classList.remove("active");
            deleteCookie(COOKIE_NAME);
            savedPosition = 0;
            playerInstance.seek(0);
            playerInstance.play();
        });

        // Save progress periodically
        playerInstance.on("time", function(e) {
            if (e.position > 5) {
                setCookie(COOKIE_NAME, e.position);
            }
        });

        // Clear progress on complete
        playerInstance.on("complete", function() {
            deleteCookie(COOKIE_NAME);
        });

        playerInstance.on("error", function(e) {
            console.error("Player error:", e);
        });
    </script>
</body>

</html>