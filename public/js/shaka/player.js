/**
 * Shaka Player Custom Controller
 * Netflix-style UI with all features migrated from JWPlayer
 */

class ShakaPlayerController {
    constructor(videoElement, options = {}) {
        this.video = videoElement;
        this.container = videoElement.closest('.shaka-player-container');
        this.player = null;
        this.options = {
            videoId: options.videoId || '',
            manifestUrl: options.manifestUrl || '',
            dashUrl: options.dashUrl || '',
            posterUrl: options.posterUrl || '',
            subtitleUrl: options.subtitleUrl || null,
            subtitles: options.subtitles || [],
            introStart: options.introStart || null,
            introEnd: options.introEnd || null,
            outroStart: options.outroStart || null,
            outroEnd: options.outroEnd || null,
            ...options
        };

        // Cookie names
        this.POSITION_COOKIE = `shaka_resume_${this.options.videoId}`;
        this.VOLUME_COOKIE = 'shaka_volume';
        this.SUBTITLE_PREFS_COOKIE = 'shaka_subtitle_prefs';

        // Subtitle preferences (loaded from cookie)
        this.subtitlePrefs = this.loadSubtitlePrefs();

        // State
        this.controlsTimeout = null;
        this.statsInterval = null;
        this.isFullscreen = false;
        this.userSubtitleBlob = null; // For user-uploaded local subtitle

        this.init();
    }

    async init() {
        // Install polyfills
        shaka.polyfill.installAll();

        if (!shaka.Player.isBrowserSupported()) {
            console.error('Browser not supported for Shaka Player');
            return;
        }

        this.player = new shaka.Player(this.video);

        // Configure streaming buffer for better playback experience
        this.player.configure({
            streaming: {
                bufferingGoal: 30,      // Pre-load 30 seconds ahead
                rebufferingGoal: 2,     // Only need 2s buffer to start playing
                bufferBehind: 30      // Keep 30s of watched content in memory
            }
        });

        // Error handling
        this.player.addEventListener('error', (e) => this.onError(e));

        // Build UI
        this.buildControls();
        this.bindEvents();

        // Load manifest
        await this.loadManifest();

        // Restore saved state
        this.restoreVolume();
        this.checkResumePosition();
    }

    async loadManifest() {
        try {
            this.showBuffering(true);

            // Detect Safari/iOS - they don't support AV1, force HLS
            const isSafari = /^((?!chrome|android).)*safari/i.test(navigator.userAgent);
            const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
            const isAppleDevice = isSafari || isIOS;

            let manifestUrl = this.options.manifestUrl; // Default: HLS

            if (isAppleDevice) {
                // Safari/iOS: Always use HLS (AV1 codec not supported)
                console.log('Safari/iOS detected - using HLS manifest for compatibility');
                manifestUrl = this.options.manifestUrl;
            } else if (this.options.dashUrl) {
                // Chrome/Firefox/Android: Use DASH for AV1 quality
                // Shaka Player always supports DASH on modern browsers
                manifestUrl = this.options.dashUrl;
                console.log('PC/Android detected - using DASH manifest for AV1');
            } else {
                console.log('No DASH URL available, using HLS manifest');
            }

            await this.player.load(manifestUrl);
            console.log('Manifest loaded successfully');

            // Add subtitles from options (array)
            const subs = this.options.subtitles || [];

            // Backward compatibility for single subtitleUrl if array is empty
            if (subs.length === 0 && this.options.subtitleUrl) {
                subs.push({
                    url: this.options.subtitleUrl,
                    language: 'vi',
                    label: 'Tiếng Việt',
                    mime: 'text/vtt'
                });
            }

            for (const sub of subs) {
                try {
                    await this.player.addTextTrackAsync(
                        sub.url,
                        sub.language,
                        'subtitles',
                        sub.mime || 'text/vtt',
                        null,
                        sub.label
                    );
                    console.log(`Loaded subtitle: ${sub.label} (${sub.language})`);
                } catch (e) {
                    console.error('Failed to load subtitle:', sub, e);
                }
            }

            // Auto-enable subtitles based on preferences
            if (this.subtitlePrefs.enabled) {
                const tracks = this.player.getTextTracks();
                // Find preferred language track
                const preferredTrack = tracks.find(t => t.language === this.subtitlePrefs.preferredLang) ||
                    tracks.find(t => t.language === 'vi') ||
                    tracks[0];

                if (preferredTrack) {
                    this.player.selectTextLanguage(preferredTrack.language);
                    this.player.setTextTrackVisibility(true);
                }
            }

            // Apply saved subtitle styles
            this.applySubtitleStyles();

            this.showBuffering(false);
            this.updateDuration();
        } catch (error) {
            console.error('Error loading manifest:', error);
            this.showBuffering(false);
        }
    }

    buildControls() {
        // Controls overlay
        this.controls = this.container.querySelector('.shaka-controls-overlay');

        // Progress bar
        this.progressContainer = this.container.querySelector('.shaka-progress-container');
        this.progressPlayed = this.container.querySelector('.shaka-progress-played');
        this.progressBuffered = this.container.querySelector('.shaka-progress-buffered');
        this.progressHandle = this.container.querySelector('.shaka-progress-handle');

        // Buttons
        this.playBtn = this.container.querySelector('.shaka-btn-play');
        this.centerPlayBtn = this.container.querySelector('.shaka-center-play');
        this.rewindBtn = this.container.querySelector('.shaka-btn-rewind');
        this.forwardBtn = this.container.querySelector('.shaka-btn-forward');
        this.muteBtn = this.container.querySelector('.shaka-btn-mute');
        this.pipBtn = this.container.querySelector('.shaka-btn-pip');
        this.fullscreenBtn = this.container.querySelector('.shaka-btn-fullscreen');
        this.settingsBtn = this.container.querySelector('.shaka-btn-settings');

        // Volume
        this.volumeSlider = this.container.querySelector('.shaka-volume-slider');
        this.volumeLevel = this.container.querySelector('.shaka-volume-level');

        // Time display
        this.timeDisplay = this.container.querySelector('.shaka-time-display');

        // Skip buttons
        this.skipIntroBtn = this.container.querySelector('.shaka-skip-intro');
        this.skipOutroBtn = this.container.querySelector('.shaka-skip-outro');

        // Resume modal and backdrop
        this.resumeModal = this.container.querySelector('.shaka-resume-modal');
        this.resumeBackdrop = this.container.querySelector('.shaka-resume-backdrop');

        // Stats overlay
        this.statsOverlay = this.container.querySelector('.shaka-stats-overlay');

        // Settings menu
        this.settingsMenu = this.container.querySelector('.shaka-settings-menu');

        // Spinner
        this.spinner = this.container.querySelector('.shaka-spinner');
    }

