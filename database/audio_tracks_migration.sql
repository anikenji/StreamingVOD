-- Audio Tracks Migration
-- Allows users to upload additional audio tracks (languages/dubs) for videos

CREATE TABLE IF NOT EXISTS audio_tracks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    video_id VARCHAR(36) NOT NULL,
    language VARCHAR(10) NOT NULL,           -- ISO code: 'vi', 'en', 'ja'
    label VARCHAR(100) NOT NULL,             -- Display name: 'Tiếng Việt', 'English'
    channels INT DEFAULT 2,                  -- 2=stereo, 6=5.1
    codec VARCHAR(20) DEFAULT 'aac',         -- 'aac', 'opus'
    bitrate INT DEFAULT 192000,              -- bits per second
    is_default BOOLEAN DEFAULT FALSE,
    file_path VARCHAR(512) NOT NULL,         -- HLS playlist path: audio/vi.m3u8
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (video_id) REFERENCES videos(id) ON DELETE CASCADE,
    UNIQUE KEY unique_video_lang (video_id, language),
    INDEX idx_video_id (video_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
