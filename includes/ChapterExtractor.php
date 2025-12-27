<?php
/**
 * Chapter Extractor
 * Extracts chapter markers from video files (especially MKV) using ffprobe
 * Automatically detects Opening (OP) and Ending (ED) timestamps
 */

require_once __DIR__ . '/../config/config.php';

class ChapterExtractor
{
    private $videoPath;
    private $chapters = [];

    // Patterns to match Opening chapters
    private static $opPatterns = [
        '/^op$/i',
        '/^opening$/i',
        '/opening/i',
        '/オープニング/u',
        '/^op\s*\d*/i',  // OP1, OP2, etc.
    ];

    // Patterns to match Ending chapters
    private static $edPatterns = [
        '/^ed$/i',
        '/^ending$/i',
        '/ending/i',
        '/エンディング/u',
        '/^ed\s*\d*/i',  // ED1, ED2, etc.
    ];

    public function __construct($videoPath)
    {
        $this->videoPath = $videoPath;
    }

    /**
     * Extract chapters from video file using ffprobe
     * @return array|null Array of chapters or null on failure
     */
    public function extractChapters()
    {
        // Derive ffprobe path from FFMPEG_PATH (replace ffmpeg with ffprobe)
        if (defined('FFPROBE_PATH')) {
            $ffprobePath = FFPROBE_PATH;
        } elseif (defined('FFMPEG_PATH')) {
            // ffprobe is usually in the same directory as ffmpeg
            $ffprobePath = str_replace('ffmpeg', 'ffprobe', FFMPEG_PATH);
        } else {
            $ffprobePath = 'ffprobe';
        }

        // Normalize path for Windows
        $ffprobePath = str_replace('/', '\\', $ffprobePath);
        $videoPath = str_replace('/', '\\', $this->videoPath);

        // Build ffprobe command for JSON chapter output
        $cmd = sprintf(
            '"%s" -v quiet -print_format json -show_chapters "%s"',
            $ffprobePath,
            $videoPath
        );

        $output = shell_exec($cmd);

        if (empty($output)) {
            logMessage("ChapterExtractor: No output from ffprobe for {$this->videoPath}", 'DEBUG');
            return null;
        }

        $data = json_decode($output, true);

        if (!isset($data['chapters']) || empty($data['chapters'])) {
            logMessage("ChapterExtractor: No chapters found in {$this->videoPath}", 'DEBUG');
            return null;
        }

        $this->chapters = $data['chapters'];
        logMessage("ChapterExtractor: Found " . count($this->chapters) . " chapters in video", 'INFO');

        return $this->chapters;
    }

    /**
     * Detect Opening (OP) chapter timestamps
     * @return array|null ['start' => float, 'end' => float] or null if not found
     */
    public function detectOpening()
    {
        if (empty($this->chapters)) {
            $this->extractChapters();
        }

        if (empty($this->chapters)) {
            return null;
        }

        foreach ($this->chapters as $chapter) {
            $title = $chapter['tags']['title'] ?? '';

            if ($this->matchesPatterns($title, self::$opPatterns)) {
                $start = floatval($chapter['start_time'] ?? 0);
                $end = floatval($chapter['end_time'] ?? 0);

                logMessage("ChapterExtractor: Detected OP at {$start}s - {$end}s (title: {$title})", 'INFO');

                return [
                    'start' => $start,
                    'end' => $end,
                    'title' => $title
                ];
            }
        }

        return null;
    }

    /**
     * Detect Ending (ED) chapter timestamps
     * @return array|null ['start' => float, 'end' => float] or null if not found
     */
    public function detectEnding()
    {
        if (empty($this->chapters)) {
            $this->extractChapters();
        }

        if (empty($this->chapters)) {
            return null;
        }

        foreach ($this->chapters as $chapter) {
            $title = $chapter['tags']['title'] ?? '';

            if ($this->matchesPatterns($title, self::$edPatterns)) {
                $start = floatval($chapter['start_time'] ?? 0);
                $end = floatval($chapter['end_time'] ?? 0);

                logMessage("ChapterExtractor: Detected ED at {$start}s - {$end}s (title: {$title})", 'INFO');

                return [
                    'start' => $start,
                    'end' => $end,
                    'title' => $title
                ];
            }
        }

        return null;
    }

    /**
     * Get all detected OP/ED timestamps
     * @return array ['intro' => [...], 'outro' => [...]]
     */
    public function detectAll()
    {
        return [
            'intro' => $this->detectOpening(),
            'outro' => $this->detectEnding()
        ];
    }

    /**
     * Check if title matches any of the given patterns
     */
    private function matchesPatterns($title, $patterns)
    {
        $title = trim($title);

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $title)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Static helper to extract and update database for a video
     * Only fills empty fields (does not override existing values)
     * 
     * @param string $videoId Video ID
     * @param string $videoPath Path to source video file
     * @param object $db Database connection
     * @return bool True if any fields were updated
     */
    public static function extractAndUpdateDatabase($videoId, $videoPath, $db)
    {
        // First, check existing values in database
        $video = $db->queryOne("SELECT intro_start, intro_end, outro_start, outro_end FROM videos WHERE id = ?", [$videoId]);

        if (!$video) {
            logMessage("ChapterExtractor: Video {$videoId} not found in database", 'ERROR');
            return false;
        }

        // Only proceed if at least one field is empty
        $hasEmptyIntro = ($video['intro_start'] === null || $video['intro_end'] === null);
        $hasEmptyOutro = ($video['outro_start'] === null || $video['outro_end'] === null);

        if (!$hasEmptyIntro && !$hasEmptyOutro) {
            logMessage("ChapterExtractor: All OP/ED fields already filled for video {$videoId}, skipping", 'DEBUG');
            return false;
        }

        // Extract chapters
        $extractor = new self($videoPath);
        $detected = $extractor->detectAll();

        $updates = [];
        $params = [];

        // Update intro (OP) if empty and detected
        if ($hasEmptyIntro && $detected['intro']) {
            if ($video['intro_start'] === null) {
                $updates[] = 'intro_start = ?';
                $params[] = $detected['intro']['start'];
            }
            if ($video['intro_end'] === null) {
                $updates[] = 'intro_end = ?';
                $params[] = $detected['intro']['end'];
            }
        }

        // Update outro (ED) if empty and detected
        if ($hasEmptyOutro && $detected['outro']) {
            if ($video['outro_start'] === null) {
                $updates[] = 'outro_start = ?';
                $params[] = $detected['outro']['start'];
            }
            if ($video['outro_end'] === null) {
                $updates[] = 'outro_end = ?';
                $params[] = $detected['outro']['end'];
            }
        }

        if (empty($updates)) {
            logMessage("ChapterExtractor: No chapters detected for video {$videoId}", 'DEBUG');
            return false;
        }

        // Execute update
        $params[] = $videoId;
        $sql = "UPDATE videos SET " . implode(', ', $updates) . " WHERE id = ?";

        try {
            $db->execute($sql, $params);
            logMessage("ChapterExtractor: Auto-filled OP/ED metadata for video {$videoId}", 'INFO');
            return true;
        } catch (Exception $e) {
            logMessage("ChapterExtractor: Failed to update database for video {$videoId}: " . $e->getMessage(), 'ERROR');
            return false;
        }
    }
}
