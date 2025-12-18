-- Movies Feature Schema Update
-- Run this SQL to add Movies/Series support

USE hls_streaming;

-- ============================================
-- Movies/Series Table
-- ============================================
CREATE TABLE IF NOT EXISTS movies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    slug VARCHAR(255) UNIQUE,
    description TEXT,
    poster_url VARCHAR(512),
    status ENUM('ongoing', 'completed') DEFAULT 'ongoing',
    total_episodes INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_slug (slug),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Add movie_id and episode fields to videos table
-- ============================================
ALTER TABLE videos 
ADD COLUMN movie_id INT NULL AFTER user_id,
ADD COLUMN episode_number INT NULL AFTER movie_id,
ADD COLUMN episode_title VARCHAR(255) NULL AFTER episode_number,
ADD CONSTRAINT fk_video_movie FOREIGN KEY (movie_id) REFERENCES movies(id) ON DELETE SET NULL,
ADD INDEX idx_movie_id (movie_id),
ADD INDEX idx_episode_number (episode_number);
