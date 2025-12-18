<?php
/**
 * API: List all users (Admin only)
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json');

// Require admin authentication
requireAdmin();

try {
    $db = db();

    $sql = "SELECT id, username, email, role, created_at, last_login FROM users ORDER BY created_at DESC";
    $users = $db->query($sql);

    echo json_encode([
        'success' => true,
        'users' => $users
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to fetch users: ' . $e->getMessage()
    ]);
}