    bindEvents() {
        // Video events
        this.video.addEventListener('timeupdate', () => this.onTimeUpdate());
        this.video.addEventListener('progress', () => this.onProgress());
        this.video.addEventListener('play', () => this.onPlay());
        this.video.addEventListener('pause', () => this.onPause());
        this.video.addEventListener('ended', () => this.onEnded());
        this.video.addEventListener('waiting', () => this.showBuffering(true));
        this.video.addEventListener('playing', () => this.showBuffering(false));
        this.video.addEventListener('volumechange', () => this.onVolumeChange());
        this.video.addEventListener('loadedmetadata', () => this.updateDuration());

        // Control buttons
        if (this.playBtn) {
            this.playBtn.addEventListener('click', () => this.togglePlay());
        }
        if (this.centerPlayBtn) {
            this.centerPlayBtn.addEventListener('click', () => this.togglePlay());
        }
        if (this.rewindBtn) {
            this.rewindBtn.addEventListener('click', () => this.seek(-10));
        }
        if (this.forwardBtn) {
            this.forwardBtn.addEventListener('click', () => this.seek(10));
        }
        if (this.muteBtn) {
            this.muteBtn.addEventListener('click', () => this.toggleMute());
        }
        if (this.pipBtn && document.pictureInPictureEnabled) {
            this.pipBtn.addEventListener('click', () => this.togglePiP());
        }
        if (this.fullscreenBtn) {
            this.fullscreenBtn.addEventListener('click', () => this.toggleFullscreen());
        }
        if (this.settingsBtn) {
            this.settingsBtn.addEventListener('click', () => this.toggleSettings());
        }

        // Progress bar
        if (this.progressContainer) {
            this.progressContainer.addEventListener('click', (e) => this.seekToPosition(e));
            this.progressContainer.addEventListener('mousemove', (e) => this.updateProgressHandle(e));
        }

        // Volume slider
        if (this.volumeSlider) {
            this.volumeSlider.addEventListener('click', (e) => this.setVolumeFromClick(e));
        }

        // Skip buttons
        if (this.skipIntroBtn) {
            this.skipIntroBtn.addEventListener('click', () => {
                this.video.currentTime = this.options.introEnd;
            });
        }
        if (this.skipOutroBtn) {
            this.skipOutroBtn.addEventListener('click', () => {
                this.video.currentTime = this.options.outroEnd || this.video.duration;
            });
        }

        // Resume modal buttons
        const resumeBtn = this.container.querySelector('.shaka-btn-resume');
        const restartBtn = this.container.querySelector('.shaka-btn-restart');
        if (resumeBtn) {
            resumeBtn.addEventListener('click', () => this.resumeFromSaved());
        }
        if (restartBtn) {
            restartBtn.addEventListener('click', () => this.startFromBeginning());
        }

        // Container events for controls visibility
        // Proper idle detection for auto-hide
        let lastActivityTime = 0; // Start at 0 so first activity always triggers
        let idleCheckInterval = null;

        const IDLE_TIMEOUT = 3000; // 3 seconds of no activity = hide
        const THROTTLE_MS = 100; // Throttle mouse events (reduced from 200)

        const onUserActivity = () => {
            const now = Date.now();
            // Throttle: only process if enough time has passed (except first time)
            if (lastActivityTime > 0 && now - lastActivityTime < THROTTLE_MS) return;

            lastActivityTime = now;
            this.showControls();

            // Reset idle check
            if (idleCheckInterval) {
                clearInterval(idleCheckInterval);
                idleCheckInterval = null;
            }

            // Only start idle check if video is playing
            if (!this.video.paused) {
                idleCheckInterval = setInterval(() => {
                    const idleTime = Date.now() - lastActivityTime;
                    if (idleTime >= IDLE_TIMEOUT && !this.video.paused) {
                        this.hideControls();
                        clearInterval(idleCheckInterval);
                        idleCheckInterval = null;
                    }
                }, 500); // Check every 500ms
            }
        };

        // Mouse events
        this.container.addEventListener('mousemove', onUserActivity);
        this.container.addEventListener('mouseenter', onUserActivity);

        // Hide immediately when mouse leaves (non-fullscreen)
        this.container.addEventListener('mouseleave', () => {
            if (!document.fullscreenElement && !this.video.paused) {
                this.hideControls();
            }
        });

        // Touch events for mobile
        this.container.addEventListener('touchstart', onUserActivity);

        // Click on video to toggle play (also triggers activity)
        this.container.addEventListener('click', (e) => {
            if (e.target === this.video || e.target.classList.contains('shaka-controls-overlay')) {
                this.togglePlay();
            }
            onUserActivity(); // Use onUserActivity for proper tracking
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => this.handleKeyboard(e));

        // Visibility change (pause when tab hidden)
        document.addEventListener('visibilitychange', () => {
            if (document.hidden && !this.video.paused) {
                this.video.pause();
            }
        });

        // Fullscreen change - also reset hide timer
        document.addEventListener('fullscreenchange', () => {
            this.isFullscreen = !!document.fullscreenElement;
            this.updateFullscreenButton();
            resetHideTimer();
        });

        // Save position periodically
        setInterval(() => this.savePosition(), 5000);
    }

    // ==================== PLAYBACK CONTROLS ====================

    togglePlay() {
        if (this.video.paused) {
            this.video.play();
        } else {
            this.video.pause();
        }
    }

    seek(seconds) {
        this.video.currentTime = Math.max(0, Math.min(this.video.duration, this.video.currentTime + seconds));
    }

    seekToPosition(e) {
        const rect = this.progressContainer.getBoundingClientRect();
        const pos = (e.clientX - rect.left) / rect.width;
        this.video.currentTime = pos * this.video.duration;
    }

    // ==================== VOLUME ====================

    toggleMute() {
        this.video.muted = !this.video.muted;
    }

    setVolumeFromClick(e) {
        const rect = this.volumeSlider.getBoundingClientRect();
        const volume = (e.clientX - rect.left) / rect.width;
        this.video.volume = Math.max(0, Math.min(1, volume));
        this.video.muted = false;
        this.saveVolume();
    }

    saveVolume() {
        this.setCookie(this.VOLUME_COOKIE, Math.round(this.video.volume * 100), 365);
    }

    restoreVolume() {
        const saved = this.getCookie(this.VOLUME_COOKIE);
        if (saved !== null) {
            this.video.volume = saved / 100;
        }
    }

    // ==================== PIP & FULLSCREEN ====================

    async togglePiP() {
        try {
            if (document.pictureInPictureElement) {
                await document.exitPictureInPicture();
            } else {
                await this.video.requestPictureInPicture();
            }
        } catch (error) {
            console.error('PiP error:', error);
        }
    }

    async toggleFullscreen() {
        try {
            if (document.fullscreenElement) {
                await document.exitFullscreen();
            } else {
                await this.container.requestFullscreen();
            }
        } catch (error) {
            console.error('Fullscreen error:', error);
        }
    }

    updateFullscreenButton() {
        if (this.fullscreenBtn) {
            const icon = this.isFullscreen ? this.getExitFullscreenIcon() : this.getFullscreenIcon();
            this.fullscreenBtn.innerHTML = icon;
        }
    }

    // ==================== UI UPDATES ====================

    onTimeUpdate() {
        const current = this.video.currentTime;
        const duration = this.video.duration;

        // Update progress bar
        if (this.progressPlayed && duration) {
            const percent = (current / duration) * 100;
            this.progressPlayed.style.width = `${percent}%`;
            if (this.progressHandle) {
                this.progressHandle.style.left = `${percent}%`;
            }
        }

        // Update time display
        if (this.timeDisplay) {
            this.timeDisplay.textContent = `${this.formatTime(current)} / ${this.formatTime(duration)}`;
        }

        // Check skip buttons
        this.checkSkipButtons(current);
    }

    onProgress() {
        if (!this.video.buffered.length) return;
        const buffered = this.video.buffered.end(this.video.buffered.length - 1);
        const duration = this.video.duration;
        if (this.progressBuffered && duration) {
            this.progressBuffered.style.width = `${(buffered / duration) * 100}%`;
        }
    }

    onPlay() {
        this.container.classList.remove('paused');
        this.updatePlayButton(true);
        // Show controls briefly then start auto-hide timer
        this.showControls();
    }

    onPause() {
        this.container.classList.add('paused');
        this.updatePlayButton(false);
        // Always show controls when paused
        this.showControls();
    }

    onEnded() {
        this.deleteCookie(this.POSITION_COOKIE);
    }

    onVolumeChange() {
        this.updateVolumeUI();
    }

    updatePlayButton(playing) {
        if (this.playBtn) {
            this.playBtn.innerHTML = playing ? this.getPauseIcon() : this.getPlayIcon();
        }
        if (this.centerPlayBtn) {
            this.centerPlayBtn.innerHTML = this.getPlayIcon();
        }
    }

    updateVolumeUI() {
        const volume = this.video.muted ? 0 : this.video.volume;
        if (this.volumeLevel) {
            this.volumeLevel.style.width = `${volume * 100}%`;
        }
        if (this.muteBtn) {
            this.muteBtn.innerHTML = volume === 0 ? this.getMutedIcon() : this.getVolumeIcon();
        }
    }

    updateDuration() {
        if (this.timeDisplay && this.video.duration) {
            this.timeDisplay.textContent = `0:00 / ${this.formatTime(this.video.duration)}`;
        }
    }

    updateProgressHandle(e) {
        if (!this.progressHandle) return;
        const rect = this.progressContainer.getBoundingClientRect();
        const pos = (e.clientX - rect.left) / rect.width;
        this.progressHandle.style.left = `${pos * 100}%`;
    }

    showControls() {
        this.container.classList.add('show-controls');
        clearTimeout(this.controlsTimeout);
        if (!this.video.paused) {
            this.controlsTimeout = setTimeout(() => this.hideControls(), 3000);
        }
    }

    hideControls() {
        if (!this.video.paused) {
            this.container.classList.remove('show-controls');
        }
    }

    showBuffering(show) {
        this.container.classList.toggle('buffering', show);
    }

    // ==================== SKIP INTRO/OUTRO ====================

    checkSkipButtons(currentTime) {
        // Skip Intro
        if (this.skipIntroBtn && this.options.introStart !== null && this.options.introEnd !== null) {
            const showIntro = currentTime >= this.options.introStart && currentTime < this.options.introEnd;
            this.toggleSkipButton(this.skipIntroBtn, showIntro);
        }

        // Skip Outro
        if (this.skipOutroBtn && this.options.outroStart !== null) {
            const showOutro = currentTime >= this.options.outroStart;
            this.toggleSkipButton(this.skipOutroBtn, showOutro);
        }
    }

    toggleSkipButton(btn, show) {
        if (show) {
            // Show with animation
            btn.classList.remove('fade-out');
            btn.classList.add('visible');
        } else if (btn.classList.contains('visible') && !btn.classList.contains('fade-out')) {
            // Hide with fade-out animation
            btn.classList.add('fade-out');
            setTimeout(() => {
                btn.classList.remove('visible', 'fade-out');
            }, 400); // Match CSS animation duration
        }
    }

    // ==================== RESUME WATCHING ====================

    checkResumePosition() {
        const saved = this.getCookie(this.POSITION_COOKIE);
        if (saved && saved > 10) {
            // Show resume modal with backdrop
            const timeDisplay = this.resumeModal?.querySelector('.time');
            if (timeDisplay) {
                timeDisplay.textContent = this.formatTime(saved);
            }
            // Activate backdrop to block interaction
            if (this.resumeBackdrop) {
                this.resumeBackdrop.classList.add('active');
            }
            if (this.resumeModal) {
                this.resumeModal.classList.add('active');
            }
            // Ensure video is paused while modal is visible
            this.video.pause();
            this.savedPosition = saved;
        }
    }

    resumeFromSaved() {
        // Remove backdrop and modal
        if (this.resumeBackdrop) {
            this.resumeBackdrop.classList.remove('active');
        }
        if (this.resumeModal) {
            this.resumeModal.classList.remove('active');
        }
        if (this.savedPosition) {
            this.video.currentTime = this.savedPosition;
        }
        this.video.play();
    }

    startFromBeginning() {
        // Remove backdrop and modal
        if (this.resumeBackdrop) {
            this.resumeBackdrop.classList.remove('active');
        }
        if (this.resumeModal) {
            this.resumeModal.classList.remove('active');
        }
        this.deleteCookie(this.POSITION_COOKIE);
        this.video.currentTime = 0;
        this.video.play();
    }

    savePosition() {
        if (this.video.currentTime > 10 && this.video.currentTime < this.video.duration - 30) {
            this.setCookie(this.POSITION_COOKIE, Math.floor(this.video.currentTime), 7);
        }
    }

    // ==================== SUBTITLE PREFERENCES ====================

    loadSubtitlePrefs() {
        const defaults = {
            enabled: true,
            preferredLang: 'vi',
            fontSize: 'medium',
            fontFamily: 'arial',
            fontColor: '#ffffff',
            fontOpacity: 1,
            backgroundColor: '#000000',
            backgroundOpacity: 0, // Default transparent
            edgeStyle: 'shadow', // 'none', 'outline', 'shadow', 'raised', 'depressed'
            position: 'bottom'
        };

        try {
            const saved = this.getCookie(this.SUBTITLE_PREFS_COOKIE);
            if (saved) {
                return { ...defaults, ...JSON.parse(saved) };
            }
        } catch (e) {
            console.warn('Failed to load subtitle prefs:', e);
        }
        return defaults;
    }

    saveSubtitlePrefs() {
        this.setCookie(this.SUBTITLE_PREFS_COOKIE, JSON.stringify(this.subtitlePrefs), 365);
        this.applySubtitleStyles();
    }

    /**
     * Helper to hex to rgba
     */
    hexToRgba(hex, alpha) {
        let c;
        if (/^#([A-Fa-f0-9]{3}){1,2}$/.test(hex)) {
            c = hex.substring(1).split('');
            if (c.length === 3) {
                c = [c[0], c[0], c[1], c[1], c[2], c[2]];
            }
            c = '0x' + c.join('');
            return 'rgba(' + [(c >> 16) & 255, (c >> 8) & 255, c & 255].join(',') + ',' + alpha + ')';
        }
        return hex;
    }

    applySubtitleStyles() {
        const prefs = this.subtitlePrefs;

        // Font size mapping
        const fontSizeMap = {
            'small': '18px',
            'medium': '24px',
            'large': '32px',
            'xlarge': '44px'
        };

        // Position mapping (bottom offset) - Increased values for better visibility
        const positionMap = {
            'bottom': '10%',  // Raised from 5%
            'lower': '20%',   // Raised from 10%
            'middle': '50%',  // Center
            'higher': '80%'   // Top area
        };

        // Edge Styles
        let textShadow = 'none';
        const color = prefs.fontColor;
        switch (prefs.edgeStyle) {
            case 'outline':
                textShadow = `-1px -1px 0 #000, 1px -1px 0 #000, -1px 1px 0 #000, 1px 1px 0 #000, 0px 0px 2px #000`;
                break;
            case 'shadow':
                textShadow = '2px 2px 2px rgba(0, 0, 0, 0.8)';
                break;
            case 'raised':
                textShadow = '1px 1px 0 #000, -1px -1px 0 #fff'; // Simplified raised effect
                break;
            case 'depressed':
                textShadow = '1px 1px 0 #fff, -1px -1px 0 #000'; // Simplified depressed effect
                break;
            case 'none':
            default:
                textShadow = 'none';
                break;
        }

        // Colors with opacity
        const textColor = this.hexToRgba(prefs.fontColor, prefs.fontOpacity);
        const bgColor = this.hexToRgba(prefs.backgroundColor || '#000000', prefs.backgroundOpacity);

        // Create or update style element
        let styleEl = document.getElementById('shaka-subtitle-styles');
        if (!styleEl) {
            styleEl = document.createElement('style');
            styleEl.id = 'shaka-subtitle-styles';
            document.head.appendChild(styleEl);
        }

        // Expanded CSS selectors
        // Note: Shaka renders cues in nested divs. We target the specific text container.
        styleEl.textContent = `
            /* Position the text container wrapper */
            .shaka-text-container {
                bottom: ${positionMap[prefs.position] || '10%'} !important;
                display: flex !important;
                flex-direction: column !important;
                justify-content: flex-end !important;
                align-items: center !important;
            }
            
            /* Style the actual cues */
            .shaka-text-container span {
                font-size: ${fontSizeMap[prefs.fontSize] || '24px'} !important;
                font-family: ${prefs.fontFamily === 'serif' ? 'Times New Roman, serif' : 'Arial, sans-serif'} !important;
                color: ${textColor} !important;
                background-color: ${bgColor} !important;
                text-shadow: ${textShadow} !important;
                padding: 4px 8px !important;
                border-radius: 4px !important;
                line-height: 1.4 !important;
                font-weight: normal !important;
                /* Ensure background covers the text properly */
                box-shadow: ${prefs.backgroundOpacity > 0 ? `0 0 5px 5px ${bgColor}` : 'none'} !important; 
            }
        `;

        console.log('Applied subtitle styles:', prefs);
    }

    handleUserSubtitleUpload(file) {
        if (!file) return;

        const reader = new FileReader();
        reader.onload = async (e) => {
            let content = e.target.result;
            const filename = file.name.toLowerCase();

            // Convert SRT to VTT if needed
            if (filename.endsWith('.srt')) {
                content = this.convertSrtToVtt(content);
            } else if (filename.endsWith('.ass') || filename.endsWith('.ssa')) {
                // ASS is more complex, try basic conversion
                content = this.convertAssToVtt(content);
            }

            // Create blob URL
            const blob = new Blob([content], { type: 'text/vtt' });
            this.userSubtitleBlob = URL.createObjectURL(blob);

            // Add to player
            try {
                await this.player.addTextTrackAsync(
                    this.userSubtitleBlob,
                    'vi', // Default to Vietnamese
                    'subtitles',
                    'text/vtt',
                    null,
                    file.name.replace(/\.[^/.]+$/, '') // Label without extension
                );
                this.player.setTextTrackVisibility(true);
                this.applySubtitleStyles();
                console.log('User subtitle loaded:', file.name);
            } catch (err) {
                console.error('Failed to load user subtitle:', err);
            }
        };
        reader.readAsText(file);
    }

    convertSrtToVtt(srt) {
        // SRT to VTT conversion
        let vtt = 'WEBVTT\n\n';

        // Replace SRT timestamp format (00:00:00,000) with VTT format (00:00:00.000)
        const converted = srt
            .replace(/\r\n/g, '\n')
            .replace(/\r/g, '\n')
            .replace(/(\d{2}:\d{2}:\d{2}),(\d{3})/g, '$1.$2');

        vtt += converted;
        return vtt;
    }

    convertAssToVtt(ass) {
        // Basic ASS to VTT conversion (handles Dialogue lines)
        let vtt = 'WEBVTT\n\n';
        const lines = ass.split('\n');
        let cueIndex = 1;

        for (const line of lines) {
            if (line.startsWith('Dialogue:')) {
                // Format: Dialogue: Marked,Start,End,Style,Name,MarginL,MarginR,MarginV,Effect,Text
                const parts = line.substring(10).split(',');
                if (parts.length >= 10) {
                    const start = parts[1].trim();
                    const end = parts[2].trim();
                    const text = parts.slice(9).join(',')
                        .replace(/\\N/g, '\n')
                        .replace(/\{[^}]*\}/g, ''); // Remove ASS tags

                    // Convert time format (0:00:00.00 to 00:00:00.000)
                    const formatTime = (t) => {
                        const match = t.match(/(\d+):(\d{2}):(\d{2})\.(\d{2})/);
                        if (match) {
                            return `${match[1].padStart(2, '0')}:${match[2]}:${match[3]}.${match[4]}0`;
                        }
                        return t;
                    };

                    vtt += `${cueIndex}\n`;
                    vtt += `${formatTime(start)} --> ${formatTime(end)}\n`;
                    vtt += `${text.trim()}\n\n`;
                    cueIndex++;
                }
            }
        }

        return vtt;
    }

