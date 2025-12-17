<?php
/**
 * User Login API
 * POST /api/auth/login.php
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

enableCORS();

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Method not allowed', 405);
}

// Get POST data
$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    // Try form-encoded data
    $data = $_POST;
}

$usernameOrEmail = trim($data['username'] ?? $data['email'] ?? '');
$password = $data['password'] ?? '';

// Validation
if (empty($usernameOrEmail) || empty($password)) {
    errorResponse('Username/email and password are required');
}

// Authenticate user
$user = authenticateUser($usernameOrEmail, $password);

if ($user) {
    logMessage("User logged in: {$user['username']} (ID: {$user['id']})", 'INFO');
    
    successResponse([
        'user' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'role' => $user['role']
        ],
        'session_id' => session_id()
    ], 'Login successful');
} else {
    errorResponse('Invalid credentials', 401);
}
