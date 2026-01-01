/**
 * Main Dashboard Application JavaScript
 */

let currentVideos = [];
let progressPolling = null;

// Initialize on page load
document.addEventListener('DOMContentLoaded', () => {
    loadVideos();
    setupNavigation();
});

/**
 * Setup navigation
 */
function setupNavigation() {
    const navItems = document.querySelectorAll('.nav-item');

    navItems.forEach(item => {
        item.addEventListener('click', (e) => {
            e.preventDefault();
            const page = item.getAttribute('data-page');
            showPage(page);
        });
    });
}

/**
 * Show specific page
 */
function showPage(pageName) {
    // Update nav active state
    document.querySelectorAll('.nav-item').forEach(item => {
        item.classList.remove('active');
        if (item.getAttribute('data-page') === pageName) {
            item.classList.add('active');
        }
    });

    // Update page visibility
    document.querySelectorAll('.page').forEach(page => {
        page.classList.remove('active');
    });

    if (pageName === 'videos') {
        document.getElementById('videos-page').classList.add('active');
        document.getElementById('page-title').textContent = 'My Videos';
        loadVideos();
    } else if (pageName === 'movies') {
        document.getElementById('movies-page').classList.add('active');
        document.getElementById('page-title').textContent = 'Movies';
        loadMovies();
    } else if (pageName === 'upload') {
        document.getElementById('upload-page').classList.add('active');
        document.getElementById('page-title').textContent = 'Upload Video';
    } else if (pageName === 'users') {
        document.getElementById('users-page').classList.add('active');
        document.getElementById('page-title').textContent = 'User Management';
        loadUsers();
    }
}

function showUploadPage() {
    showPage('upload');
}

// Pagination state
let currentPage = 1;
let totalPages = 1;
let searchTimeout = null;

/**
 * Debounce search input
 */
function debounceSearch() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        loadVideos(1);
    }, 300);
}

/**
 * Load videos from API with pagination and search
 */
async function loadVideos(page = 1) {
    const grid = document.getElementById('videos-grid');
    const loading = document.getElementById('videos-loading');
    const empty = document.getElementById('videos-empty');
    const pagination = document.getElementById('videos-pagination');

    currentPage = page;
    loading.style.display = 'block';
    grid.innerHTML = '';
    empty.style.display = 'none';
    if (pagination) pagination.innerHTML = '';

    // Get search and filter values
    const searchInput = document.getElementById('video-search');
    const statusFilter = document.getElementById('video-status-filter');
    const search = searchInput ? searchInput.value.trim() : '';
    const status = statusFilter ? statusFilter.value : '';

    // Build URL with parameters
    const params = new URLSearchParams({
        page: page,
        limit: 50
    });
    if (search) params.append('search', search);
    if (status) params.append('status', status);

    try {
        const response = await fetch('/api/videos/list.php?' + params.toString());
        const data = await response.json();

        if (data.success) {
            currentVideos = data.videos;
            totalPages = data.pagination?.pages || 1;

            if (currentVideos.length === 0) {
                loading.style.display = 'none';
                empty.style.display = 'block';
                empty.querySelector('h3').textContent = search ? 'Không tìm thấy video' : 'No videos yet';
                empty.querySelector('p').textContent = search ? 'Thử từ khóa khác' : 'Upload your first video to get started';
            } else {
                loading.style.display = 'none';
                renderVideos(currentVideos);
                renderPagination(data.pagination);

                // Start progress polling for processing videos
                startProgressPolling();
            }
        }
    } catch (error) {
        console.error('Error loading videos:', error);
        loading.innerHTML = '<p style="color: var(--danger);">Failed to load videos</p>';
    }
}

/**
 * Render pagination controls
 */
function renderPagination(pagination) {
    const container = document.getElementById('videos-pagination');
    if (!container || !pagination || pagination.pages <= 1) return;

    const { page, pages, total } = pagination;
    let html = `<span style="color: var(--text-muted); margin-right: 12px;">Tổng: ${total} video</span>`;

    // Previous button
    html += `<button class="btn-small" onclick="loadVideos(${page - 1})" ${page <= 1 ? 'disabled' : ''} style="opacity: ${page <= 1 ? '0.5' : '1'}">◀ Prev</button>`;

    // Page numbers (show 5 pages around current)
    const startPage = Math.max(1, page - 2);
    const endPage = Math.min(pages, page + 2);

    if (startPage > 1) {
        html += `<button class="btn-small" onclick="loadVideos(1)">1</button>`;
        if (startPage > 2) html += `<span style="padding: 0 8px;">...</span>`;
    }

    for (let i = startPage; i <= endPage; i++) {
        const active = i === page ? 'background: var(--primary); color: white;' : '';
        html += `<button class="btn-small" onclick="loadVideos(${i})" style="${active}">${i}</button>`;
    }

    if (endPage < pages) {
        if (endPage < pages - 1) html += `<span style="padding: 0 8px;">...</span>`;
        html += `<button class="btn-small" onclick="loadVideos(${pages})">${pages}</button>`;
    }

    // Next button
    html += `<button class="btn-small" onclick="loadVideos(${page + 1})" ${page >= pages ? 'disabled' : ''} style="opacity: ${page >= pages ? '0.5' : '1'}">Next ▶</button>`;

    container.innerHTML = html;
}

/**
 * Render videos in grid
 */
function renderVideos(videos) {
    const grid = document.getElementById('videos-grid');
    grid.innerHTML = '';

    videos.forEach(video => {
        const card = createVideoCard(video);
        grid.appendChild(card);
    });
}

/**
 * Create video card element
 */
function createVideoCard(video) {
    const card = document.createElement('div');
    card.className = 'video-card';
    card.onclick = () => showVideoDetail(video.id);

    const statusClass = `status-${video.status}`;
    const statusText = video.status.charAt(0).toUpperCase() + video.status.slice(1);

    card.innerHTML = `
        <div class="video-thumbnail">
            ${video.thumbnail_url ? `<img src="${video.thumbnail_url}" alt="${video.original_filename}">` : '<div class="placeholder">🎬</div>'}
            <div class="status-badge ${statusClass}">${statusText}</div>
        </div>
        <div class="video-info">
            <div class="video-title">${escapeHtml(video.original_filename)}</div>
            <div class="video-meta">
                ${video.duration_formatted} • ${video.file_size_formatted}
            </div>
            ${video.status === 'processing' ? `
                <div class="video-progress">
                    <div class="progress-bar">
                        <div class="progress-fill" id="progress-${video.id}" style="width: 0%"></div>
                    </div>
                </div>
            ` : ''}
            <div class="video-actions">
                ${video.status === 'completed' ? `
                    <button class="btn-small btn-view" onclick="event.stopPropagation(); openEmbed('${video.id}')">▶ Play</button>
                    <button class="btn-small btn-view" onclick="event.stopPropagation(); copyEmbedLink('${video.id}')">🔗 Embed</button>
                    <button class="btn-small btn-view" onclick="event.stopPropagation(); copyM3U8Link('${video.id}')">📋 M3U8</button>
                ` : ''}
                <button class="btn-small btn-delete" onclick="event.stopPropagation(); deleteVideo('${video.id}')">🗑️</button>
            </div>
        </div>
    `;

    return card;
}

/**
 * Show video detail modal
 */
async function showVideoDetail(videoId) {
    const modal = document.getElementById('video-modal');
    const modalBody = document.getElementById('modal-body');

    modalBody.innerHTML = '<div class="loading"><div class="spinner"></div><p>Loading...</p></div>';
    modal.classList.add('active');

    try {
        const response = await fetch(`/api/videos/detail.php?id=${videoId}`);
        const data = await response.json();

        if (data.success) {
            renderVideoDetail(data.video, data.encoding_jobs);
        }
    } catch (error) {
        modalBody.innerHTML = '<p style="color: var(--danger);">Failed to load video details</p>';
    }
}

/**
 * Render video detail in modal
 */