    // ==================== STATS OVERLAY ====================

    toggleStats() {
        if (this.statsOverlay) {
            this.statsOverlay.classList.toggle('visible');
            if (this.statsOverlay.classList.contains('visible')) {
                this.updateStats();
                this.statsInterval = setInterval(() => this.updateStats(), 1000);
            } else {
                clearInterval(this.statsInterval);
            }
        }
    }

    updateStats() {
        if (!this.statsOverlay) return;

        const tracks = this.player.getVariantTracks();
        const currentTrack = tracks.find(t => t.active);
        const stats = this.player.getStats();
        const bufferedInfo = this.player.getBufferedInfo();

        // Calculate buffer health (seconds ahead)
        let bufferHealth = 0;
        if (bufferedInfo && bufferedInfo.total && bufferedInfo.total.length > 0) {
            const currentTime = this.video.currentTime;
            for (const range of bufferedInfo.total) {
                if (currentTime >= range.start && currentTime <= range.end) {
                    bufferHealth = range.end - currentTime;
                    break;
                }
            }
        }

        // Get viewport size
        const viewport = `${this.video.videoWidth || 0}x${this.video.videoHeight || 0}`;
        const optimal = currentTrack ? `${currentTrack.width}x${currentTrack.height}` : 'N/A';

        // Frames info
        const droppedFrames = stats.droppedFrames || 0;
        const decodedFrames = stats.decodedFrames || 0;

        // Codec info - parse from combined codecs string
        let videoCodec = 'N/A';
        let audioCodec = 'mp4a.40.2'; // Default AAC

        // Parse from codecs string (format: "avc1.xxx,mp4a.40.2")
        if (currentTrack?.codecs) {
            const codecParts = currentTrack.codecs.split(',').map(c => c.trim());
            if (codecParts[0]) videoCodec = codecParts[0];
            if (codecParts[1]) audioCodec = codecParts[1];
        } else {
            // Fallback to separate fields if available
            if (currentTrack?.videoCodec) videoCodec = currentTrack.videoCodec;
            if (currentTrack?.audioCodec) audioCodec = currentTrack.audioCodec;
        }

        // Bandwidth/speed
        const estimatedBandwidth = stats.estimatedBandwidth ? Math.round(stats.estimatedBandwidth / 1000) : 0;
        const streamBandwidth = stats.streamBandwidth ? Math.round(stats.streamBandwidth / 1000) : 0;

        // Current datetime
        const now = new Date();
        const dateStr = now.toLocaleString('vi-VN', {
            timeZone: 'Asia/Ho_Chi_Minh',
            weekday: 'short', year: 'numeric', month: 'short', day: 'numeric',
            hour: '2-digit', minute: '2-digit', second: '2-digit'
        });

        // Build progress bar helper
        const progressBar = (value, max, color = '#ff0') => {
            const percent = Math.min(100, (value / max) * 100);
            return `<div style="background: #333; border-radius: 2px; height: 8px; width: 120px; display: inline-block; vertical-align: middle;">
                <div style="background: ${color}; height: 100%; width: ${percent}%; border-radius: 2px;"></div>
            </div>`;
        };

        this.statsOverlay.innerHTML = `
            <div style="font-family: monospace; font-size: 11px; line-height: 1.6;">
                <div><span style="color: #888;">Video ID / sCPN</span>  <span style="color: #fff;">${this.options.videoId}</span></div>
                <div><span style="color: #888;">Viewport / Frames</span>  <span style="color: #fff;">${viewport} / ${droppedFrames} dropped of ${decodedFrames}</span></div>
                <div><span style="color: #888;">Current / Optimal Res</span>  <span style="color: #fff;">${viewport}@${Math.round(stats.playTime > 0 ? decodedFrames / stats.playTime : 0)} / ${optimal}@${currentTrack?.frameRate || 24}</span></div>
                <div><span style="color: #888;">Volume / Normalized</span>  <span style="color: #fff;">${Math.round(this.video.volume * 100)}%</span></div>
                <div><span style="color: #888;">Codecs</span>  <span style="color: #fff;">${videoCodec} / ${audioCodec}</span></div>
                <div><span style="color: #888;">Connection Speed</span>  ${progressBar(estimatedBandwidth, 12000, '#4a4')} <span style="color: #fff;">${estimatedBandwidth} Kbps</span></div>
                <div><span style="color: #888;">Stream Bandwidth</span>  ${progressBar(streamBandwidth, 6000, '#48f')} <span style="color: #fff;">${streamBandwidth} Kbps</span></div>
                <div><span style="color: #888;">Buffer Health</span>  ${progressBar(bufferHealth, 30, bufferHealth > 5 ? '#4a4' : '#f44')} <span style="color: #fff;">${bufferHealth.toFixed(2)} s</span></div>
                <div><span style="color: #888;">Position / Duration</span>  <span style="color: #fff;">${this.formatTime(this.video.currentTime)} / ${this.formatTime(this.video.duration)}</span></div>
                <div><span style="color: #888;">Date</span>  <span style="color: #fff;">${dateStr} (Giờ Đông Dương)</span></div>
            </div>
            <div style="margin-top: 8px; font-size: 10px; color: #666;">Press 'i' to close</div>
        `;
    }

