<?php
/**
 * API: Delete user (Admin only)
 * Cannot delete own account
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json');

// Require admin authentication
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$userId = $input['user_id'] ?? null;

if (!$userId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'User ID is required']);
    exit;
}

$currentUserId = getCurrentUserId();

// Cannot delete own account
if ($userId == $currentUserId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Cannot delete your own account']);
    exit;
}

try {
    $db = db();

    // Check if user exists
    $user = $db->queryOne("SELECT id, username FROM users WHERE id = ?", [$userId]);
    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit;
    }

    // Delete user (cascade will handle related records)
    $result = $db->execute("DELETE FROM users WHERE id = ?", [$userId]);

    if ($result) {
        echo json_encode([
            'success' => true,
            'message' => 'User "' . $user['username'] . '" deleted successfully'
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to delete user']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}
