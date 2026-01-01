<?php
/**
 * Run audio_tracks migration
 */

require_once __DIR__ . '/../config/database.php';

try {
    $db = Database::getInstance();

    $sql = "CREATE TABLE IF NOT EXISTS audio_tracks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        video_id VARCHAR(36) NOT NULL,
        language VARCHAR(10) NOT NULL,
        label VARCHAR(100) NOT NULL,
        channels INT DEFAULT 2,
        codec VARCHAR(20) DEFAULT 'aac',
        bitrate INT DEFAULT 192000,
        is_default BOOLEAN DEFAULT FALSE,
        file_path VARCHAR(512) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        
        FOREIGN KEY (video_id) REFERENCES videos(id) ON DELETE CASCADE,
        UNIQUE KEY unique_video_lang (video_id, language),
        INDEX idx_video_id (video_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $db->execute($sql, []);
    echo "audio_tracks table created successfully!\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