    // ==================== SETTINGS MENU ====================

    toggleSettings() {
        if (this.settingsMenu) {
            this.settingsMenu.classList.toggle('active');
            if (this.settingsMenu.classList.contains('active')) {
                this.buildSettingsMenu();
            }
        }
    }

    buildSettingsMenu(submenu = null) {
        if (!this.settingsMenu) return;

        const tracks = this.player.getVariantTracks();
        const speeds = [0.5, 0.75, 1, 1.25, 1.5, 2];

        // Get current quality
        const currentTrack = tracks.find(t => t.active);
        const currentQuality = this.player.getConfiguration().abr.enabled
            ? 'Auto'
            : (currentTrack?.height ? `${currentTrack.height}p` : 'Auto');

        let html = '';

        if (submenu === 'speed') {
            // Speed submenu
            html = `
                <div class="shaka-settings-item shaka-settings-back" data-action="back">
                    <svg viewBox="0 0 24 24" width="16" height="16" style="margin-right: 8px;"><path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z" fill="currentColor"/></svg>
                    <span>Speed</span>
                </div>
            `;
            speeds.forEach(speed => {
                const isActive = this.video.playbackRate === speed;
                html += `
                    <div class="shaka-settings-item ${isActive ? 'active' : ''}" data-action="set-speed" data-value="${speed}">
                        <span>${speed === 1 ? 'Normal' : speed + 'x'}</span>
                        ${isActive ? '<svg viewBox="0 0 24 24" width="16" height="16"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z" fill="currentColor"/></svg>' : ''}
                    </div>
                `;
            });
        } else if (submenu === 'quality') {
            // Quality submenu
            html = `
                <div class="shaka-settings-item shaka-settings-back" data-action="back">
                    <svg viewBox="0 0 24 24" width="16" height="16" style="margin-right: 8px;"><path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z" fill="currentColor"/></svg>
                    <span>Quality</span>
                </div>
            `;

            // Auto option
            const isAuto = this.player.getConfiguration().abr.enabled;
            html += `
                <div class="shaka-settings-item ${isAuto ? 'active' : ''}" data-action="set-quality" data-value="auto">
                    <span>Auto</span>
                    ${isAuto ? '<svg viewBox="0 0 24 24" width="16" height="16"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z" fill="currentColor"/></svg>' : ''}
                </div>
            `;

            // Quality options (unique heights, sorted descending)
            const qualities = [...new Set(tracks.map(t => t.height))].filter(h => h).sort((a, b) => b - a);
            qualities.forEach(height => {
                const isActive = !isAuto && currentTrack?.height === height;
                html += `
                    <div class="shaka-settings-item ${isActive ? 'active' : ''}" data-action="set-quality" data-value="${height}">
                        <span>${height}p</span>
                        ${isActive ? '<svg viewBox="0 0 24 24" width="16" height="16"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z" fill="currentColor"/></svg>' : ''}
                    </div>
                `;
            });
        } else if (submenu === 'subtitles') {
            // Subtitles submenu
            const textTracks = this.player.getTextTracks();
            const isVisible = this.player.isTextTrackVisible();

            html = `
                <div class="shaka-settings-item shaka-settings-back" data-action="back">
                    <svg viewBox="0 0 24 24" width="16" height="16" style="margin-right: 8px;"><path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z" fill="currentColor"/></svg>
                    <span>Subtitles</span>
                </div>
                <div class="shaka-settings-item ${!isVisible ? 'active' : ''}" data-action="set-subtitle" data-value="off">
                    <span>Off</span>
                    ${!isVisible ? '<svg viewBox="0 0 24 24" width="16" height="16"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z" fill="currentColor"/></svg>' : ''}
                </div>
            `;

            textTracks.forEach((track, index) => {
                const isActive = isVisible && track.active;
                const langLabel = track.language === 'vi' ? 'Tiếng Việt' :
                    track.language === 'en' ? 'English' :
                        track.label || track.language || `Track ${index + 1}`;
                html += `
                    <div class="shaka-settings-item ${isActive ? 'active' : ''}" data-action="set-subtitle" data-value="${index}">
                        <span>${langLabel}</span>
                        ${isActive ? '<svg viewBox="0 0 24 24" width="16" height="16"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z" fill="currentColor"/></svg>' : ''}
                    </div>
                `;
            });

            // Upload local file option
            html += `
                <div class="shaka-settings-item" data-action="upload-subtitle">
                    <span>Upload File (.srt, .vtt, .ass)</span>
                </div>
                <div class="shaka-settings-item" data-action="subtitle-style">
                    <span>Subtitle Style</span>
                    <span class="value">›</span>
                </div>
            `;

        } else if (submenu === 'subtitle-style') {
            // Main Subtitle Style submenu (Categories)
            html = `
                <div class="shaka-settings-item shaka-settings-back" data-action="subtitles">
                    <svg viewBox="0 0 24 24" width="16" height="16" style="margin-right: 8px;"><path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z" fill="currentColor"/></svg>
                    <span>Back</span>
                </div>
                <div class="shaka-settings-item" data-action="style-size">
                    <span>Font Size</span>
                    <span class="value">›</span>
                </div>
                <div class="shaka-settings-item" data-action="style-family">
                    <span>Font Family</span>
                    <span class="value">›</span>
                </div>
                <div class="shaka-settings-item" data-action="style-color">
                    <span>Font Color</span>
                    <span class="value">›</span>
                </div>
                <div class="shaka-settings-item" data-action="style-opacity">
                    <span>Text Opacity</span>
                    <span class="value">›</span>
                </div>
                <div class="shaka-settings-item" data-action="style-edge">
                    <span>Edge Style</span>
                    <span class="value">›</span>
                </div>
                <div class="shaka-settings-item" data-action="style-bg">
                    <span>Background</span>
                    <span class="value">›</span>
                </div>
                <div class="shaka-settings-item" data-action="style-pos">
                    <span>Position</span>
                    <span class="value">›</span>
                </div>
            `;

        } else if (submenu && submenu.startsWith('style-')) {
            // Generic handler for nested style submenus
            html = `
                <div class="shaka-settings-item shaka-settings-back" data-action="subtitle-style">
                    <svg viewBox="0 0 24 24" width="16" height="16" style="margin-right: 8px;"><path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z" fill="currentColor"/></svg>
                    <span>Back</span>
                </div>
            `;

            const prefs = this.subtitlePrefs;
            let items = [];
            let currentVal = '';
            let actionName = '';
            let label = '';

            switch (submenu) {
                case 'style-size':
                    label = 'Font Size';
                    actionName = 'set-font-size';
                    currentVal = prefs.fontSize;
                    items = [
                        { value: 'small', label: 'Small' },
                        { value: 'medium', label: 'Medium' },
                        { value: 'large', label: 'Large' },
                        { value: 'xlarge', label: 'X-Large' }
                    ];
                    break;
                case 'style-family':
                    label = 'Font Family';
                    actionName = 'set-font-family';
                    currentVal = prefs.fontFamily;
                    items = [
                        { value: 'arial', label: 'Arial', style: 'font-family: Arial, sans-serif' },
                        { value: 'serif', label: 'Times New Roman', style: 'font-family: Times New Roman, serif' }
                    ];
                    break;
                case 'style-color':
                    label = 'Font Color';
                    actionName = 'set-font-color';
                    currentVal = prefs.fontColor;
                    items = [
                        { value: '#ffffff', label: '● White', style: 'color: #ffffff' },
                        { value: '#ffff00', label: '● Yellow', style: 'color: #ffff00' },
                        { value: '#00ff00', label: '● Green', style: 'color: #00ff00' },
                        { value: '#00ffff', label: '● Cyan', style: 'color: #00ffff' },
                        { value: '#ff69b4', label: '● Pink', style: 'color: #ff69b4' }
                    ];
                    break;
                case 'style-opacity':
                    label = 'Text Opacity';
                    actionName = 'set-font-opacity';
                    currentVal = String(prefs.fontOpacity);
                    items = [
                        { value: '1', label: '100%' },
                        { value: '0.75', label: '75%' },
                        { value: '0.5', label: '50%' },
                        { value: '0.25', label: '25%' }
                    ];
                    break;
                case 'style-edge':
                    label = 'Edge Style';
                    actionName = 'set-edge-style';
                    currentVal = prefs.edgeStyle;
                    items = [
                        { value: 'none', label: 'None' },
                        { value: 'outline', label: 'Outline' },
                        { value: 'shadow', label: 'Drop Shadow' },
                        { value: 'raised', label: 'Raised' },
                        { value: 'depressed', label: 'Depressed' }
                    ];
                    break;
                case 'style-bg':
                    label = 'Background';
                    // This is tricky because we have color AND opacity in one menu? 
                    // Let's split them or combine them. For simplicity, let's keep them separate but here I'll just do Background Color first?
                    // Or I can add sections WITHIN this submenu.
                    // Let's do sections.
                    break;
                case 'style-pos':
                    label = 'Position';
                    actionName = 'set-position';
                    currentVal = prefs.position;
                    items = [
                        { value: 'bottom', label: 'Bottom' },
                        { value: 'lower', label: 'Lower' },
                        { value: 'middle', label: 'Middle' },
                        { value: 'higher', label: 'Higher' }
                    ];
                    break;
            }

            if (submenu === 'style-bg') {
                // Special handling for Background (Color + Opacity)
                html += `<div class="shaka-settings-label">Color</div>`;
                [
                    { value: '#000000', label: '● Black', style: 'color: #999' },
                    { value: '#ffffff', label: '● White', style: 'color: #ffffff' }
                ].forEach(item => {
                    const isActive = prefs.backgroundColor === item.value;
                    html += `
                        <div class="shaka-settings-item ${isActive ? 'active' : ''}" data-action="set-background-color" data-value="${item.value}">
                            <span style="${item.style || ''}">${item.label}</span>
                            ${isActive ? '<svg viewBox="0 0 24 24" width="16" height="16"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z" fill="currentColor"/></svg>' : ''}
                        </div>
                    `;
                });

                html += `<div class="shaka-settings-label">Opacity</div>`;
                [
                    { value: '0', label: '0%' },
                    { value: '0.25', label: '25%' },
                    { value: '0.5', label: '50%' },
                    { value: '0.75', label: '75%' },
                    { value: '1', label: '100%' }
                ].forEach(item => {
                    const isActive = String(prefs.backgroundOpacity) === item.value;
                    html += `
                        <div class="shaka-settings-item ${isActive ? 'active' : ''}" data-action="set-background-opacity" data-value="${item.value}">
                            <span>${item.label}</span>
                            ${isActive ? '<svg viewBox="0 0 24 24" width="16" height="16"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z" fill="currentColor"/></svg>' : ''}
                        </div>
                    `;
                });

            } else {
                // Standard single-list submenu
                html += `<div class="shaka-settings-label">${label}</div>`;
                items.forEach(item => {
                    const isActive = currentVal === item.value;
                    html += `
                        <div class="shaka-settings-item ${isActive ? 'active' : ''}" data-action="${actionName}" data-value="${item.value}">
                            <span style="${item.style || ''}">${item.label}</span>
                            ${isActive ? '<svg viewBox="0 0 24 24" width="16" height="16"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z" fill="currentColor"/></svg>' : ''}
                        </div>
                    `;
                });
            }

        } else {
            // Main menu
            const textTracks = this.player.getTextTracks();
            const isSubVisible = this.player.isTextTrackVisible();
            const activeSubTrack = textTracks.find(t => t.active);
            const subLabel = !isSubVisible ? 'Off' :
                (activeSubTrack?.language === 'vi' ? 'Tiếng Việt' :
                    activeSubTrack?.language === 'en' ? 'English' :
                        activeSubTrack?.label || 'On');

            const currentQuality = this.player.getVariantTracks().find(t => t.active)?.height + 'p' || 'Auto';

            html = `
                <div class="shaka-settings-item" data-action="speed">
                    <span>Speed</span>
                    <span class="value">${this.video.playbackRate === 1 ? 'Normal' : this.video.playbackRate + 'x'}</span>
                </div>
                <div class="shaka-settings-item" data-action="quality">
                    <span>Quality</span>
                    <span class="value">${currentQuality}</span>
                </div>
                <div class="shaka-settings-item" data-action="subtitles">
                    <span>Subtitles</span>
                    <span class="value">${subLabel}</span>
                </div>
            `;
        }

        this.settingsMenu.innerHTML = html;

        // Bind settings actions
        this.settingsMenu.querySelectorAll('.shaka-settings-item').forEach(item => {
            item.addEventListener('click', (e) => {
                e.stopPropagation();
                const action = item.dataset.action;
                const value = item.dataset.value;

                switch (action) {
                    case 'speed':
                        this.buildSettingsMenu('speed');
                        break;
                    case 'quality':
                        this.buildSettingsMenu('quality');
                        break;
                    case 'subtitles':
                        this.buildSettingsMenu('subtitles');
                        break;
                    case 'subtitle-style':
                        this.buildSettingsMenu('subtitle-style');
                        break;
                    case 'back':
                        this.buildSettingsMenu();
                        break;
                    case 'set-speed':
                        this.video.playbackRate = parseFloat(value);
                        this.buildSettingsMenu();
                        break;
                    case 'set-quality':
                        if (value === 'auto') {
                            // Enable ABR (auto quality)
                            this.player.configure({ abr: { enabled: true } });
                        } else {
                            // Disable ABR and select specific quality
                            this.player.configure({ abr: { enabled: false } });
                            const targetTrack = tracks.find(t => t.height === parseInt(value));
                            if (targetTrack) {
                                this.player.selectVariantTrack(targetTrack, true);
                            }
                        }
                        this.buildSettingsMenu();
                        break;
                    case 'set-subtitle':
                        if (value === 'off') {
                            this.player.setTextTrackVisibility(false);
                        } else {
                            const textTracks = this.player.getTextTracks();
                            const trackIndex = parseInt(value);
                            if (textTracks[trackIndex]) {
                                this.player.selectTextTrack(textTracks[trackIndex]);
                                this.player.setTextTrackVisibility(true);
                                this.applySubtitleStyles();
                            }
                        }
                        this.buildSettingsMenu('subtitles');
                        break;
                    case 'upload-subtitle':
                        // ... existing upload logic ...
                        const fileInput = document.createElement('input');
                        fileInput.type = 'file';
                        fileInput.accept = '.vtt,.srt,.ass,.ssa';
                        fileInput.onchange = (e) => {
                            if (e.target.files[0]) {
                                this.handleUserSubtitleUpload(e.target.files[0]);
                            }
                        };
                        fileInput.click();
                        this.settingsMenu.classList.remove('active');
                        break;

                    // Navigation to submenus
                    case 'style-size':
                    case 'style-family':
                    case 'style-color':
                    case 'style-opacity':
                    case 'style-edge':
                    case 'style-bg':
                    case 'style-pos':
                        this.buildSettingsMenu(action);
                        break;

                    // Setting values
                    case 'set-font-size':
                        this.subtitlePrefs.fontSize = value;
                        this.saveSubtitlePrefs();
                        this.buildSettingsMenu('style-size');
                        break;
                    case 'set-font-family':
                        this.subtitlePrefs.fontFamily = value;
                        this.saveSubtitlePrefs();
                        this.buildSettingsMenu('style-family');
                        break;
                    case 'set-font-color':
                        this.subtitlePrefs.fontColor = value;
                        this.saveSubtitlePrefs();
                        this.buildSettingsMenu('style-color');
                        break;
                    case 'set-font-opacity':
                        this.subtitlePrefs.fontOpacity = parseFloat(value);
                        this.saveSubtitlePrefs();
                        this.buildSettingsMenu('style-opacity');
                        break;
                    case 'set-edge-style':
                        this.subtitlePrefs.edgeStyle = value;
                        this.saveSubtitlePrefs();
                        this.buildSettingsMenu('style-edge');
                        break;
                    case 'set-background-color':
                        this.subtitlePrefs.backgroundColor = value;
                        this.saveSubtitlePrefs();
                        this.buildSettingsMenu('style-bg');
                        break;
                    case 'set-background-opacity':
                        this.subtitlePrefs.backgroundOpacity = parseFloat(value);
                        this.saveSubtitlePrefs();
                        this.buildSettingsMenu('style-bg');
                        break;
                    case 'set-position':
                        this.subtitlePrefs.position = value;
                        this.saveSubtitlePrefs();
                        this.buildSettingsMenu('style-pos');
                        break;
                }
            });
        });
    }

