<?php
/**
 * Security Functions for HLS Stream Protection
 * Token encryption/decryption and validation
 */

require_once __DIR__ . '/../config/config.php';

// Security key - should be set in config.php or environment
if (!defined('STREAM_SECRET_KEY')) {
    define('STREAM_SECRET_KEY', 'change_this_to_a_secure_random_string_32chars!');
}

// Token expiry time in seconds (default 4 hours)
if (!defined('STREAM_TOKEN_EXPIRY')) {
    define('STREAM_TOKEN_EXPIRY', 4 * 60 * 60);
}

/**
 * Encrypt video ID into a secure token
 * Token includes: video_id + timestamp + signature
 */
function encryptVideoToken($videoId, $expirySeconds = null)
{
    $expiry = time() + ($expirySeconds ?? STREAM_TOKEN_EXPIRY);

    $payload = [
        'id' => $videoId,
        'exp' => $expiry,
        'nonce' => bin2hex(random_bytes(8))
    ];

    $json = json_encode($payload);

    // Encrypt with AES-256-GCM
    $key = hash('sha256', STREAM_SECRET_KEY, true);
    $iv = random_bytes(12);
    $tag = '';

    $encrypted = openssl_encrypt($json, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

    // Combine iv + tag + encrypted data
    $combined = $iv . $tag . $encrypted;

    // URL-safe base64
    return rtrim(strtr(base64_encode($combined), '+/', '-_'), '=');
}

/**
 * Decrypt and validate token
 * Returns video ID if valid, false otherwise
 */
function decryptVideoToken($token)
{
    try {
        // URL-safe base64 decode
        $combined = base64_decode(strtr($token, '-_', '+/'));

        if (strlen($combined) < 28) {
            return false; // Too short
        }

        // Extract iv (12 bytes), tag (16 bytes), encrypted data
        $iv = substr($combined, 0, 12);
        $tag = substr($combined, 12, 16);
        $encrypted = substr($combined, 28);

        $key = hash('sha256', STREAM_SECRET_KEY, true);

        $json = openssl_decrypt($encrypted, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

        if ($json === false) {
            return false;
        }

        $payload = json_decode($json, true);

        if (!$payload || !isset($payload['id']) || !isset($payload['exp'])) {
            return false;
        }

        // Check expiry
        if ($payload['exp'] < time()) {
            return false;
        }

        return $payload['id'];

    } catch (Exception $e) {
        return false;
    }
}

/**
 * Generate signed URL for playlist/segment
 * Shorter token for each stream request
 */
function generateStreamSignature($videoId, $path, $expirySeconds = 300)
{
    $expiry = time() + $expirySeconds;
    $data = $videoId . '|' . $path . '|' . $expiry;
    $signature = hash_hmac('sha256', $data, STREAM_SECRET_KEY);

    return [
        'expires' => $expiry,
        'signature' => substr($signature, 0, 16) // Short signature
    ];
}

/**
 * Validate stream signature
 */
function validateStreamSignature($videoId, $path, $expires, $signature)
{
    if ($expires < time()) {
        return false;
    }

    $data = $videoId . '|' . $path . '|' . $expires;
    $expected = hash_hmac('sha256', $data, STREAM_SECRET_KEY);

    return hash_equals(substr($expected, 0, 16), $signature);
}

/**
 * Get allowed referer domains
 */
function getAllowedDomains()
{
    return [
        'anikenji.live',
        'service.anikenji.live',
        'localhost',
        '127.0.0.1'
    ];
}

/**
 * Check if request comes from allowed domain
 */
function isAllowedReferer()
{
    $referer = $_SERVER['HTTP_REFERER'] ?? '';

    if (empty($referer)) {
        return false;
    }

    $parsed = parse_url($referer);
    $host = $parsed['host'] ?? '';

    foreach (getAllowedDomains() as $domain) {
        if ($host === $domain || str_ends_with($host, '.' . $domain)) {
            return true;
        }
    }

    return false;
}
