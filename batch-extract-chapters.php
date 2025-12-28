<?php
/**
 * Batch Chapter Extraction Script
 * Extracts OP/ED chapters from all existing videos that have empty metadata
 * Run once: php batch-extract-chapters.php
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/ChapterExtractor.php';

if (php_sapi_name() !== 'cli') {
    die("Run from command line: php batch-extract-chapters.php\n");
}

echo "=== Batch Chapter Extraction ===\n\n";

$db = db();

// Get all videos with empty OP/ED fields and existing original files
$sql = "SELECT id, original_path, original_filename FROM videos 
        WHERE (intro_start IS NULL OR intro_end IS NULL OR outro_start IS NULL OR outro_end IS NULL)
        ORDER BY created_at DESC";

$videos = $db->query($sql, []);

if (!$videos || empty($videos)) {
    echo "No videos found with empty OP/ED metadata.\n";
    exit(0);
}

echo "Found " . count($videos) . " videos to process.\n\n";

$success = 0;
$skipped = 0;
$failed = 0;

foreach ($videos as $video) {
    $videoId = $video['id'];
    $originalPath = $video['original_path'];
    $filename = $video['original_filename'];

    echo "[{$videoId}] {$filename}\n";

    // Check if original file still exists
    if (!file_exists($originalPath)) {
        echo "   ⏭️ Skipped - Original file deleted\n";
        $skipped++;
        continue;
    }

    try {
        $extractor = new ChapterExtractor($originalPath);
        $chapters = $extractor->extractChapters();

        if (!$chapters) {
            echo "   ⏭️ Skipped - No chapters in file\n";
            $skipped++;
            continue;
        }

        $detected = $extractor->detectAll();

        if (!$detected['intro'] && !$detected['outro']) {
            echo "   ⏭️ Skipped - No OP/ED chapters detected\n";
            $skipped++;
            continue;
        }

        // Update database
        $result = ChapterExtractor::extractAndUpdateDatabase($videoId, $originalPath, $db);

        if ($result) {
            echo "   ✅ Success";
            if ($detected['intro']) {
                printf(" | OP: %.1fs-%.1fs", $detected['intro']['start'], $detected['intro']['end']);
            }
            if ($detected['outro']) {
                printf(" | ED: %.1fs-%.1fs", $detected['outro']['start'], $detected['outro']['end']);
            }
            echo "\n";
            $success++;
        } else {
            echo "   ⏭️ Skipped - Fields already filled\n";
            $skipped++;
        }
    } catch (Exception $e) {
        echo "   ❌ Error: " . $e->getMessage() . "\n";
        $failed++;
    }
}

echo "\n=== Summary ===\n";
echo "Total:   " . count($videos) . "\n";
echo "Success: {$success}\n";
echo "Skipped: {$skipped}\n";
echo "Failed:  {$failed}\n";
