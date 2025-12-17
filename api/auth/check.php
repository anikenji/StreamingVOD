<?php
/**
 * Check Authentication Status API
 * GET /api/auth/check.php
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

enableCORS();

if (isLoggedIn()) {
    successResponse([
        'authenticated' => true,
        'user' => getCurrentUser()
    ]);
} else {
    jsonResponse([
        'success' => true,
        'authenticated' => false
    ]);
}
