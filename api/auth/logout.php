<?php
/**
 * User Logout API
 * POST /api/auth/logout.php
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

enableCORS();

// Require authentication
requireAuth();

$username = $_SESSION['username'];

// Logout user
logoutUser();

logMessage("User logged out: $username", 'INFO');

successResponse([], 'Logout successful');
