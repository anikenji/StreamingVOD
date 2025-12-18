<?php
/**
 * JWPlayer Embed Page with Netflix Skin
 * Supports: /embed/{id} or /embed.php?id=xxx
 * Features: Continue watching, gradient controls, secured streaming
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
$securedPlaylistUrl = BASE_URL . 'api/stream/playlist.php?token=' . urlencode($streamToken);

$title = htmlspecialchars($video['original_filename']);
$thumbnailUrl = $video['thumbnail_path'] ? THUMBNAIL_BASE_URL . '/' . $videoId . '.jpg' : '';

// Episode metadata
$subtitleUrl = $video['subtitle_url'] ?? null;
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
            margin: 0;
            padding: 0;
            width: 100vw;
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        #player-container {
            width: 100%;
            height: 100%;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        /* 
         * Player sizing: fit entirely within viewport
         * - Portrait mode: limited by width
         * - Landscape mode: limited by height
         */
        #player {
            max-width: 100vw;
            max-height: 100vh;
            width: 100%;
        }

        /* Hide video title */
        .jw-title,
        .jw-title-primary,
        .jw-title-secondary {
            display: none !important;
        }

        /* Gradient background for controls - only show when controls visible */
        .jwplayer .jw-controls {
            background: transparent !important;
        }

        /* Show gradient only when controls are active/visible */
        .jwplayer.jw-state-idle .jw-controls,
        .jwplayer.jw-state-paused .jw-controls,
        .jwplayer.jw-flag-user-inactive:not(.jw-state-paused) .jw-controls {
            background: transparent !important;
        }

        .jwplayer:not(.jw-flag-user-inactive) .jw-controls,
        .jwplayer.jw-state-paused .jw-controls {
            background: linear-gradient(0deg, rgba(0, 0, 0, 0.8) 0%, rgba(0, 0, 0, 0.4) 20%, transparent 50%) !important;
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
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.8);
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

        /* Skip Intro/Outro Button */
        .skip-button {
            position: absolute;
            bottom: 80px;
            right: 20px;
            padding: 12px 24px;
            background: rgba(0, 0, 0, 0.3);
            color: #fff;
            font-size: 14px;
            font-weight: 600;
            border: 2px solid rgba(255, 255, 255, 0.7);
            border-radius: 8px;
            cursor: pointer;
            z-index: 100;
            display: none;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
            box-shadow: 0 0 20px rgba(255, 255, 255, 0.3), 0 0 40px rgba(255, 255, 255, 0.1);
            text-shadow: 0 0 10px rgba(255, 255, 255, 0.5);
        }

        .skip-button:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: scale(1.05);
            box-shadow: 0 0 30px rgba(255, 255, 255, 0.5), 0 0 60px rgba(255, 255, 255, 0.2);
            border-color: #fff;
        }

        .skip-button.visible {
            display: block;
            opacity: 1;
            animation: fadeIn 0.5s ease forwards;
        }

        .skip-button.fade-out {
            animation: fadeOut 0.5s ease forwards;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateX(20px);
            }

            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes fadeOut {
            from {
                opacity: 1;
                transform: translateX(0);
            }

            to {
                opacity: 0;
                transform: translateX(20px);
            }
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
    <script>(function () { var _ = atob; jwplayer.key = _("<?= base64_encode(JWPLAYER_KEY) ?>"); })();</script>

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

        // Initialize JWPlayer with secured playlist URL
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
                    file: "<?= $securedPlaylistUrl ?>",
                    type: "hls"
                }]<?php if ($subtitleUrl): ?>,
                    tracks: [{
                        file: "<?= $subtitleUrl ?>",
                        kind: "captions",
                        label: "Vietnamese",
                        default: true
                    }]
                <?php endif; ?>
            }],

            width: "100%",
            aspectratio: "16:9",
            stretching: "uniform",
            autostart: false,
            primary: "html5",
            hlshtml: true,
            preload: "metadata",
            playbackRateControls: [0.5, 0.75, 1, 1.25, 1.5, 2]
        });

        // Resume watching logic
        let savedPosition = getCookie(COOKIE_NAME);
        let hasShownModal = false;

        playerInstance.on("ready", function () {
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

                // Add Forward 10s button (clone from rewind button)
                const rewindContainer = playerContainer.querySelector(".jw-display-icon-rewind");
                if (rewindContainer) {
                    const forwardContainer = rewindContainer.cloneNode(true);
                    const forwardDisplayButton = forwardContainer.querySelector(".jw-icon-rewind");
                    if (forwardDisplayButton) {
                        forwardDisplayButton.style.transform = "scaleX(-1)";
                        forwardDisplayButton.ariaLabel = "Forward 10 Seconds";
                        const nextContainer = playerContainer.querySelector(".jw-display-icon-next");
                        if (nextContainer) {
                            nextContainer.parentNode.insertBefore(forwardContainer, nextContainer);
                        }

                        // Control bar forward button
                        const rewindControlBarButton = buttonContainer.querySelector(".jw-icon-rewind");
                        if (rewindControlBarButton) {
                            const forwardControlBarButton = rewindControlBarButton.cloneNode(true);
                            forwardControlBarButton.style.transform = "scaleX(-1)";
                            forwardControlBarButton.ariaLabel = "Forward 10 Seconds";
                            rewindControlBarButton.parentNode.insertBefore(forwardControlBarButton, rewindControlBarButton.nextElementSibling);

                            // Add click handlers
                            [forwardDisplayButton, forwardControlBarButton].forEach((button) => {
                                button.onclick = () => {
                                    playerInstance.seek(playerInstance.getPosition() + 10);
                                };
                            });
                        }
                    }
                }

                // Add PiP (Picture in Picture) button
                if (document.pictureInPictureEnabled) {
                    const pipIconSvg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M19 7h-8v6h8V7zm2-4H3c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h18c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H3V5h18v14z"/></svg>`;
                    const pipBtn = document.createElement('div');
                    pipBtn.className = 'jw-icon jw-icon-inline jw-button-color jw-reset jw-icon-pip';
                    pipBtn.setAttribute('role', 'button');
                    pipBtn.setAttribute('tabindex', '0');
                    pipBtn.setAttribute('aria-label', 'Picture in Picture');
                    pipBtn.innerHTML = pipIconSvg;
                    pipBtn.style.cursor = 'pointer';
                    pipBtn.onclick = async () => {
                        try {
                            const video = playerContainer.querySelector('video');
                            if (document.pictureInPictureElement) {
                                await document.exitPictureInPicture();
                            } else if (video) {
                                await video.requestPictureInPicture();
                            }
                        } catch (err) {
                            console.log('PiP error:', err);
                        }
                    };
                    // Insert before fullscreen button
                    const fullscreenBtn = buttonContainer.querySelector('.jw-icon-fullscreen');
                    if (fullscreenBtn) {
                        buttonContainer.insertBefore(pipBtn, fullscreenBtn);
                    } else {
                        buttonContainer.appendChild(pipBtn);
                    }
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

        // Video Stats Info (toggle with 'i' key or right-click menu)
        let statsOverlay = null;

        function createStatsOverlay() {
            if (statsOverlay) return;
            statsOverlay = document.createElement('div');
            statsOverlay.id = 'video-stats';
            statsOverlay.style.cssText = `
                position: absolute;
                top: 10px;
                left: 10px;
                background: rgba(0,0,0,0.85);
                color: #fff;
                padding: 15px 20px;
                border-radius: 8px;
                font-family: monospace;
                font-size: 12px;
                z-index: 100;
                max-width: 400px;
                display: none;
            `;
            playerInstance.getContainer().appendChild(statsOverlay);
        }

        function updateStats() {
            if (!statsOverlay || statsOverlay.style.display === 'none') return;
            const pos = playerInstance.getPosition();
            const dur = playerInstance.getDuration();
            const buf = playerInstance.getBuffer();
            const quality = playerInstance.getVisualQuality();
            const vol = playerInstance.getVolume();
            const videoTitle = <?= json_encode($video['original_filename']) ?>;
            const videoId = <?= json_encode($videoId) ?>;

            statsOverlay.innerHTML = `
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px 20px;">
                    <div><strong>Title</strong> ${videoTitle}</div>
                    <div><strong>Duration</strong> ${formatTime(dur)}</div>
                    <div><strong>Video ID</strong> ${videoId.substring(0, 8)}...</div>
                    <div><strong>Position</strong> ${formatTime(pos)}</div>
                    <div><strong>Buffer</strong> ${buf.toFixed(1)}%</div>
                    <div><strong>Resolution</strong> ${quality?.level?.width || 'N/A'}x${quality?.level?.height || 'N/A'}</div>
                    <div><strong>Bitrate</strong> ${quality?.level?.bitrate ? Math.round(quality.level.bitrate / 1000) + 'k' : 'N/A'}</div>
                    <div><strong>Volume</strong> ${vol}%</div>
                    <div><strong>Stream Type</strong> HLS</div>
                    <div><strong>Provider</strong> JWPlayer</div>
                </div>
                <div style="margin-top: 10px; font-size: 10px; color: #888;">Press 'i' to close</div>
            `;
        }

        function toggleStats() {
            createStatsOverlay();
            if (statsOverlay.style.display === 'none') {
                statsOverlay.style.display = 'block';
                updateStats();
            } else {
                statsOverlay.style.display = 'none';
            }
        }

        // Toggle stats with 'i' key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'i' || e.key === 'I') {
                toggleStats();
            }
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

        // Update stats every second when visible
        setInterval(updateStats, 1000);

        // Modal button handlers
        document.getElementById("btnResume").addEventListener("click", function () {
            document.getElementById("resumeModal").classList.remove("active");
            playerInstance.seek(savedPosition);
            playerInstance.play();
        });

        document.getElementById("btnRestart").addEventListener("click", function () {
            document.getElementById("resumeModal").classList.remove("active");
            deleteCookie(COOKIE_NAME);
            savedPosition = 0;
            playerInstance.seek(0);
            playerInstance.play();
        });

        // Save progress periodically AND handle skip buttons
        const introStart = <?= json_encode($introStart !== null ? floatval($introStart) : null) ?>;
        const introEnd = <?= json_encode($introEnd !== null ? floatval($introEnd) : null) ?>;
        const outroStart = <?= json_encode($outroStart !== null ? floatval($outroStart) : null) ?>;
        const outroEnd = <?= json_encode($outroEnd !== null ? floatval($outroEnd) : null) ?>;

        console.log('Video Metadata:', { introStart, introEnd, outroStart, outroEnd });

        // --- Only Play On Screen Feature ---
        console.log('Initializing onlyPlayOnScreen feature...');

        // Pause when page is hidden (tab switch, minimize, screen off)
        document.addEventListener("visibilitychange", function () {
            if (document.hidden) {
                const state = playerInstance.getState();
                if (state === 'playing' || state === 'buffering') {
                    console.log('Page hidden, pausing video to save resources.');
                    playerInstance.pause();
                }
            }
        });

        // Prevent playing if hidden (double check)
        playerInstance.on('play', function () {
            if (document.hidden) {
                console.log('Attempted to play while hidden, forcing pause.');
                playerInstance.pause();
            }
        });

        // --- Skip Intro/Outro Feature ---
        let skipIntroBtn = null;
        let skipOutroBtn = null;
        let skipButtonsInitialized = false;

        function showSkipButton(btn) {
            btn.classList.remove('fade-out');
            btn.style.display = 'block';
            btn.classList.add('visible');
        }

        function hideSkipButton(btn) {
            if (btn.style.display === 'block') {
                btn.classList.add('fade-out');
                btn.classList.remove('visible');
                setTimeout(() => {
                    btn.style.display = 'none';
                    btn.classList.remove('fade-out');
                }, 500);
            }
        }

        function initSkipButtons() {
            if (skipButtonsInitialized) return;
            skipButtonsInitialized = true;

            const container = playerInstance.getContainer();
            console.log('Initializing skip buttons, container:', container);

            // Create Skip Intro button
            skipIntroBtn = document.createElement('button');
            skipIntroBtn.className = 'skip-button';
            skipIntroBtn.id = 'skip-intro-btn';
            skipIntroBtn.textContent = 'Skip Intro ⏭';
            skipIntroBtn.style.cssText = 'display: none; position: absolute; bottom: 80px; right: 20px; z-index: 999999;';
            container.appendChild(skipIntroBtn);
            console.log('Skip Intro button created:', skipIntroBtn);

            // Create Skip Outro button
            skipOutroBtn = document.createElement('button');
            skipOutroBtn.className = 'skip-button';
            skipOutroBtn.id = 'skip-outro-btn';
            skipOutroBtn.textContent = 'Skip Ending ⏭';
            skipOutroBtn.style.cssText = 'display: none; position: absolute; bottom: 80px; right: 20px; z-index: 999999;';
            container.appendChild(skipOutroBtn);
            console.log('Skip Outro button created:', skipOutroBtn);

            // Click handlers
            skipIntroBtn.onclick = function () {
                console.log('Skip Intro clicked, seeking to:', introEnd);
                if (introEnd !== null) {
                    playerInstance.seek(introEnd);
                    hideSkipButton(skipIntroBtn);
                }
            };

            skipOutroBtn.onclick = function () {
                console.log('Skip Outro clicked, seeking to:', outroEnd);
                if (outroEnd !== null) {
                    playerInstance.seek(outroEnd);
                    hideSkipButton(skipOutroBtn);
                }
            };
        }

        // Initialize buttons when player is ready
        playerInstance.on('ready', function () {
            console.log('Player ready event fired');
            initSkipButtons();
        });

        // Also try to init on first play in case ready already fired
        playerInstance.on('firstFrame', function () {
            console.log('FirstFrame event fired');
            initSkipButtons();
        });

        playerInstance.on("time", function (e) {
            const position = e.position;

            // Save progress
            if (position > 5) {
                setCookie(COOKIE_NAME, position);
            }

            // Skip if buttons not ready
            if (!skipIntroBtn || !skipOutroBtn) {
                return;
            }

            // Handle Skip Intro Visibility - show throughout entire intro range
            if (introStart !== null && introEnd !== null && introEnd > introStart) {
                const inIntroRange = position >= introStart && position < introEnd;
                const isShowing = skipIntroBtn.style.display === 'block';

                if (inIntroRange && !isShowing) {
                    console.log('Showing Skip Intro at position:', position, '(range:', introStart, '-', introEnd, ')');
                    showSkipButton(skipIntroBtn);
                } else if (!inIntroRange && isShowing) {
                    console.log('Hiding Skip Intro at position:', position);
                    hideSkipButton(skipIntroBtn);
                }
            }

            // Handle Skip Outro Visibility - show throughout entire outro range
            if (outroStart !== null && outroEnd !== null && outroEnd > outroStart) {
                const inOutroRange = position >= outroStart && position < outroEnd;
                const isShowing = skipOutroBtn.style.display === 'block';

                if (inOutroRange && !isShowing) {
                    console.log('Showing Skip Outro at position:', position, '(range:', outroStart, '-', outroEnd, ')');
                    showSkipButton(skipOutroBtn);
                } else if (!inOutroRange && isShowing) {
                    console.log('Hiding Skip Outro at position:', position);
                    hideSkipButton(skipOutroBtn);
                }
            }
        });

        // Clear progress on complete and notify parent for auto next episode
        playerInstance.on("complete", function () {
            deleteCookie(COOKIE_NAME);
            // Gửi message đến trang cha khi video hoàn thành
            window.parent.postMessage('anikenji_video_complete', '*');
        });

        playerInstance.on("error", function (e) {
            console.error("Player error:", e);
        });

    </script>
</body>

</html>