    // ==================== KEYBOARD SHORTCUTS ====================

    handleKeyboard(e) {
        // Ignore if typing in input
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;

        switch (e.key.toLowerCase()) {
            case ' ':
            case 'k':
                e.preventDefault();
                this.togglePlay();
                break;
            case 'arrowleft':
            case 'j':
                this.seek(-10);
                break;
            case 'arrowright':
            case 'l':
                this.seek(10);
                break;
            case 'arrowup':
                e.preventDefault();
                this.video.volume = Math.min(1, this.video.volume + 0.1);
                break;
            case 'arrowdown':
                e.preventDefault();
                this.video.volume = Math.max(0, this.video.volume - 0.1);
                break;
            case 'f':
                this.toggleFullscreen();
                break;
            case 'm':
                this.toggleMute();
                break;
            case 'i':
                this.toggleStats();
                break;
        }
    }

    // ==================== UTILITIES ====================

    formatTime(seconds) {
        if (isNaN(seconds) || !isFinite(seconds)) return '0:00';
        const h = Math.floor(seconds / 3600);
        const m = Math.floor((seconds % 3600) / 60);
        const s = Math.floor(seconds % 60);
        if (h > 0) {
            return `${h}:${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
        }
        return `${m}:${s.toString().padStart(2, '0')}`;
    }

    setCookie(name, value, days) {
        const expires = new Date(Date.now() + days * 864e5).toUTCString();
        document.cookie = `${name}=${value}; expires=${expires}; path=/`;
    }

    getCookie(name) {
        const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
        return match ? parseFloat(match[2]) : null;
    }

    deleteCookie(name) {
        document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/`;
    }

    onError(event) {
        console.error('Shaka Player error:', event.detail);
    }

    // ==================== SVG ICONS ====================

    getPlayIcon() {
        return '<svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>';
    }

    getPauseIcon() {
        return '<svg viewBox="0 0 24 24"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>';
    }

    getVolumeIcon() {
        return '<svg viewBox="0 0 24 24"><path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z"/></svg>';
    }

    getMutedIcon() {
        return '<svg viewBox="0 0 24 24"><path d="M16.5 12c0-1.77-1.02-3.29-2.5-4.03v2.21l2.45 2.45c.03-.2.05-.41.05-.63zm2.5 0c0 .94-.2 1.82-.54 2.64l1.51 1.51C20.63 14.91 21 13.5 21 12c0-4.28-2.99-7.86-7-8.77v2.06c2.89.86 5 3.54 5 6.71zM4.27 3L3 4.27 7.73 9H3v6h4l5 5v-6.73l4.25 4.25c-.67.52-1.42.93-2.25 1.18v2.06c1.38-.31 2.63-.95 3.69-1.81L19.73 21 21 19.73l-9-9L4.27 3zM12 4L9.91 6.09 12 8.18V4z"/></svg>';
    }

    getFullscreenIcon() {
        return '<svg viewBox="0 0 24 24"><path d="M7 14H5v5h5v-2H7v-3zm-2-4h2V7h3V5H5v5zm12 7h-3v2h5v-5h-2v3zM14 5v2h3v3h2V5h-5z"/></svg>';
    }

    getExitFullscreenIcon() {
        return '<svg viewBox="0 0 24 24"><path d="M5 16h3v3h2v-5H5v2zm3-8H5v2h5V5H8v3zm6 11h2v-3h3v-2h-5v5zm2-11V5h-2v5h5V8h-3z"/></svg>';
    }
}

// Export for global use
window.ShakaPlayerController = ShakaPlayerController;
