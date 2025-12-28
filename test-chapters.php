<?php
/**
 * Test script for Chapter Extraction
 * Run this from CLI to debug: php test-chapters.php "path/to/video.mkv"
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/ChapterExtractor.php';

if (php_sapi_name() !== 'cli') {
    die("Run from command line: php test-chapters.php \"path/to/video.mkv\"\n");
}

$videoPath = $argv[1] ?? null;

if (!$videoPath) {
    // If no path provided, get the most recent video from database
    $db = db();
    $video = $db->queryOne("SELECT id, original_path FROM videos ORDER BY created_at DESC LIMIT 1", []);

    if ($video) {
        $videoPath = $video['original_path'];
        echo "Using most recent video: {$videoPath}\n";
        echo "Video ID: {$video['id']}\n\n";
    } else {
        die("Usage: php test-chapters.php \"path/to/video.mkv\"\n");
    }
}

echo "=== Chapter Extraction Debug ===\n\n";

// Check if file exists
echo "1. File exists: ";
if (file_exists($videoPath)) {
    echo "YES\n";
} else {
    echo "NO - File not found: {$videoPath}\n";
    exit(1);
}

// Check ffprobe path
echo "\n2. FFprobe path:\n";
if (defined('FFPROBE_PATH')) {
    echo "   FFPROBE_PATH = " . FFPROBE_PATH . "\n";
    $ffprobePath = FFPROBE_PATH;
} elseif (defined('FFMPEG_PATH')) {
    $ffprobePath = str_replace('ffmpeg', 'ffprobe', FFMPEG_PATH);
    echo "   Derived from FFMPEG_PATH = {$ffprobePath}\n";
} else {
    $ffprobePath = 'ffprobe';
    echo "   Using default: ffprobe\n";
}

// Check if ffprobe exists
echo "\n3. FFprobe exists: ";
$ffprobeWin = str_replace('/', '\\', $ffprobePath);
if (file_exists($ffprobeWin)) {
    echo "YES\n";
} else {
    echo "NO - {$ffprobeWin}\n";
}

// Run ffprobe command
echo "\n4. Running ffprobe...\n";
$videoPathWin = str_replace('/', '\\', $videoPath);
$cmd = sprintf('"%s" -v quiet -print_format json -show_chapters "%s"', $ffprobeWin, $videoPathWin);
echo "   Command: {$cmd}\n\n";

$output = shell_exec($cmd);

if (empty($output)) {
    echo "   ERROR: No output from ffprobe!\n";
    echo "   Trying with error output...\n";
    $cmd2 = sprintf('"%s" -print_format json -show_chapters "%s" 2>&1', $ffprobeWin, $videoPathWin);
    $output2 = shell_exec($cmd2);
    echo "   Output: " . substr($output2, 0, 500) . "\n";
    exit(1);
}

echo "5. Raw ffprobe output:\n";
echo $output . "\n\n";

$data = json_decode($output, true);

if (!isset($data['chapters']) || empty($data['chapters'])) {
    echo "6. Chapters: NONE FOUND\n";
    echo "   This video does not contain chapter markers.\n";
    exit(0);
}

echo "6. Chapters found: " . count($data['chapters']) . "\n\n";

foreach ($data['chapters'] as $i => $chapter) {
    $title = $chapter['tags']['title'] ?? '(no title)';
    $start = $chapter['start_time'] ?? 0;
    $end = $chapter['end_time'] ?? 0;
    printf("   [%d] %s: %.2fs - %.2fs\n", $i + 1, $title, $start, $end);
}

echo "\n7. Pattern matching:\n";
$extractor = new ChapterExtractor($videoPath);
$detected = $extractor->detectAll();

echo "   OP detected: " . ($detected['intro'] ? "YES ({$detected['intro']['title']})" : "NO") . "\n";
echo "   ED detected: " . ($detected['outro'] ? "YES ({$detected['outro']['title']})" : "NO") . "\n";

if ($detected['intro']) {
    printf("   OP: %.2fs - %.2fs\n", $detected['intro']['start'], $detected['intro']['end']);
}
if ($detected['outro']) {
    printf("   ED: %.2fs - %.2fs\n", $detected['outro']['start'], $detected['outro']['end']);
}

echo "\n=== Done ===\n";
