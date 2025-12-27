<?php
/**
 * Embed Router - Browser-based Player Selection
 * 
 * Routes to appropriate player based on browser/device:
 * - PC/Android (Chrome, Firefox, Edge): Shaka Player (DASH/AV1 preferred)
 * - iOS/Safari: JWPlayer (HLS/H.264 for compatibility)
 */

// Detect Safari/iOS from User-Agent
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

// iOS detection (iPad, iPhone, iPod)
$isIOS = preg_match('/iPad|iPhone|iPod/i', $userAgent);

// Safari detection (Safari but not Chrome/Android)
// Chrome on Mac also contains "Safari" in UA, so we need to exclude it
$isSafari = preg_match('/Safari/i', $userAgent)
    && !preg_match('/Chrome|CriOS|Android/i', $userAgent);

// macOS Safari also needs JWPlayer (no AV1 support)
$isMacSafari = preg_match('/Macintosh.*Safari/i', $userAgent)
    && !preg_match('/Chrome|CriOS/i', $userAgent);

// Determine which player to use
$useJWPlayer = $isIOS || $isSafari || $isMacSafari;

if ($useJWPlayer) {
    // iOS/Safari: Use JWPlayer with HLS for best compatibility
    require_once __DIR__ . '/embed.jwplayer.php';
} else {
    // PC/Android: Use Shaka Player with DASH for AV1 quality
    require_once __DIR__ . '/embed.shaka.php';
}