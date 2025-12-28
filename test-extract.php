<?php
// Quick test script to manually extract chapters for a video
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/ChapterExtractor.php';

$videoId = 'ccacb7ad-b7d6-4074-93f6-f66326e9ad65';
$videoPath = 'E:/movie/uploads/ccacb7ad-b7d6-4074-93f6-f66326e9ad65/original.mkv';

$db = db();
$result = ChapterExtractor::extractAndUpdateDatabase($videoId, $videoPath, $db);

echo "Result: " . ($result ? "SUCCESS - Chapters extracted and saved!" : "Failed or no empty fields") . "\n";

// Also show detected chapters
$extractor = new ChapterExtractor($videoPath);
$detected = $extractor->detectAll();
print_r($detected);
