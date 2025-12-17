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
    } else if (pageName === 'upload') {
        document.getElementById('upload-page').classList.add('active');
        document.getElementById('page-title').textContent = 'Upload Video';
    }
}

function showUploadPage() {
    showPage('upload');
}

/**
 * Load videos from API
 */
async function loadVideos() {
    const grid = document.getElementById('videos-grid');
    const loading = document.getElementById('videos-loading');
    const empty = document.getElementById('videos-empty');
    
    loading.style.display = 'block';
    grid.innerHTML = '';
    empty.style.display = 'none';
    
    try {
        const response = await fetch('/api/videos/list.php');
        const data = await response.json();
        
        if (data.success) {
            currentVideos = data.videos;
            
            if (currentVideos.length === 0) {
                loading.style.display = 'none';
                empty.style.display = 'block';
            } else {
                loading.style.display = 'none';
                renderVideos(currentVideos);
                
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
                    <button class="btn-small btn-view" onclick="event.stopPropagation(); copyEmbedLink('${video.id}')">🔗 Link</button>
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
                <span class="status-badge status-${job.status}">${job.status}</span>
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
        <h2>${escapeHtml(video.original_filename)}</h2>
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