function renderVideoDetail(video, jobs) {
    const modalBody = document.getElementById('modal-body');

    const jobsHtml = jobs.map(job => `
        <div class="encoding-job">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                <strong>${job.quality}</strong>
                <span class="status-badge status-${job.status}" style="position: static;">${job.status}</span>
            </div>
            ${job.status === 'processing' ? `
                <div class="progress-bar">
                    <div class="progress-fill" style="width: ${job.progress}%"></div>
                </div>
                <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 4px;">
                    ${job.progress.toFixed(1)}% • ${job.fps} fps • ETA: ${job.eta_formatted || 'calculating...'}
                </div>
            ` : job.status === 'failed' ? `
                <div style="color: var(--danger); font-size: 0.85rem;">${job.error_message}</div>
            ` : ''}
        </div>
    `).join('');

    modalBody.innerHTML = `
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px; padding-right: 40px;">
            <h2 style="margin: 0;">${escapeHtml(video.original_filename)}</h2>
            <span class="status-badge status-${video.status}" style="position: static; flex-shrink: 0;">${video.status}</span>
        </div>
        <div style="color: var(--text-muted); margin-bottom: 24px;">
            ${video.duration_formatted} • ${video.file_size_formatted}
        </div>
        
        <div style="margin-bottom: 24px;">
            <h3 style="margin-bottom: 12px;">Encoding Progress</h3>
            <div class="progress-bar" style="height: 8px; margin-bottom: 8px;">
                <div class="progress-fill" style="width: ${video.overall_progress}%"></div>
            </div>
            <div style="font-size: 0.9rem; color: var(--text-muted);">
                Overall: ${video.overall_progress.toFixed(1)}% (${video.completed_jobs}/${video.total_jobs} completed)
            </div>
        </div>
        
        <div style="margin-bottom: 24px;">
            <h3 style="margin-bottom: 12px;">Quality Levels</h3>
            <div style="display: flex; flex-direction: column; gap: 16px;">
                ${jobsHtml}
            </div>
        </div>
        
        ${video.status === 'completed' ? `
            <div>
                <h3 style="margin-bottom: 12px;">Embed</h3>
                <div style="display: flex; gap: 12px;">
                    <button class="btn-primary" onclick="openEmbed('${video.id}')">▶ Play Video</button>
                    <button class="btn-primary" onclick="copyEmbedLink('${video.id}')">📋 Copy Link</button>
                </div>
            </div>
        ` : ''}
    `;
}

/**
 * Close video modal
 */
function closeVideoModal() {
    document.getElementById('video-modal').classList.remove('active');
}

/**
 * Open embed player
 */
function openEmbed(videoId) {
    window.open(`/embed/${videoId}`, '_blank');
}

/**
 * Copy embed link
 */
async function copyEmbedLink(videoId) {
    const embedUrl = `${window.location.origin}/embed/${videoId}`;

    try {
        await navigator.clipboard.writeText(embedUrl);
        alert('Embed link copied to clipboard!');
    } catch (error) {
        prompt('Copy this link:', embedUrl);
    }
}

/**
 * Copy M3U8 playlist link
 */
async function copyM3U8Link(videoId) {
    const m3u8Url = `${window.location.origin}/movie/hls/${videoId}/video.m3u8`;

    try {
        await navigator.clipboard.writeText(m3u8Url);
        alert('M3U8 link copied to clipboard!');
    } catch (error) {
        prompt('Copy this M3U8 link:', m3u8Url);
    }
}

/**
 * Delete video
 */
async function deleteVideo(videoId) {
    if (!confirm('Are you sure you want to delete this video? This action cannot be undone.')) {
        return;
    }

    try {
        const response = await fetch(`/api/videos/delete.php?id=${videoId}`, {
            method: 'DELETE'
        });

        const data = await response.json();

        if (data.success) {
            alert('Video deleted successfully');
            loadVideos();
        } else {
            alert('Failed to delete video: ' + data.error);
        }
    } catch (error) {
        alert('Error deleting video');
    }
}

/**
 * Start progress polling for processing videos
 */
function startProgressPolling() {
    // Clear existing polling
    if (progressPolling) {
        clearInterval(progressPolling);
    }

    // Poll every 3 seconds
    progressPolling = setInterval(() => {
        const processingVideos = currentVideos.filter(v => v.status === 'processing');

        if (processingVideos.length === 0) {
            clearInterval(progressPolling);
            return;
        }

        processingVideos.forEach(video => {
            updateVideoProgress(video.id);
        });
    }, 3000);
}

/**
 * Update progress for a specific video
 */
async function updateVideoProgress(videoId) {
    try {
        const response = await fetch(`/api/progress/poll.php?video_id=${videoId}`);
        const data = await response.json();

        if (data.success) {
            // Update progress bar
            const progressBar = document.getElementById(`progress-${videoId}`);
            if (progressBar) {
                progressBar.style.width = `${data.overall_progress}%`;
            }

            // Reload if status changed to completed
            if (data.video_status === 'completed') {
                loadVideos();
            }
        }
    } catch (error) {
        console.error('Error updating progress:', error);
    }
}

/**
 * Logout
 */
async function logout() {
    try {
        await fetch('/api/auth/logout.php', { method: 'POST' });
        window.location.href = '/';
    } catch (error) {
        window.location.href = '/';
    }
}

/**
 * Escape HTML
 */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// ================================
// Movies Management Functions
// ================================

let currentMovies = [];

/**
 * Load movies from API
 */
async function loadMovies() {
    const grid = document.getElementById('movies-grid');
    const loading = document.getElementById('movies-loading');
    const empty = document.getElementById('movies-empty');

    if (!grid) return;

    loading.style.display = 'block';
    empty.style.display = 'none';
    grid.innerHTML = '';

    try {
        const response = await fetch('/api/movies/list.php');
        const data = await response.json();

        loading.style.display = 'none';

        if (data.success && data.movies.length > 0) {
            currentMovies = data.movies;
            grid.innerHTML = data.movies.map(movie => renderMovieCard(movie)).join('');
        } else {
            empty.style.display = 'block';
        }
    } catch (error) {
        console.error('Failed to load movies:', error);
        loading.style.display = 'none';
        grid.innerHTML = '<p style="color: var(--danger);">Failed to load movies</p>';
    }
}

/**
 * Render movie card
 */
function renderMovieCard(movie) {
    const statusClass = movie.status === 'completed' ? 'status-completed' : 'status-processing';
    return `
        <div class="video-card" onclick="showMovieDetail(${movie.id})">
            <div class="video-thumbnail">
                ${movie.poster_url
            ? `<img src="${movie.poster_url}" alt="${escapeHtml(movie.title)}">`
            : `<div class="placeholder">🎬</div>`
        }
                <span class="status-badge ${statusClass}">${movie.status}</span>
            </div>
            <div class="video-info">
                <div class="video-title">${escapeHtml(movie.title)}</div>
                <div class="video-meta">
                    ${movie.video_count || 0} episodes
                </div>
                <div class="video-actions">
                    <button class="btn-small btn-view" onclick="event.stopPropagation(); showMovieDetail(${movie.id})">View</button>
                    <button class="btn-small btn-delete" onclick="event.stopPropagation(); deleteMovie(${movie.id})">Delete</button>
                </div>
            </div>
        </div>
    `;
}

/**
 * Show create movie modal
 */
function showCreateMovieModal() {
    document.getElementById('movie-create-modal').classList.add('active');
    document.getElementById('movie-title').value = '';
    document.getElementById('movie-description').value = '';
    document.getElementById('movie-status').value = 'ongoing';
    document.getElementById('movie-total-episodes').value = '';
}

function closeMovieCreateModal() {
    document.getElementById('movie-create-modal').classList.remove('active');
}

/**
 * Create new movie
 */
async function createMovie(event) {
    event.preventDefault();

    const title = document.getElementById('movie-title').value.trim();
    const description = document.getElementById('movie-description').value.trim();
    const status = document.getElementById('movie-status').value;
    const totalEpisodes = document.getElementById('movie-total-episodes').value || 0;

    try {
        const response = await fetch('/api/movies/create.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ title, description, status, total_episodes: totalEpisodes })
        });
        const data = await response.json();

        if (data.success) {
            closeMovieCreateModal();
            loadMovies();
            alert('Movie created successfully!');
        } else {
            alert('Error: ' + data.error);
        }
    } catch (error) {
        alert('Failed to create movie: ' + error.message);
    }
}

/**
 * Show movie detail modal
 */
async function showMovieDetail(movieId) {
    const modal = document.getElementById('movie-detail-modal');
    const body = document.getElementById('movie-detail-body');

    body.innerHTML = '<div class="loading"><div class="spinner"></div><p>Loading...</p></div>';
    modal.classList.add('active');

    try {
        const response = await fetch(`/api/movies/detail.php?id=${movieId}`);
        const data = await response.json();

        if (data.success) {
            renderMovieDetail(data.movie);
        } else {
            body.innerHTML = `<p style="color: var(--danger);">Error: ${data.error}</p>`;
        }
    } catch (error) {
        body.innerHTML = `<p style="color: var(--danger);">Failed to load movie</p>`;
    }
}

