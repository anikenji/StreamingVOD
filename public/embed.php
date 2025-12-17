<?php
/**
 * JWPlayer Embed Page
 * /public/embed.php?id=xxx
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$videoId = $_GET['id'] ?? '';

if (empty($videoId)) {
    die('Video ID required');
}

$db = db();

// Get video info
$sql = "SELECT * FROM videos WHERE id = ?";
$video = $db->queryOne($sql, [$videoId]);

if (!$video) {
    die('Video not found');
}

if ($video['status'] !== 'completed') {
    die('Video is still processing. Please check back later.');
}

$masterPlaylistUrl = HLS_BASE_URL . '/' . $videoId . '/video.m3u8';
$title = htmlspecialchars($video['original_filename']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $title ?> - HLS Streaming</title>
    <script src="<?= JWPLAYER_CDN ?>"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: #000;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        
        #player-container {
            width: 100%;
            max-width: 100%;
            margin: 0 auto;
        }
        
        #jwplayer {
            width: 100% !important;
            height: auto !important;
        }
        
        .info {
            max-width: 1200px;
            margin: 20px auto;
            padding: 20px;
            color: #fff;
        }
        
        .info h2 {
            margin-bottom: 10px;
        }
        
        .info p {
            color: #999;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div id="player-container">
        <div id="jwplayer"></div>
    </div>
    
    <div class="info">
        <h2><?= $title ?></h2>
        <p>Duration: <?= formatDuration($video['duration']) ?> | Size: <?= formatBytes($video['file_size']) ?></p>
    </div>
    
    <script>
        jwplayer("jwplayer").setup({
            playlist: [{
                file: "<?= $masterPlaylistUrl ?>",
                title: "<?= addslashes($title) ?>",
                image: "<?= $video['thumbnail_path'] ? THUMBNAIL_BASE_URL . '/' . $videoId . '.jpg' : '' ?>"
            }],
            width: "100%",
            aspectratio: "16:9",
            autostart: false,
            controls: true,
            displaytitle: true,
            displaydescription: false,
            primary: "html5",
            hlshtml: true,
            preload: "metadata",
            skin: {
                name: "seven"
            }
        });
        
        // Analytics (optional)
        jwplayer().on('play', function() {
            console.log('Video started playing');
        });
        
        jwplayer().on('complete', function() {
            console.log('Video completed');
        });
    </script>
</body>
</html>
