<?php
/**
 * Run Movies Migration
 * Execute this once to add movies table and update videos table
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = db();

echo "Running Movies Migration...\n";

try {
    // Create movies table
    $sql1 = "CREATE TABLE IF NOT EXISTS movies (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $db->execute($sql1);
    echo "✓ Created movies table\n";

    // Check if movie_id column exists
    $columns = $db->query("SHOW COLUMNS FROM videos LIKE 'movie_id'");
    if (empty($columns)) {
        // Add movie_id column
        $db->execute("ALTER TABLE videos ADD COLUMN movie_id INT NULL AFTER user_id");
        echo "✓ Added movie_id column to videos\n";

        // Add episode columns
        $db->execute("ALTER TABLE videos ADD COLUMN episode_number INT NULL AFTER movie_id");
        echo "✓ Added episode_number column to videos\n";

        $db->execute("ALTER TABLE videos ADD COLUMN episode_title VARCHAR(255) NULL AFTER episode_number");
        echo "✓ Added episode_title column to videos\n";

        // Add foreign key
        $db->execute("ALTER TABLE videos ADD CONSTRAINT fk_video_movie FOREIGN KEY (movie_id) REFERENCES movies(id) ON DELETE SET NULL");
        echo "✓ Added foreign key constraint\n";

        // Add indexes
        $db->execute("ALTER TABLE videos ADD INDEX idx_movie_id (movie_id)");
        $db->execute("ALTER TABLE videos ADD INDEX idx_episode_number (episode_number)");
        echo "✓ Added indexes\n";
    } else {
        echo "→ movie_id column already exists, skipping...\n";
    }

    echo "\n✅ Migration completed successfully!\n";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