function closeMovieDetailModal() {
    document.getElementById('movie-detail-modal').classList.remove('active');
}

/**
 * Render movie detail with improved UI
 */
function renderMovieDetail(movie) {
    const body = document.getElementById('movie-detail-body');
    const statusClass = movie.status === 'completed' ? 'status-completed' : 'status-processing';

    const episodesHtml = movie.episodes.length > 0
        ? movie.episodes.map(ep => {
            const hasSubtitle = ep.subtitle_url ? '🔤' : '';
            const hasIntro = (ep.intro_start !== null && ep.intro_end !== null) ? '⏭' : '';
            const hasOutro = (ep.outro_start !== null && ep.outro_end !== null) ? '🔚' : '';
            const metaIcons = [hasSubtitle, hasIntro, hasOutro].filter(x => x).join(' ');

            return `
            <div class="episode-item" style="display: flex; justify-content: space-between; align-items: center; padding: 12px; background: rgba(255,255,255,0.05); border-radius: 8px; margin-bottom: 8px;">
                <div style="flex: 1;">
                    <strong style="color: var(--primary);">Tập ${ep.episode_number || '?'}</strong>
                    <span style="margin-left: 10px;">${escapeHtml(ep.original_filename || ep.episode_title || '')}</span>
                    <span style="color: var(--text-muted); font-size: 0.85em; margin-left: 8px;">${ep.duration_formatted}</span>
                    <span class="status-badge status-${ep.status}" style="position: static; margin-left: 8px; font-size: 0.7em;">${ep.status}</span>
                    ${metaIcons ? `<span style="margin-left: 8px; font-size: 0.8em;" title="Has: ${hasSubtitle ? 'Subtitle ' : ''}${hasIntro ? 'Intro ' : ''}${hasOutro ? 'Outro' : ''}">${metaIcons}</span>` : ''}
                </div>
                <div style="display: flex; gap: 6px;">
                    <button class="btn-small" onclick="showEditEpisodeModal('${ep.id}', ${movie.id}, ${JSON.stringify(ep).replace(/"/g, '&quot;')})" style="background: var(--secondary);" title="Edit Metadata">⚙</button>
                    <button class="btn-small btn-view" onclick="window.open('${ep.embed_url}', '_blank')">▶</button>
                    <button class="btn-small" onclick="copyToClipboard('${ep.embed_url}')" style="background: var(--primary);">📋</button>
                    <button class="btn-small btn-delete" onclick="removeVideoFromMovie('${ep.id}', ${movie.id})">✕</button>
                </div>
            </div>
        `}).join('')
        : '<p style="color: var(--text-muted); text-align: center; padding: 20px;">Chưa có tập nào. Upload hoặc thêm video từ danh sách bên dưới.</p>';

    body.innerHTML = `
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px; padding-right: 40px;">
            <h2 style="margin: 0;">${escapeHtml(movie.title)}</h2>
            <span class="status-badge ${statusClass}" style="position: static;">${movie.status}</span>
        </div>
        <p style="color: var(--text-muted); margin-bottom: 24px;">
            ${movie.episode_count} tập
            ${movie.description ? ` • ${escapeHtml(movie.description)}` : ''}
        </p>

        <!-- Episodes List -->
        <div style="margin-bottom: 24px;">
            <h3 style="margin-bottom: 12px;">📺 Danh sách tập</h3>
            <div id="movie-episodes-list" style="max-height: 300px; overflow-y: auto;">
                ${episodesHtml}
            </div>
        </div>

        <!-- Add Episodes Section -->
        <div style="margin-bottom: 24px; padding: 20px; background: rgba(99, 102, 241, 0.1); border-radius: 12px; border: 1px dashed var(--primary);">
            <h3 style="margin-bottom: 16px;">➕ Thêm tập mới</h3>
            
            <!-- Upload Zone -->
            <div id="movie-upload-zone" 
                style="border: 2px dashed var(--border); border-radius: 12px; padding: 30px; text-align: center; cursor: pointer; margin-bottom: 16px; transition: all 0.3s;"
                onclick="triggerMovieUpload(${movie.id})"
                ondragover="this.style.borderColor='var(--primary)'; event.preventDefault();"
                ondragleave="this.style.borderColor='var(--border)';"
                ondrop="handleMovieUploadDrop(event, ${movie.id})">
                <div style="font-size: 2rem; margin-bottom: 8px;">📁</div>
                <div>Kéo thả hoặc click để upload video</div>
                <div style="font-size: 0.85em; color: var(--text-muted);">Tự động detect số tập từ tên file</div>
            </div>
            <input type="file" id="movie-file-input" accept="video/*" style="display: none;" multiple onchange="handleMovieFileSelect(event, ${movie.id})">

            <!-- Or Select Existing Videos -->
            <div style="text-align: center; margin-bottom: 16px; color: var(--text-muted);">── hoặc ──</div>
            
            <button class="btn-primary" style="width: 100%;" onclick="showSelectVideosModal(${movie.id})">
                📹 Chọn từ video có sẵn
            </button>
        </div>

        <!-- Export Actions -->
        <div style="display: flex; gap: 12px; flex-wrap: wrap;">
            <button class="btn-primary" onclick="exportMovieLinks(${movie.id}, 'embed')">📋 Export Embed Links</button>
            <button class="btn-primary" style="background: var(--warning);" onclick="exportMovieLinks(${movie.id}, 'm3u8')">📋 Export M3U8 Links</button>
        </div>
    `;
}

/**
 * Export movie links
 */
async function exportMovieLinks(movieId, type = 'both') {
    try {
        const response = await fetch(`/api/movies/export-links.php?id=${movieId}&type=${type}`);
        const data = await response.json();

        if (data.success) {
            const formatted = data.formatted;
            await navigator.clipboard.writeText(formatted);
            alert(`Copied ${data.episode_count} ${type} links to clipboard!\n\nFormat: episode|link|type`);
        } else {
            alert('Error: ' + data.error);
        }
    } catch (error) {
        alert('Failed to export: ' + error.message);
    }
}

/**
 * Delete movie
 */
async function deleteMovie(movieId) {
    if (!confirm('Are you sure you want to delete this movie? Videos will be kept but unlinked.')) return;

    try {
        const response = await fetch('/api/movies/delete.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: movieId })
        });
        const data = await response.json();

        if (data.success) {
            loadMovies();
        } else {
            alert('Error: ' + data.error);
        }
    } catch (error) {
        alert('Failed to delete: ' + error.message);
    }
}

/**
 * Remove video from movie
 */
async function removeVideoFromMovie(videoId, movieId) {
    if (!confirm('Remove this episode from the movie?')) return;

    try {
        const response = await fetch('/api/movies/remove-video.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ video_id: videoId })
        });
        const data = await response.json();

        if (data.success) {
            showMovieDetail(movieId);
        } else {
            alert('Error: ' + data.error);
        }
    } catch (error) {
        alert('Failed to remove: ' + error.message);
    }
}

/**
 * Show add video to movie modal - now replaced by showSelectVideosModal
 */
async function showAddVideoToMovieModal(movieId) {
    showSelectVideosModal(movieId);
}

/**
 * Parse episode number from filename
 * Patterns: "Ep 01", "Episode 1", " - 01", "[01]", "_01_", ".01."
 */
function parseEpisodeNumber(filename) {
    const patterns = [
        /(?:ep|episode|tập|tap|e)\s*\.?\s*(\d+)/i,  // Ep 01, Episode 1, E01
        /\s+-\s*(\d+)/,                              // - 01
        /\[(\d+)\]/,                                 // [01]
        /[_\s](\d{1,3})[_\s\.]/,                     // _01_, .01.
        /(\d{1,3})\s*(?:end|final)?\.?\s*$/i        // 01.mp4, 12 END.mkv
    ];

    for (const pattern of patterns) {
        const match = filename.match(pattern);
        if (match) {
            return parseInt(match[1], 10);
        }
    }
    return null;
}

/**
 * Trigger movie upload file input
 */
function triggerMovieUpload(movieId) {
    const input = document.getElementById('movie-file-input');
    input.setAttribute('data-movie-id', movieId);
    input.click();
}

/**
 * Handle movie upload drop
 */
function handleMovieUploadDrop(event, movieId) {
    event.preventDefault();
    const files = event.dataTransfer.files;
    if (files.length > 0) {
        uploadFilesToMovie(files, movieId);
    }
}

