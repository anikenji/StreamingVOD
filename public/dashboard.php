<?php
/**
 * Main Dashboard Page
 * Requires authentication
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

// Require authentication
if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$user = getCurrentUser();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Dashboard</title>
    <link rel="stylesheet" href="css/style.css">
</head>

<body>
    <div class="dashboard">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="logo">
                <h1>🎬 HLS Stream</h1>
            </div>

            <nav class="nav">
                <a href="#" class="nav-item active" data-page="videos">
                    <span class="icon">📹</span>
                    <span>My Videos</span>
                </a>
                <a href="#" class="nav-item" data-page="upload">
                    <span class="icon">⬆️</span>
                    <span>Upload</span>
                </a>
                <?php if (isAdmin()): ?>
                    <a href="#" class="nav-item" data-page="users">
                        <span class="icon">👥</span>
                        <span>Users</span>
                    </a>
                <?php endif; ?>
            </nav>

            <div class="user-info">
                <div class="user-avatar"><?= strtoupper(substr($user['username'], 0, 1)) ?></div>
                <div class="user-details">
                    <div class="username"><?= htmlspecialchars($user['username']) ?></div>
                    <div class="user-role"><?= ucfirst($user['role']) ?></div>
                </div>
                <button class="btn-logout" onclick="logout()">Logout</button>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <header class="header">
                <h2 id="page-title">My Videos</h2>
                <div class="header-actions">
                    <button class="btn-primary" onclick="showUploadPage()">
                        <span>⬆️</span> Upload Video
                    </button>
                </div>
            </header>

            <!-- Videos Page -->
            <div id="videos-page" class="page active">
                <div class="videos-grid" id="videos-grid">
                    <!-- Videos will be loaded here -->
                </div>
                <div class="loading" id="videos-loading">
                    <div class="spinner"></div>
                    <p>Loading videos...</p>
                </div>
                <div class="empty-state" id="videos-empty" style="display: none;">
                    <div class="empty-icon">📹</div>
                    <h3>No videos yet</h3>
                    <p>Upload your first video to get started</p>
                    <button class="btn-primary" onclick="showUploadPage()">Upload Video</button>
                </div>
            </div>

            <!-- Upload Page -->
            <div id="upload-page" class="page">
                <div class="upload-container">
                    <div class="upload-zone" id="upload-zone">
                        <div class="upload-icon">📁</div>
                        <h3>Drag & drop your video here</h3>
                        <p>or click to browse</p>
                        <p class="upload-hint">Supported formats: MP4, MKV, AVI, MOV, WebM</p>
                        <input type="file" id="file-input" accept="video/*" style="display: none;">
                    </div>

                    <div class="upload-progress" id="upload-progress" style="display: none;">
                        <h3>Uploading...</h3>
                        <div class="progress-bar">
                            <div class="progress-fill" id="upload-progress-bar"></div>
                        </div>
                        <p id="upload-status">0%</p>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Video Detail Modal -->
    <div id="video-modal" class="modal">
        <div class="modal-content">
            <span class="modal-close" onclick="closeVideoModal()">&times;</span>
            <div id="modal-body">
                <!-- Content loaded dynamically -->
            </div>
        </div>
    </div>

    <script src="js/app.js?v=<?= time() ?>"></script>
    <script src="js/upload.js?v=<?= time() ?>"></script>
</body>

</html>