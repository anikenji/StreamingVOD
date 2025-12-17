/**
 * Video Upload Module
 * Handles file upload with chunked upload for large files
 */

// Initialize upload functionality
document.addEventListener('DOMContentLoaded', () => {
    initializeUpload();
});

function initializeUpload() {
    const uploadZone = document.getElementById('upload-zone');
    const fileInput = document.getElementById('file-input');

    if (!uploadZone || !fileInput) return;

    // Click to browse
    uploadZone.addEventListener('click', () => {
        fileInput.click();
    });

    // File input change
    fileInput.addEventListener('change', (e) => {
        if (e.target.files.length > 0) {
            handleFileSelect(e.target.files[0]);
        }
    });

    // Drag & drop
    uploadZone.addEventListener('dragover', (e) => {
        e.preventDefault();
        uploadZone.classList.add('drag-over');
    });

    uploadZone.addEventListener('dragleave', () => {
        uploadZone.classList.remove('drag-over');
    });

    uploadZone.addEventListener('drop', (e) => {
        e.preventDefault();
        uploadZone.classList.remove('drag-over');

        if (e.dataTransfer.files.length > 0) {
            handleFileSelect(e.dataTransfer.files[0]);
        }
    });
}

/**
 * Handle file selection
 */
async function handleFileSelect(file) {
    // Validate file
    const allowedExtensions = ['mp4', 'mkv', 'avi', 'mov', 'webm', 'flv'];
    const extension = file.name.split('.').pop().toLowerCase();

    if (!allowedExtensions.includes(extension)) {
        alert('Invalid file type. Allowed: ' + allowedExtensions.join(', '));
        return;
    }

    // Check file size (5GB max)
    const maxSize = 5 * 1024 * 1024 * 1024; // 5GB
    if (file.size > maxSize) {
        alert('File too large. Maximum size: 5GB');
        return;
    }

    // Show upload progress
    document.getElementById('upload-zone').style.display = 'none';
    document.getElementById('upload-progress').style.display = 'block';

    // Start upload
    try {
        if (file.size > 50 * 1024 * 1024) { // > 50MB, use chunked upload
            await uploadFileChunked(file);
        } else {
            await uploadFileSingle(file);
        }
    } catch (error) {
        console.error('Upload error:', error);
        alert('Upload failed: ' + error.message);
        resetUploadUI();
    }
}

/**
 * Upload file in chunks
 */
async function uploadFileChunked(file) {
    const chunkSize = 5 * 1024 * 1024; // 5MB chunks
    const totalChunks = Math.ceil(file.size / chunkSize);
    const videoId = generateUUID();

    for (let i = 0; i < totalChunks; i++) {
        const start = i * chunkSize;
        const end = Math.min(start + chunkSize, file.size);
        const chunk = file.slice(start, end);

        const formData = new FormData();
        formData.append('chunk', chunk);
        formData.append('video_id', videoId);
        formData.append('chunk_index', i);
        formData.append('total_chunks', totalChunks);
        formData.append('original_filename', file.name);

        const response = await fetch('/api/videos/upload.php', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (!data.success) {
            throw new Error(data.error || 'Upload failed');
        }

        // Update progress
        const progress = ((i + 1) / totalChunks) * 100;
        updateUploadProgress(progress);

        // Check if completed
        if (data.upload_complete) {
            onUploadComplete(videoId);
            return;
        }
    }
}

/**
 * Upload single file
 */
async function uploadFileSingle(file) {
    const formData = new FormData();
    formData.append('video', file);

    const xhr = new XMLHttpRequest();

    xhr.upload.addEventListener('progress', (e) => {
        if (e.lengthComputable) {
            const progress = (e.loaded / e.total) * 100;
            updateUploadProgress(progress);
        }
    });

    xhr.addEventListener('load', () => {
        if (xhr.status === 200) {
            const data = JSON.parse(xhr.responseText);
            if (data.success) {
                onUploadComplete(data.video_id);
            } else {
                throw new Error(data.error || 'Upload failed');
            }
        } else {
            throw new Error('Upload failed');
        }
    });

    xhr.addEventListener('error', () => {
        throw new Error('Network error');
    });

    xhr.open('POST', '/api/videos/upload.php');
    xhr.send(formData);
}

/**
 * Update upload progress bar
 */
function updateUploadProgress(percent) {
    const progressBar = document.getElementById('upload-progress-bar');
    const statusText = document.getElementById('upload-status');

    if (progressBar) {
        progressBar.style.width = `${percent}%`;
    }

    if (statusText) {
        statusText.textContent = `${Math.round(percent)}%`;
    }
}

/**
 * Handle upload completion
 */
function onUploadComplete(videoId) {
    alert('Upload successful! Your video is being processed.');

    resetUploadUI();

    // Show videos page
    showPage('videos');
}

/**
 * Reset upload UI
 */
function resetUploadUI() {
    document.getElementById('upload-zone').style.display = 'block';
    document.getElementById('upload-progress').style.display = 'none';
    document.getElementById('file-input').value = '';
    updateUploadProgress(0);
}

/**
 * Generate UUID for chunked upload
 */
function generateUUID() {
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
        const r = Math.random() * 16 | 0;
        const v = c === 'x' ? r : (r & 0x3 | 0x8);
        return v.toString(16);
    });
}