/**
 * Handle movie file select
 */
function handleMovieFileSelect(event, movieId) {
    const files = event.target.files;
    if (files.length > 0) {
        uploadFilesToMovie(files, movieId);
    }
}

/**
 * Upload files to movie with auto episode detection and progress tracking
 * Uploads run in parallel for better performance
 */
let movieUploadControllers = new Map(); // Track active uploads for cancellation

async function uploadFilesToMovie(files, movieId) {
    const episodesListEl = document.getElementById('movie-episodes-list');
    const uploadPromises = [];

    for (const file of files) {
        const episodeNumber = parseEpisodeNumber(file.name);
        const uploadId = 'upload-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);

        // Show progress UI with cancel button
        if (episodesListEl) {
            const progressHtml = `
                <div id="${uploadId}" style="padding: 12px; background: rgba(99, 102, 241, 0.2); border-radius: 8px; margin-bottom: 8px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <span>📤 Uploading: ${escapeHtml(file.name)}</span>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span>Tập ${episodeNumber || 'Auto'}</span>
                            <button class="btn-small btn-delete" onclick="cancelMovieUpload('${uploadId}')" title="Cancel">✕</button>
                        </div>
                    </div>
                    <div class="progress-bar" style="height: 6px;">
                        <div class="progress-fill" style="width: 0%;" id="${uploadId}-progress"></div>
                    </div>
                    <div id="${uploadId}-status" style="font-size: 0.85em; color: var(--text-muted); margin-top: 4px;">0%</div>
                </div>
            `;
            episodesListEl.insertAdjacentHTML('beforeend', progressHtml);
        }

        // Create upload promise (run in parallel)
        const uploadPromise = (async () => {
            try {
                // Use chunked upload for large files (> 50MB)
                if (file.size > 50 * 1024 * 1024) {
                    await uploadFileChunkedToMovie(file, movieId, episodeNumber, uploadId);
                } else {
                    await uploadFileSingleToMovie(file, movieId, episodeNumber, uploadId);
                }
                return { success: true, file: file.name };
            } catch (error) {
                if (error.message === 'Upload cancelled') {
                    console.log('Upload cancelled:', file.name);
                    return { success: false, file: file.name, cancelled: true };
                } else {
                    console.error('Upload failed:', file.name, error);
                    updateMovieUploadStatus(uploadId, `❌ Error: ${error.message}`, true);
                    return { success: false, file: file.name, error: error.message };
                }
            }
        })();

        uploadPromises.push(uploadPromise);
    }

    // Wait for all uploads to complete (parallel)
    const results = await Promise.allSettled(uploadPromises);

    // Count results
    let successCount = 0;
    let failCount = 0;
    results.forEach(r => {
        if (r.status === 'fulfilled' && r.value.success) successCount++;
        else if (r.status === 'fulfilled' && !r.value.cancelled) failCount++;
    });

    // Show refresh button instead of auto-reload
    if (episodesListEl && successCount > 0) {
        const refreshHtml = `
            <div id="upload-complete-notice" style="padding: 16px; background: rgba(34, 197, 94, 0.2); border-radius: 8px; margin-top: 12px; text-align: center;">
                <p style="margin-bottom: 12px;">
                    ✅ ${successCount} video uploaded thành công${failCount > 0 ? `, ❌ ${failCount} thất bại` : ''}
                </p>
                <button class="btn-primary" onclick="showMovieDetail(${movieId}); document.getElementById('upload-complete-notice')?.remove();">
                    🔄 Refresh danh sách
                </button>
            </div>
        `;
        episodesListEl.insertAdjacentHTML('beforeend', refreshHtml);
    }
}

/**
 * Upload single file to movie with progress tracking
 */
function uploadFileSingleToMovie(file, movieId, episodeNumber, uploadId) {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        movieUploadControllers.set(uploadId, xhr);

        xhr.upload.addEventListener('progress', (e) => {
            if (e.lengthComputable) {
                const percent = Math.round((e.loaded / e.total) * 100);
                updateMovieUploadProgress(uploadId, percent);
            }
        });

        xhr.addEventListener('load', () => {
            movieUploadControllers.delete(uploadId);
            if (xhr.status === 200) {
                try {
                    const data = JSON.parse(xhr.responseText);
                    if (data.success) {
                        updateMovieUploadStatus(uploadId, '✅ Upload thành công! Đang xử lý...', false);
                        console.log('Uploaded:', file.name, 'Episode:', episodeNumber);
                        resolve(data);
                    } else {
                        updateMovieUploadStatus(uploadId, `❌ Error: ${data.error}`, true);
                        reject(new Error(data.error || 'Upload failed'));
                    }
                } catch (e) {
                    reject(new Error('Invalid response from server'));
                }
            } else {
                updateMovieUploadStatus(uploadId, `❌ HTTP Error: ${xhr.status}`, true);
                reject(new Error(`HTTP ${xhr.status}`));
            }
        });

        xhr.addEventListener('error', () => {
            movieUploadControllers.delete(uploadId);
            updateMovieUploadStatus(uploadId, '❌ Network error', true);
            reject(new Error('Network error'));
        });

        xhr.addEventListener('abort', () => {
            movieUploadControllers.delete(uploadId);
            updateMovieUploadStatus(uploadId, '⛔ Đã hủy', true);
            reject(new Error('Upload cancelled'));
        });

        const formData = new FormData();
        formData.append('video', file);
        formData.append('movie_id', movieId);
        if (episodeNumber) formData.append('episode_number', episodeNumber);

        xhr.open('POST', '/api/videos/upload.php');
        xhr.send(formData);
    });
}

/**
 * Upload large file in chunks to movie
 */
async function uploadFileChunkedToMovie(file, movieId, episodeNumber, uploadId) {
    const chunkSize = 5 * 1024 * 1024; // 5MB chunks
    const totalChunks = Math.ceil(file.size / chunkSize);
    const videoId = generateMovieUploadUUID();

    // Add to tracker so cancellation works
    movieUploadControllers.set(uploadId, { cancelled: false });

    for (let i = 0; i < totalChunks; i++) {
        // Check if cancelled
        const tracker = movieUploadControllers.get(uploadId);
        if (!tracker || tracker.cancelled) {
            movieUploadControllers.delete(uploadId);
            throw new Error('Upload cancelled');
        }

        const start = i * chunkSize;
        const end = Math.min(start + chunkSize, file.size);
        const chunk = file.slice(start, end);

        const formData = new FormData();
        formData.append('chunk', chunk);
        formData.append('video_id', videoId);
        formData.append('chunk_index', i);
        formData.append('total_chunks', totalChunks);
        formData.append('original_filename', file.name);
        formData.append('movie_id', movieId);
        if (episodeNumber) formData.append('episode_number', episodeNumber);

        const response = await fetch('/api/videos/upload.php', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (!data.success) {
            updateMovieUploadStatus(uploadId, `❌ Error: ${data.error}`, true);
            throw new Error(data.error || 'Upload failed');
        }

        // Update progress
        const percent = Math.round(((i + 1) / totalChunks) * 100);
        updateMovieUploadProgress(uploadId, percent);

        // Check if completed
        if (data.upload_complete) {
            updateMovieUploadStatus(uploadId, '✅ Upload thành công! Đang xử lý...', false);
            console.log('Uploaded:', file.name, 'Episode:', episodeNumber);
            return data;
        }
    }
}

/**
 * Update movie upload progress bar
 */
function updateMovieUploadProgress(uploadId, percent) {
    const progressBar = document.getElementById(`${uploadId}-progress`);
    const statusEl = document.getElementById(`${uploadId}-status`);

    if (progressBar) {
        progressBar.style.width = `${percent}%`;
    }
    if (statusEl && percent < 100) {
        statusEl.textContent = `${percent}%`;
    }
}

/**
 * Update movie upload status text
 */
function updateMovieUploadStatus(uploadId, message, isError) {
    const statusEl = document.getElementById(`${uploadId}-status`);
    const containerEl = document.getElementById(uploadId);

    if (statusEl) {
        statusEl.textContent = message;
        statusEl.style.color = isError ? 'var(--danger)' : 'var(--success)';
    }

    if (containerEl && isError) {
        containerEl.style.background = 'rgba(239, 68, 68, 0.2)';
    }
}

/**
 * Cancel movie upload
 */
function cancelMovieUpload(uploadId) {
    const tracker = movieUploadControllers.get(uploadId);
    if (tracker) {
        // If it's an XHR (single upload), abort it
        if (tracker.abort && typeof tracker.abort === 'function') {
            tracker.abort();
        }
        // If it's a chunked upload tracker, mark as cancelled
        if (typeof tracker === 'object' && 'cancelled' in tracker) {
            tracker.cancelled = true;
        }
    }
    movieUploadControllers.delete(uploadId);
    updateMovieUploadStatus(uploadId, '⛔ Đã hủy', true);
}

/**
 * Generate UUID for chunked upload
 */
function generateMovieUploadUUID() {
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
        const r = Math.random() * 16 | 0;
        const v = c === 'x' ? r : (r & 0x3 | 0x8);
        return v.toString(16);
    });
}

