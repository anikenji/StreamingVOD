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
                <a href="#" class="nav-item" data-page="movies">
                    <span class="icon">🎬</span>
                    <span>Movies</span>
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
                <!-- Search & Filter Bar -->
                <div class="search-bar"
                    style="display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; align-items: center;">
                    <input type="text" id="video-search" placeholder="🔍 Tìm video theo tên..."
                        style="flex: 1; min-width: 200px; padding: 12px 16px; border-radius: 8px; border: 1px solid var(--border); background: var(--bg-secondary); color: var(--text-primary);"
                        oninput="debounceSearch()">
                    <select id="video-status-filter" onchange="loadVideos(1)"
                        style="padding: 12px 16px; border-radius: 8px; border: 1px solid var(--border); background: var(--bg-secondary); color: var(--text-primary);">
                        <option value="">Tất cả status</option>
                        <option value="completed">Completed</option>
                        <option value="processing">Processing</option>
                        <option value="pending">Pending</option>
                        <option value="failed">Failed</option>
                    </select>
                </div>

                <div class="videos-grid" id="videos-grid">
                    <!-- Videos will be loaded here -->
                </div>

                <!-- Pagination -->
                <div id="videos-pagination"
                    style="display: flex; justify-content: center; align-items: center; gap: 8px; margin-top: 24px;">
                    <!-- Pagination will be rendered here -->
                </div>

                <div class="loading" id="videos-loading">
                    <div class="spinner"></div>
                </div>
                <div class="empty-state" id="videos-empty" style="display: none;">
                    <div class="empty-icon">📹</div>
                    <h3>No videos yet</h3>
                    <p>Upload your first video to get started</p>
                    <button class="btn-primary" onclick="showUploadPage()">Upload Video</button>
                </div>
            </div>

            <!-- Movies Page -->
            <div id="movies-page" class="page">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
                    <h3>🎬 My Movies/Series</h3>
                    <button class="btn-primary" onclick="showCreateMovieModal()">+ New Movie</button>
                </div>
                <div class="movies-grid" id="movies-grid">
                    <!-- Movies will be loaded here -->
                </div>
                <div class="loading" id="movies-loading">
                    <div class="spinner"></div>
                    <p>Loading movies...</p>
                </div>
                <div class="empty-state" id="movies-empty" style="display: none;">
                    <div class="empty-icon">🎬</div>
                    <h3>No movies yet</h3>
                    <p>Create a movie/series to organize your videos</p>
                    <button class="btn-primary" onclick="showCreateMovieModal()">+ New Movie</button>
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

            <!-- Users Page (Admin Only) -->
            <div id="users-page" class="page">
                <div class="users-container">
                    <div class="users-table-wrapper">
                        <table class="users-table" id="users-table">
                            <thead>
                                <tr>
                                    <th>Username</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Created</th>
                                    <th>Last Login</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="users-tbody">
                                <!-- Users will be loaded here -->
                            </tbody>
                        </table>
                    </div>
                    <div class="loading" id="users-loading">
                        <div class="spinner"></div>
                        <p>Loading users...</p>
                    </div>
                    <div class="empty-state" id="users-empty" style="display: none;">
                        <div class="empty-icon">👥</div>
                        <h3>No users found</h3>
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

    <!-- Create Movie Modal -->
    <div id="movie-create-modal" class="modal">
        <div class="modal-content" style="max-width: 500px;">
            <span class="modal-close" onclick="closeMovieCreateModal()">&times;</span>
            <h2>Create New Movie/Series</h2>
            <form onsubmit="createMovie(event)" style="margin-top: 20px;">
                <div class="form-group">
                    <label>Title *</label>
                    <input type="text" id="movie-title" required placeholder="e.g. Attack on Titan">
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea id="movie-description" rows="3" placeholder="Optional description"></textarea>
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select id="movie-status">
                        <option value="ongoing">Ongoing</option>
                        <option value="completed">Completed</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Total Episodes (optional)</label>
                    <input type="number" id="movie-total-episodes" placeholder="e.g. 12">
                </div>
                <button type="submit" class="btn-primary" style="width: 100%;">Create Movie</button>
            </form>
        </div>
    </div>

    <!-- Movie Detail Modal -->
    <div id="movie-detail-modal" class="modal">
        <div class="modal-content" style="max-width: 800px;">
            <span class="modal-close" onclick="closeMovieDetailModal()">&times;</span>
            <div id="movie-detail-body">
                <!-- Content loaded dynamically -->
            </div>
        </div>
    </div>

    <script src="js/app.js?v=<?= time() ?>"></script>
    <script src="js/upload.js?v=<?= time() ?>"></script>
</body>

</html>