<?php
/**
 * API: Change user password
 * Admin can change any user's password
 * Regular users can only change their own password (requires current password)
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json');

// Require authentication
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$userId = $input['user_id'] ?? null;
$currentPassword = $input['current_password'] ?? null;
$newPassword = $input['new_password'] ?? null;

if (!$userId || !$newPassword) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'User ID and new password are required']);
    exit;
}

if (strlen($newPassword) < 6) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Password must be at least 6 characters']);
    exit;
}

$currentUserId = getCurrentUserId();
$isAdmin = isAdmin();

try {
    $db = db();

    // Check if user exists
    $user = $db->queryOne("SELECT id, password_hash FROM users WHERE id = ?", [$userId]);
    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit;
    }

    // If changing own password, require current password verification
    if ($userId == $currentUserId) {
        if (!$currentPassword) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Current password is required']);
            exit;
        }

        if (!password_verify($currentPassword, $user['password_hash'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Current password is incorrect']);
            exit;
        }
    } else {
        // Only admin can change other users' passwords
        if (!$isAdmin) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'You can only change your own password']);
            exit;
        }
    }

    // Update password
    $result = updateUserPassword($userId, $newPassword);

    if ($result) {
        echo json_encode(['success' => true, 'message' => 'Password changed successfully']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to update password']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}