/**
 * Show select videos modal - allows picking existing videos to add to movie
 */
let selectVideosMovieId = null;

async function showSelectVideosModal(movieId) {
    selectVideosMovieId = movieId;

    // Create modal if not exists
    let modal = document.getElementById('select-videos-modal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'select-videos-modal';
        modal.className = 'modal';
        modal.innerHTML = `
            <div class="modal-content" style="max-width: 700px;">
                <span class="modal-close" onclick="closeSelectVideosModal()">&times;</span>
                <h2>📹 Chọn video để thêm vào Movie</h2>
                <p style="color: var(--text-muted); margin-bottom: 20px;">Chỉ hiển thị video chưa thuộc movie nào</p>
                <div id="select-videos-list" style="max-height: 400px; overflow-y: auto;">
                    <div class="loading"><div class="spinner"></div></div>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
    }

    modal.classList.add('active');

    // Load videos not in any movie
    try {
        const response = await fetch('/api/videos/list.php');
        const data = await response.json();

        if (data.success) {
            const unassignedVideos = data.videos.filter(v => !v.movie_id && v.status === 'completed');

            const listEl = document.getElementById('select-videos-list');
            if (unassignedVideos.length === 0) {
                listEl.innerHTML = '<p style="text-align: center; color: var(--text-muted);">Không có video nào chưa được gán vào movie</p>';
            } else {
                listEl.innerHTML = unassignedVideos.map(video => {
                    const autoEp = parseEpisodeNumber(video.original_filename);
                    return `
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px; background: rgba(255,255,255,0.05); border-radius: 8px; margin-bottom: 8px;">
                            <div style="flex: 1;">
                                <div style="font-weight: 600;">${escapeHtml(video.original_filename)}</div>
                                <div style="font-size: 0.85em; color: var(--text-muted);">
                                    ${video.duration_formatted || ''} • ${video.file_size_formatted || ''}
                                    ${autoEp ? ` • <span style="color: var(--success);">Auto: Tập ${autoEp}</span>` : ''}
                                </div>
                            </div>
                            <button class="btn-small btn-view" onclick="addVideoToMovieFromList('${video.id}', ${autoEp || 0})">
                                + Thêm ${autoEp ? 'Tập ' + autoEp : ''}
                            </button>
                        </div>
                    `;
                }).join('');
            }
        }
    } catch (error) {
        document.getElementById('select-videos-list').innerHTML = '<p style="color: var(--danger);">Error loading videos</p>';
    }
}

function closeSelectVideosModal() {
    const modal = document.getElementById('select-videos-modal');
    if (modal) modal.classList.remove('active');
}

/**
 * Add video to movie from the selection list
 */
async function addVideoToMovieFromList(videoId, episodeNumber) {
    if (!selectVideosMovieId) return;

    try {
        const response = await fetch('/api/movies/add-video.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                movie_id: selectVideosMovieId,
                video_id: videoId,
                episode_number: episodeNumber
            })
        });
        const data = await response.json();

        if (data.success) {
            closeSelectVideosModal();
            showMovieDetail(selectVideosMovieId);
        } else {
            alert('Error: ' + data.error);
        }
    } catch (error) {
        alert('Failed to add: ' + error.message);
    }
}

/**
 * Copy to clipboard helper
 */
async function copyToClipboard(text) {
    try {
        await navigator.clipboard.writeText(text);
        // Show toast instead of alert
        const toast = document.createElement('div');
        toast.style.cssText = 'position: fixed; bottom: 20px; right: 20px; background: var(--success); color: white; padding: 12px 24px; border-radius: 8px; z-index: 9999;';
        toast.textContent = 'Copied!';
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 2000);
    } catch (error) {
        prompt('Copy this:', text);
    }
}

// ================================
// Episode Metadata Edit Functions
// ================================

let editingEpisodeId = null;
let editingMovieId = null;

/**
 * Show edit episode metadata modal
 */
function showEditEpisodeModal(videoId, movieId, episodeData) {
    editingEpisodeId = videoId;
    editingMovieId = movieId;

    // Parse episode data if it's a string
    if (typeof episodeData === 'string') {
        try {
            episodeData = JSON.parse(episodeData.replace(/&quot;/g, '"'));
        } catch (e) {
            episodeData = {};
        }
    }

    // Create modal if not exists
    let modal = document.getElementById('edit-episode-modal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'edit-episode-modal';
        modal.className = 'modal';
        modal.innerHTML = `
            <div class="modal-content" style="max-width: 550px;">
                <span class="modal-close" onclick="closeEditEpisodeModal()">&times;</span>
                <h2>⚙ Chỉnh sửa Metadata</h2>
                <form onsubmit="saveEpisodeMetadata(event)" style="margin-top: 20px;">
                    <div id="edit-episode-form-content"></div>
                    <button type="submit" class="btn-primary" style="width: 100%; margin-top: 20px;">💾 Lưu thay đổi</button>
                </form>
            </div>
        `;
        document.body.appendChild(modal);
    }

    // Fill form
    document.getElementById('edit-episode-form-content').innerHTML = `
        <div style="font-size: 0.9em; color: var(--text-muted); margin-bottom: 20px;">
            Video: ${escapeHtml(episodeData.original_filename || '')}
        </div>
        
        <div class="form-group">
            <label>Số tập</label>
            <input type="number" id="edit-episode-number" value="${episodeData.episode_number || ''}" placeholder="Ví dụ: 1">
        </div>
        
        <div class="form-group">
            <label>Tên tập (tùy chọn)</label>
            <input type="text" id="edit-episode-title" value="${escapeHtml(episodeData.episode_title || '')}" placeholder="Ví dụ: Khởi đầu mới">
        </div>
        
        <div class="form-group">
            <label>🔤 Quản lý Phụ đề</label>
            
            <!-- Subtitle List -->
            <div id="subtitle-list" style="margin-bottom: 15px;">
                <div class="loading"><div class="spinner"></div></div>
            </div>
            
            <!-- Add New Subtitle -->
            <div style="background: rgba(255,255,255,0.05); padding: 12px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.1);">
                <div style="font-weight: 600; margin-bottom: 10px; font-size: 0.9em; color: var(--accent-blue);">➕ Thêm phụ đề mới</div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 8px;">
                    <select id="subtitle-lang-select" class="form-control" style="padding: 8px;" onchange="document.getElementById('subtitle-label-input').value = this.options[this.selectedIndex].text">
                        <option value="vi">Tiếng Việt</option>
                        <option value="en">English</option>
                        <option value="ja">Japanese</option>
                        <option value="ko">Korean</option>
                        <option value="zh">Chinese</option>
                        <option value="th">Thai</option>
                        <option value="other">Other</option>
                    </select>
                    <input type="text" id="subtitle-label-input" class="form-control" placeholder="Nhãn (ví dụ: Tiếng Việt)" value="Tiếng Việt">
                </div>
                
                <div style="display: flex; gap: 8px;">
                    <input type="file" id="subtitle-file-input" accept=".vtt,.srt,.ass,.ssa" class="form-control" style="flex: 1; font-size: 0.85em;">
                    <button type="button" id="btn-upload-subtitle" class="btn-primary" style="white-space: nowrap;" onclick="uploadSubtitle()">
                        📤 Upload
                    </button>
                </div>
                <div style="font-size: 0.8em; color: var(--text-muted); margin-top: 6px;">
                    Hỗ trợ .vtt, .srt, .ass (tự động convert sang VTT)
                </div>
            </div>
        </div>
        
        <div class="form-group">
            <label>🔊 Quản lý Audio Tracks</label>
            
            <!-- Audio List -->
            <div id="audio-track-list" style="margin-bottom: 15px;">
                <div class="loading"><div class="spinner"></div></div>
            </div>
            
            <!-- Add New Audio -->
            <div style="background: rgba(255,255,255,0.05); padding: 12px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.1);">
                <div style="font-weight: 600; margin-bottom: 10px; font-size: 0.9em; color: var(--accent-blue);">➕ Thêm Audio Track mới</div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 8px;">
                    <select id="audio-lang-select" class="form-control" style="padding: 8px;" onchange="document.getElementById('audio-label-input').value = this.options[this.selectedIndex].text">
                        <option value="vi">Tiếng Việt</option>
                        <option value="en">English</option>
                        <option value="ja">Japanese</option>
                        <option value="ko">Korean</option>
                        <option value="zh">Chinese</option>
                        <option value="th">Thai</option>
                        <option value="other">Other</option>
                    </select>
                    <input type="text" id="audio-label-input" class="form-control" placeholder="Nhãn (ví dụ: Tiếng Việt)" value="Tiếng Việt">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 8px;">
                    <select id="audio-type-select" class="form-control" style="padding: 8px;">
                        <option value="stereo">🎧 Stereo (2ch)</option>
                        <option value="surround">🎬 Surround 5.1 (6ch)</option>
                    </select>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" id="audio-is-default" style="width: auto;">
                        <label for="audio-is-default" style="margin: 0; font-size: 0.9em; cursor: pointer;">Đặt làm mặc định</label>
                    </div>
                </div>
                
                <div style="display: flex; gap: 8px;">
                    <input type="file" id="audio-file-input" accept=".mp3,.aac,.m4a,.wav,.flac,.ogg,.opus" class="form-control" style="flex: 1; font-size: 0.85em;">
                    <button type="button" id="btn-upload-audio" class="btn-primary" style="white-space: nowrap;" onclick="uploadAudioTrack()">
                        📤 Upload
                    </button>
                </div>
                <div style="font-size: 0.8em; color: var(--text-muted); margin-top: 6px;">
                    Hỗ trợ .mp3, .aac, .m4a, .wav (tự động convert sang AAC HLS)
                </div>
            </div>
        </div>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
            <div class="form-group">
                <label>⏭ Intro bắt đầu</label>
                <input type="text" id="edit-intro-start" value="${secondsToTimeStr(episodeData.intro_start)}" placeholder="0:00 hoặc 0">
            </div>
            <div class="form-group">
                <label>⏭ Intro kết thúc</label>
                <input type="text" id="edit-intro-end" value="${secondsToTimeStr(episodeData.intro_end)}" placeholder="1:30 hoặc 90">
            </div>
        </div>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
            <div class="form-group">
                <label>🔚 Outro bắt đầu</label>
                <input type="text" id="edit-outro-start" value="${secondsToTimeStr(episodeData.outro_start)}" placeholder="21:00">
            </div>
            <div class="form-group">
                <label>🔚 Outro kết thúc</label>
                <input type="text" id="edit-outro-end" value="${secondsToTimeStr(episodeData.outro_end)}" placeholder="23:30">
            </div>
        </div>
        
        <div style="font-size: 0.85em; color: var(--text-muted); background: rgba(255,255,255,0.05); padding: 12px; border-radius: 8px;">
            💡 <strong>Hỗ trợ format:</strong> mm:ss (ví dụ: 1:30 = 90 giây) hoặc nhập trực tiếp số giây.
            <br>Intro thường từ 0:00 - 1:30. Outro thường bắt đầu vài phút cuối.
        </div>
    `;

    modal.classList.add('active');

    // Load subtitles
    loadEpisodeSubtitles(editingEpisodeId);
    // Load audio tracks
    loadEpisodeAudioTracks(editingEpisodeId);
}

function closeEditEpisodeModal() {
    const modal = document.getElementById('edit-episode-modal');
    if (modal) modal.classList.remove('active');
}

/**
 * Convert seconds to time string (mm:ss)
 */
function secondsToTimeStr(seconds) {
    if (seconds === null || seconds === undefined || seconds === '') return '';
    const s = parseFloat(seconds);
    if (isNaN(s)) return '';
    const mins = Math.floor(s / 60);
    const secs = Math.floor(s % 60);
    return `${mins}:${secs.toString().padStart(2, '0')}`;
}

/**
 * Convert time string (mm:ss or just seconds) to seconds
 */
function timeStrToSeconds(str) {
    if (!str || str.trim() === '') return null;
    str = str.trim();

    // If contains colon, parse as mm:ss
    if (str.includes(':')) {
        const parts = str.split(':');
        const mins = parseInt(parts[0], 10) || 0;
        const secs = parseInt(parts[1], 10) || 0;
        return mins * 60 + secs;
    }

    // Otherwise treat as seconds
    const num = parseFloat(str);
    return isNaN(num) ? null : num;
}

/**
 * Save episode metadata
 */
async function saveEpisodeMetadata(event) {
    event.preventDefault();

    if (!editingEpisodeId) return;

    const data = {
        video_id: editingEpisodeId,
        episode_number: document.getElementById('edit-episode-number').value || null,
        episode_title: document.getElementById('edit-episode-title').value || null,
        subtitle_url: document.getElementById('edit-subtitle-url').value || null,
        intro_start: timeStrToSeconds(document.getElementById('edit-intro-start').value),
        intro_end: timeStrToSeconds(document.getElementById('edit-intro-end').value),
        outro_start: timeStrToSeconds(document.getElementById('edit-outro-start').value),
        outro_end: timeStrToSeconds(document.getElementById('edit-outro-end').value)
    };

    console.log('Saving metadata:', data);

    try {
        const response = await fetch('/api/videos/update-metadata.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        const result = await response.json();

        if (result.success) {
            closeEditEpisodeModal();
            // Refresh movie detail
            if (editingMovieId) {
                showMovieDetail(editingMovieId);
            }
            // Show success toast
            const toast = document.createElement('div');
            toast.style.cssText = 'position: fixed; bottom: 20px; right: 20px; background: var(--success); color: white; padding: 12px 24px; border-radius: 8px; z-index: 9999;';
            toast.textContent = 'Đã lưu metadata!';
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), 2000);
        } else {
            alert('Error: ' + result.error);
        }
    } catch (error) {
        alert('Failed to save: ' + error.message);
    }
}

// ================================
// Subtitle Management Functions
// ================================

let currentSubtitles = [];

/**
 * Load subtitles for an episode
 */
async function loadEpisodeSubtitles(videoId) {
    const listEl = document.getElementById('subtitle-list');
    if (!listEl) return;

    listEl.innerHTML = '<div class="loading"><div class="spinner"></div></div>';

    try {
        const response = await fetch(`/api/subtitles/list.php?video_id=${videoId}`);
        const data = await response.json();

        if (data.success) {
            currentSubtitles = data.subtitles;
            renderEpisodeSubtitles();
        } else {
            listEl.innerHTML = `<div class="error">Error: ${data.error}</div>`;
        }
    } catch (error) {
        console.error('Failed to load subtitles:', error);
        listEl.innerHTML = `<div class="error">Failed to load subtitles</div>`;
    }
}

/**
 * Render subtitle list in modal
 */
function renderEpisodeSubtitles() {
    const listEl = document.getElementById('subtitle-list');
    if (!listEl) return;

    if (currentSubtitles.length === 0) {
        listEl.innerHTML = '<div class="empty-state" style="padding: 15px; text-align: center; font-style: italic; color: var(--text-muted); background: rgba(255,255,255,0.02); border-radius: 8px;">Chưa có phụ đề nào</div>';
        return;
    }

    listEl.innerHTML = currentSubtitles.map(sub => `
        <div class="subtitle-item" style="display: flex; align-items: center; justify-content: space-between; padding: 10px; background: rgba(255,255,255,0.05); margin-bottom: 8px; border-radius: 6px;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <span class="badge" style="background: var(--accent-blue); padding: 4px 8px; border-radius: 4px; font-weight: bold; font-size: 0.8em;">${sub.language.toUpperCase()}</span>
                <div style="display: flex; flex-direction: column;">
                    <span style="font-weight: 500;">${escapeHtml(sub.label)}</span>
                    <a href="${sub.url}" target="_blank" style="font-size: 0.8em; color: var(--text-muted); text-decoration: none;">${escapeHtml(sub.file_path)}</a>
                </div>
            </div>
            <button type="button" class="btn-icon btn-danger" onclick="deleteSubtitle(${sub.id})" title="Xóa phụ đề">🗑️</button>
        </div>
    `).join('');
}

/**
 * Trigger subtitle upload
 */
async function uploadSubtitle() {
    const fileInput = document.getElementById('subtitle-file-input');
    const langInput = document.getElementById('subtitle-lang-select');
    const labelInput = document.getElementById('subtitle-label-input');

    const file = fileInput.files[0];
    const language = langInput.value;
    const label = labelInput.value.trim();

    if (!file) {
        alert('Vui lòng chọn file (.vtt, .srt, .ass)');
        return;
    }
    if (!label) {
        alert('Vui lòng nhập nhãn hiển thị (ví dụ: Tiếng Việt)');
        return;
    }

    // Show loading state
    const btn = document.getElementById('btn-upload-subtitle');
    const originalText = btn.innerHTML;
    btn.innerHTML = '⏳ Uploading...';
    btn.disabled = true;

    const formData = new FormData();
    formData.append('file', file);
    formData.append('video_id', editingEpisodeId);
    formData.append('language', language);
    formData.append('label', label);

    try {
        const response = await fetch('/api/subtitles/upload.php', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();

        if (data.success) {
            // Reset form
            fileInput.value = '';
            labelInput.value = '';
            // Reload list
            await loadEpisodeSubtitles(editingEpisodeId);
            // Show toast
            const toast = document.createElement('div');
            toast.style.cssText = 'position: fixed; bottom: 20px; right: 20px; background: var(--success); color: white; padding: 12px 24px; border-radius: 8px; z-index: 9999;';
            toast.textContent = 'Upload phụ đề thành công!';
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), 2000);
        } else {
            alert('Upload thất bại: ' + data.error);
        }
    } catch (error) {
        console.error('Upload error:', error);
        alert('Lỗi upload: ' + error.message);
    } finally {
        btn.innerHTML = originalText;
        btn.disabled = false;
    }
}

/**
 * Delete subtitle
 */
async function deleteSubtitle(subtitleId) {
    if (!confirm('Bạn có chắc muốn xóa phụ đề này?')) return;

    try {
        const response = await fetch('/api/subtitles/delete.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ subtitle_id: subtitleId })
        });
        const data = await response.json();

        if (data.success) {
            await loadEpisodeSubtitles(editingEpisodeId);
        } else {
            alert('Lỗi: ' + data.error);
        }
    } catch (error) {
        alert('Xóa thất bại: ' + error.message);
    }
}

// ================================
// Audio Track Management Functions
// ================================

let currentAudioTracks = [];

/**
 * Load audio tracks for an episode
 */
async function loadEpisodeAudioTracks(videoId) {
    const listEl = document.getElementById('audio-track-list');
    if (!listEl) return;

    listEl.innerHTML = '<div class="loading"><div class="spinner"></div></div>';

    try {
        const response = await fetch(`/api/audio-tracks/list.php?video_id=${videoId}`);
        const data = await response.json();

        if (data.success) {
            currentAudioTracks = data.audio_tracks;
            renderEpisodeAudioTracks();
        } else {
            listEl.innerHTML = `<div class="error">Error: ${data.error}</div>`;
        }
    } catch (error) {
        console.error('Failed to load audio tracks:', error);
        listEl.innerHTML = `<div class="error">Failed to load audio tracks</div>`;
    }
}

/**
 * Render audio track list
 */
function renderEpisodeAudioTracks() {
    const listEl = document.getElementById('audio-track-list');
    if (!listEl) return;

    if (currentAudioTracks.length === 0) {
        listEl.innerHTML = '<div class="empty-state" style="padding: 15px; text-align: center; font-style: italic; color: var(--text-muted); background: rgba(255,255,255,0.02); border-radius: 8px;">Chưa có audio track nào</div>';
        return;
    }

    listEl.innerHTML = currentAudioTracks.map(track => `
        <div class="subtitle-item" style="display: flex; align-items: center; justify-content: space-between; padding: 10px; background: rgba(255,255,255,0.05); margin-bottom: 8px; border-radius: 6px;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <span class="badge" style="background: var(--accent-purple); padding: 4px 8px; border-radius: 4px; font-weight: bold; font-size: 0.8em;">${track.language.toUpperCase()}</span>
                <div style="display: flex; flex-direction: column;">
                    <span style="font-weight: 500;">
                        ${escapeHtml(track.label)}
                        ${track.is_default == 1 ? '<span style="color: var(--warning); font-size: 0.8em; margin-left: 4px;">(Default)</span>' : ''}
                    </span>
                    <span style="font-size: 0.8em; color: var(--text-muted);">
                        ${track.channels} channels • ${track.codec}
                    </span>
                </div>
            </div>
            <button type="button" class="btn-icon btn-danger" onclick="deleteAudioTrack(${track.id})" title="Xóa audio track">🗑️</button>
        </div>
    `).join('');
}

/**
 * Upload audio track
 */
async function uploadAudioTrack() {
    const fileInput = document.getElementById('audio-file-input');
    const langInput = document.getElementById('audio-lang-select');
    const labelInput = document.getElementById('audio-label-input');
    const defaultInput = document.getElementById('audio-is-default');

    const file = fileInput.files[0];
    const language = langInput.value;
    const label = labelInput.value.trim();
    const isDefault = defaultInput.checked;

    if (!file) {
        alert('Vui lòng chọn file audio');
        return;
    }
    if (!label) {
        alert('Vui lòng nhập nhãn hiển thị');
        return;
    }

    // Show loading state
    const btn = document.getElementById('btn-upload-audio');
    const originalText = btn.innerHTML;
    btn.innerHTML = '⏳ Uploading...';
    btn.disabled = true;

    const formData = new FormData();
    formData.append('file', file);
    formData.append('video_id', editingEpisodeId);
    formData.append('language', language);
    formData.append('label', label);
    formData.append('is_default', isDefault);
    formData.append('audio_type', document.getElementById('audio-type-select').value);

    try {
        const response = await fetch('/api/audio-tracks/upload.php', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();

        if (data.success) {
            fileInput.value = '';
            labelInput.value = '';
            defaultInput.checked = false;

            await loadEpisodeAudioTracks(editingEpisodeId);

            const toast = document.createElement('div');
            toast.style.cssText = 'position: fixed; bottom: 20px; right: 20px; background: var(--success); color: white; padding: 12px 24px; border-radius: 8px; z-index: 9999;';
            toast.textContent = 'Upload audio thành công!';
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), 2000);
        } else {
            alert('Upload thất bại: ' + data.error);
        }
    } catch (error) {
        console.error('Upload error:', error);
        alert('Lỗi upload: ' + error.message);
    } finally {
        btn.innerHTML = originalText;
        btn.disabled = false;
    }
}

/**
 * Delete audio track
 */
async function deleteAudioTrack(trackId) {
    if (!confirm('Bạn có chắc muốn xóa audio track này?')) return;

    try {
        const response = await fetch('/api/audio-tracks/delete.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: trackId })
        });
        const data = await response.json();

        if (data.success) {
            await loadEpisodeAudioTracks(editingEpisodeId);
        } else {
            alert('Lỗi: ' + data.error);
        }
    } catch (error) {
        alert('Xóa thất bại: ' + error.message);
    }
}


let currentUsers = [];

/**
 * Load users from API (admin only)
 */
async function loadUsers() {
    const tbody = document.getElementById('users-tbody');
    const loading = document.getElementById('users-loading');
    const empty = document.getElementById('users-empty');
    const table = document.getElementById('users-table');

    if (!tbody) return;

    loading.style.display = 'block';
    table.style.display = 'none';
    empty.style.display = 'none';

    try {
        const response = await fetch('/api/users/list.php');
        const data = await response.json();

        loading.style.display = 'none';

        if (data.success && data.users.length > 0) {
            currentUsers = data.users;
            table.style.display = 'table';
            renderUsers(data.users);
        } else if (data.success) {
            empty.style.display = 'block';
        } else {
            tbody.innerHTML = `<tr><td colspan="6" style="color: var(--danger); text-align: center;">${data.error || 'Failed to load users'}</td></tr>`;
            table.style.display = 'table';
        }
    } catch (error) {
        console.error('Failed to load users:', error);
        loading.style.display = 'none';
        tbody.innerHTML = '<tr><td colspan="6" style="color: var(--danger); text-align: center;">Failed to load users</td></tr>';
        table.style.display = 'table';
    }
}

/**
 * Render users in table
 */
function renderUsers(users) {
    const tbody = document.getElementById('users-tbody');

    tbody.innerHTML = users.map(user => {
        const roleClass = user.role === 'admin' ? 'role-admin' : 'role-user';
        const createdDate = user.created_at ? new Date(user.created_at).toLocaleDateString('vi-VN') : 'N/A';
        const lastLogin = user.last_login ? new Date(user.last_login).toLocaleString('vi-VN') : 'Never';

        return `
            <tr>
                <td><strong>${escapeHtml(user.username)}</strong></td>
                <td>${escapeHtml(user.email)}</td>
                <td><span class="role-badge ${roleClass}">${user.role}</span></td>
                <td>${createdDate}</td>
                <td>${lastLogin}</td>
                <td>
                    <div class="user-actions">
                        <button class="btn-icon btn-edit" onclick="showChangePasswordModal(${user.id}, '${escapeHtml(user.username)}')" title="Change Password">🔑</button>
                        <button class="btn-icon btn-danger" onclick="deleteUser(${user.id}, '${escapeHtml(user.username)}')" title="Delete User">🗑️</button>
                    </div>
                </td>
            </tr>
        `;
    }).join('');
}

/**
 * Show change password modal
 */
function showChangePasswordModal(userId, username) {
    // Create modal if not exists
    let modal = document.getElementById('change-password-modal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'change-password-modal';
        modal.className = 'modal';
        document.body.appendChild(modal);
    }

    modal.innerHTML = `
        <div class="modal-content" style="max-width: 450px;">
            <span class="modal-close" onclick="closeChangePasswordModal()">&times;</span>
            <h2>🔑 Đổi mật khẩu</h2>
            <p style="color: var(--text-muted); margin-bottom: 20px;">User: <strong>${username}</strong></p>
            
            <form onsubmit="changeUserPassword(event, ${userId})">
                <div class="form-group">
                    <label>Mật khẩu hiện tại *</label>
                    <input type="password" id="current-password" required placeholder="Nhập mật khẩu hiện tại">
                </div>
                <div class="form-group">
                    <label>Mật khẩu mới *</label>
                    <input type="password" id="new-password" required minlength="6" placeholder="Tối thiểu 6 ký tự">
                </div>
                <div class="form-group">
                    <label>Xác nhận mật khẩu *</label>
                    <input type="password" id="confirm-password" required placeholder="Nhập lại mật khẩu">
                </div>
                <button type="submit" class="btn-primary" style="width: 100%;">Đổi mật khẩu</button>
            </form>
        </div>
    `;

    modal.classList.add('active');
}

function closeChangePasswordModal() {
    const modal = document.getElementById('change-password-modal');
    if (modal) modal.classList.remove('active');
}

/**
 * Change user password
 */
async function changeUserPassword(event, userId) {
    event.preventDefault();

    const currentPassword = document.getElementById('current-password').value;
    const newPassword = document.getElementById('new-password').value;
    const confirmPassword = document.getElementById('confirm-password').value;

    if (newPassword !== confirmPassword) {
        alert('Mật khẩu xác nhận không khớp!');
        return;
    }

    if (newPassword.length < 6) {
        alert('Mật khẩu phải có ít nhất 6 ký tự!');
        return;
    }

    try {
        const response = await fetch('/api/users/change-password.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                user_id: userId,
                current_password: currentPassword,
                new_password: newPassword
            })
        });
        const data = await response.json();

        if (data.success) {
            closeChangePasswordModal();
            // Show success toast
            const toast = document.createElement('div');
            toast.style.cssText = 'position: fixed; bottom: 20px; right: 20px; background: var(--success); color: white; padding: 12px 24px; border-radius: 8px; z-index: 9999;';
            toast.textContent = 'Đổi mật khẩu thành công!';
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), 3000);
        } else {
            alert('Lỗi: ' + data.error);
        }
    } catch (error) {
        alert('Đổi mật khẩu thất bại: ' + error.message);
    }
}

/**
 * Delete user
 */
async function deleteUser(userId, username) {
    if (!confirm(`Bạn có chắc muốn xóa user "${username}"?\n\nTất cả video của user này cũng sẽ bị xóa!`)) {
        return;
    }

    try {
        const response = await fetch('/api/users/delete.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ user_id: userId })
        });
        const data = await response.json();

        if (data.success) {
            loadUsers(); // Refresh list
            // Show success toast
            const toast = document.createElement('div');
            toast.style.cssText = 'position: fixed; bottom: 20px; right: 20px; background: var(--success); color: white; padding: 12px 24px; border-radius: 8px; z-index: 9999;';
            toast.textContent = data.message || 'User deleted!';
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), 3000);
        } else {
            alert('Lỗi: ' + data.error);
        }
    } catch (error) {
        alert('Xóa user thất bại: ' + error.message);
    }
}

// ================================
// HLS M3U8 Upload Functions
// ================================

/**
 * Switch between video and HLS upload tabs
 */
function switchUploadTab(tab) {
    const videoContainer = document.getElementById('video-upload-container');
    const hlsContainer = document.getElementById('hls-upload-container');
    const tabVideo = document.getElementById('tab-video');
    const tabHls = document.getElementById('tab-hls');

    if (tab === 'video') {
        videoContainer.style.display = 'block';
        hlsContainer.style.display = 'none';
        tabVideo.style.opacity = '1';
        tabVideo.className = 'btn-primary';
        tabHls.style.opacity = '0.6';
        tabHls.className = 'btn-secondary';
    } else {
        videoContainer.style.display = 'none';
        hlsContainer.style.display = 'block';
        tabVideo.style.opacity = '0.6';
        tabVideo.className = 'btn-secondary';
        tabHls.style.opacity = '1';
        tabHls.className = 'btn-primary';
    }
}

/**
 * Handle HLS file select
 */
function handleHLSFileSelect(event) {
    const file = event.target.files[0];
    if (file) {
        uploadHLSFile(file);
    }
}

/**
 * Upload HLS m3u8 file
 */
async function uploadHLSFile(file) {
    const resultDiv = document.getElementById('hls-upload-result');
    const title = document.getElementById('hls-title').value.trim();

    resultDiv.style.display = 'block';
    resultDiv.style.background = 'rgba(99, 102, 241, 0.2)';
    resultDiv.innerHTML = '<p>⏳ Đang upload...</p>';

    const formData = new FormData();
    formData.append('m3u8', file);
    if (title) formData.append('title', title);

    try {
        const response = await fetch('/api/videos/upload-hls.php', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            resultDiv.style.background = 'rgba(34, 197, 94, 0.2)';
            resultDiv.innerHTML = `
                <h4 style="margin-bottom: 12px;">✅ Upload thành công!</h4>
                <div style="margin-bottom: 8px;">
                    <strong>Video ID:</strong> ${data.video_id}
                </div>
                <div style="margin-bottom: 8px;">
                    <strong>Embed URL:</strong><br>
                    <input type="text" value="${data.embed_url}" readonly 
                           style="width: 100%; margin-top: 4px; padding: 8px; border-radius: 4px; border: 1px solid var(--border); background: var(--bg-primary);"
                           onclick="this.select(); document.execCommand('copy');">
                </div>
                <div style="margin-bottom: 8px;">
                    <strong>M3U8 URL:</strong><br>
                    <input type="text" value="${data.m3u8_url}" readonly 
                           style="width: 100%; margin-top: 4px; padding: 8px; border-radius: 4px; border: 1px solid var(--border); background: var(--bg-primary);"
                           onclick="this.select(); document.execCommand('copy');">
                </div>
                <div style="margin-bottom: 12px;">
                    <strong>Embed Code:</strong><br>
                    <textarea readonly style="width: 100%; margin-top: 4px; padding: 8px; border-radius: 4px; border: 1px solid var(--border); background: var(--bg-primary); height: 60px;"
                              onclick="this.select(); document.execCommand('copy');">${escapeHtml(data.embed_code)}</textarea>
                </div>
                <button class="btn-primary" onclick="window.open('${data.embed_url}', '_blank')">
                    ▶️ Xem Video
                </button>
                <button class="btn-secondary" onclick="document.getElementById('hls-upload-result').style.display='none'; document.getElementById('hls-file-input').value=''; document.getElementById('hls-title').value='';">
                    Upload khác
                </button>
            `;

            // Refresh video list
            loadVideos(1);
        } else {
            resultDiv.style.background = 'rgba(239, 68, 68, 0.2)';
            resultDiv.innerHTML = `<p>❌ Lỗi: ${data.error}</p>`;
        }
    } catch (error) {
        resultDiv.style.background = 'rgba(239, 68, 68, 0.2)';
        resultDiv.innerHTML = `<p>❌ Lỗi: ${error.message}</p>`;
    }
}

