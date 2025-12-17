<?php
/**
 * User Registration API
 * POST /api/auth/register.php
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

$username = trim($data['username'] ?? '');
$email = trim($data['email'] ?? '');
$password = $data['password'] ?? '';

// Validation
if (empty($username) || empty($email) || empty($password)) {
    errorResponse('All fields are required');
}

if (!validateUsername($username)) {
    errorResponse('Invalid username. Use 3-50 alphanumeric characters or underscore');
}

if (!validateEmail($email)) {
    errorResponse('Invalid email format');
}

if (!validatePassword($password)) {
    errorResponse('Password must be at least 6 characters');
}

// Check if username exists
if (usernameExists($username)) {
    errorResponse('Username already exists');
}

// Check if email exists
if (emailExists($email)) {
    errorResponse('Email already exists');
}

// Register user
$userId = registerUser($username, $email, $password, 'user');

if ($userId) {
    logMessage("New user registered: $username (ID: $userId)", 'INFO');
    
    successResponse([
        'user' => [
            'id' => $userId,
            'username' => $username,
            'email' => $email
        ]
    ], 'Registration successful');
} else {
    errorResponse('Registration failed', 500);
}
