<?php
/**
 * Add episode metadata columns to videos table
 * Run this migration once
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = db();

echo "Running Episode Metadata Migration...\n";

try {
    // Check if columns exist
    $columns = $db->query("SHOW COLUMNS FROM videos LIKE 'subtitle_url'");

    if (empty($columns)) {
        // Add subtitle_url column
        $db->execute("ALTER TABLE videos ADD COLUMN subtitle_url VARCHAR(512) NULL AFTER episode_title");
        echo "✓ Added subtitle_url column\n";

        // Add intro timing columns
        $db->execute("ALTER TABLE videos ADD COLUMN intro_start DECIMAL(10,2) NULL AFTER subtitle_url");
        $db->execute("ALTER TABLE videos ADD COLUMN intro_end DECIMAL(10,2) NULL AFTER intro_start");
        echo "✓ Added intro_start, intro_end columns\n";

        // Add outro timing columns
        $db->execute("ALTER TABLE videos ADD COLUMN outro_start DECIMAL(10,2) NULL AFTER intro_end");
        $db->execute("ALTER TABLE videos ADD COLUMN outro_end DECIMAL(10,2) NULL AFTER outro_start");
        echo "✓ Added outro_start, outro_end columns\n";

    } else {
        echo "→ Columns already exist, skipping...\n";
    }

    echo "\n✅ Migration completed successfully!\n";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
