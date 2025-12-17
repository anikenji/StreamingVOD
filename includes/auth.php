<?php
/**
 * Authentication Helper Functions
 * Session management and access control
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_start();
}

/**
 * Register a new user
 */
function registerUser($username, $email, $password, $role = 'user') {
    $db = db();
    
    // Hash password
    $passwordHash = password_hash($password, PASSWORD_BCRYPT);
    
    $sql = "INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)";
    $result = $db->execute($sql, [$username, $email, $passwordHash, $role]);
    
    if ($result) {
        return $db->lastInsertId();
    }
    return false;
}

/**
 * Check if username exists
 */
function usernameExists($username) {
    $db = db();
    $sql = "SELECT id FROM users WHERE username = ?";
    $result = $db->queryOne($sql, [$username]);
    return $result !== false;
}

/**
 * Check if email exists
 */
function emailExists($email) {
    $db = db();
    $sql = "SELECT id FROM users WHERE email = ?";
    $result = $db->queryOne($sql, [$email]);
    return $result !== false;
}

/**
 * Authenticate user with username/email and password
 */
function authenticateUser($usernameOrEmail, $password) {
    $db = db();
    
    // Check if input is email or username
    $sql = "SELECT * FROM users WHERE username = ? OR email = ?";
    $user = $db->queryOne($sql, [$usernameOrEmail, $usernameOrEmail]);
    
    if (!$user) {
        return false;
    }
    
    // Verify password
    if (!password_verify($password, $user['password_hash'])) {
        return false;
    }
    
    // Update last login
    $updateSql = "UPDATE users SET last_login = NOW() WHERE id = ?";
    $db->execute($updateSql, [$user['id']]);
    
    // Set session
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['logged_in'] = true;
    
    return $user;
}

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}

/**
 * Get current user info from session
 */
function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    
    return [
        'id' => $_SESSION['user_id'],
        'username' => $_SESSION['username'],
        'email' => $_SESSION['email'],
        'role' => $_SESSION['role']
    ];
}

/**
 * Get user ID from session
 */
function getCurrentUserId() {
    return isLoggedIn() ? $_SESSION['user_id'] : null;
}

/**
 * Check if current user is admin
 */
function isAdmin() {
    return isLoggedIn() && $_SESSION['role'] === 'admin';
}

/**
 * Require authentication (middleware)
 */
function requireAuth() {
    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Authentication required'
        ]);
        exit;
    }
}

/**
 * Require admin role (middleware)
 */
function requireAdmin() {
    requireAuth();
    
    if (!isAdmin()) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Admin access required'
        ]);
        exit;
    }
}

/**
 * Logout user
 */
function logoutUser() {
    $_SESSION = [];
    
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }
    
    session_destroy();
}

/**
 * Get user by ID
 */
function getUserById($userId) {
    $db = db();
    $sql = "SELECT id, username, email, role, created_at, last_login FROM users WHERE id = ?";
    return $db->queryOne($sql, [$userId]);
}

/**
 * Update user password
 */
function updateUserPassword($userId, $newPassword) {
    $db = db();
    $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT);
    $sql = "UPDATE users SET password_hash = ? WHERE id = ?";
    return $db->execute($sql, [$passwordHash, $userId]) > 0;
}
