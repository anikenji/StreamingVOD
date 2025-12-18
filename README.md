# HLS Streaming Service

Professional video streaming platform with adaptive bitrate HLS encoding, built with PHP and MySQL.

## Features

- 🔐 **User Authentication** - Secure registration and login system
- ⬆️ **Video Upload** - Chunked upload support for large files (up to 5GB)
- 🎬 **Adaptive Bitrate Streaming** - Automatic encoding to 360p, 720p, and 1080p
- 📊 **Real-time Progress** - Live encoding progress tracking
- 🎯 **JWPlayer Integration** - Embeddable video player with HLS support
- 📱 **Responsive Dashboard** - Modern glassmorphism UI design
- 🚀 **Background Processing** - Asynchronous video encoding

## Requirements

- **WampServer** (or any LAMP/WAMP stack)
  - PHP 7.4 or higher
  - MySQL 5.7 or higher
  - Apache or Nginx
- **FFmpeg** - For video encoding
- **JWPlayer License** - For video playback

## Installation

### 1. Database Setup

Import the database schema:

```sql
mysql -u root -p < database/schema.sql
```

Or via phpMyAdmin:
- Open phpMyAdmin
- Create database `hls_streaming`
- Import `database/schema.sql`

Default admin credentials:
- **Username:** admin
- **Password:** admin123
- ⚠️ **Important:** Change password after first login!

### 2. Configuration

### 2. Configuration

1. Copy the example config file:
   ```bash
   cp config/config.example.php config/config.php
   ```
2. Edit `config/config.php` and update your settings:

```php
// Database
define('DB_HOST', 'localhost');
define('DB_NAME', 'hls_streaming');
define('DB_USER', 'root');
define('DB_PASS', 'your_password');

// FFmpeg paths (update to your installation)
define('FFMPEG_PATH', 'C:/ffmpeg/bin/ffmpeg.exe');
define('FFPROBE_PATH', 'C:/ffmpeg/bin/ffprobe.exe');

// JWPlayer
define('JWPLAYER_KEY', 'YOUR_LICENSE_KEY');
define('JWPLAYER_CDN', 'https://cdn.jwplayer.com/libraries/YOUR_KEY.js');

// Base URL
define('BASE_URL', 'http://localhost'); // Change for production
```

### 3. Storage Directories

The storage directories on E: drive should be created automatically. If not:

```powershell
mkdir E:\videos\uploads
mkdir E:\videos\hls
mkdir E:\videos\thumbnails
mkdir E:\videos\temp
```

Ensure PHP has write permissions to these directories.

### 4. Apache Configuration

Add to your Apache `httpd.conf` or create a virtual host:

```apache
<VirtualHost *:80>
    ServerName streaming.local
    DocumentRoot "E:/Code/HLS-Server/public"
    
    <Directory "E:/Code/HLS-Server/public">
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    
    Alias /videos "E:/videos"
    <Directory "E:/videos">
        Options -Indexes
        AllowOverride None
        Require all granted
        Header set Access-Control-Allow-Origin "*"
    </Directory>
</VirtualHost>
```

Add to `hosts` file (C:\Windows\System32\drivers\etc\hosts):
```
127.0.0.1 streaming.local
```

### 5. Start Background Worker

Run the video processing worker:

```batch
E:\Code\HLS-Server\workers\start-worker.bat
```

Or run manually:
```batch
php E:\Code\HLS-Server\workers\process-video.php
```

The worker will continuously process pending encoding jobs.

## Usage

### Access the Application

- **Main URL:** http://localhost (or http://streaming.local)
- **Dashboard:** http://localhost/dashboard.php
- **Embed Player:** http://localhost/embed/{video_id}

### Upload a Video

1. Login to dashboard
2. Click "Upload Video" button
3. Drag & drop video file or click to browse
4. Wait for upload to complete
5. Video will automatically start encoding

### Monitor Encoding Progress

- Progress is displayed in real-time on video cards
- Click on a video to see detailed encoding progress for each quality level
- Refresh automatically when encoding completes

### Embed Video

Once encoding is complete:
1. Click on the video
2. Click "Play Video" to open player
3. Click "Copy Link" to get embed URL
4. Share the embed link or use the iframe code

## Directory Structure

```
E:\Code\HLS-Server\
├── api\                    # API endpoints
│   ├── auth\              # Authentication APIs
│   ├── videos\            # Video management APIs
│   └── progress\          # Progress tracking
├── config\                # Configuration files
├── includes\              # Helper classes and functions
├── public\                # Web root
│   ├── css\              # Stylesheets
│   └── js\               # JavaScript
├── workers\               # Background workers
└── database\              # Database schema

E:\videos\                 # Media storage
├── uploads\              # Original uploads
├── hls\                  # HLS outputs
├── thumbnails\           # Video thumbnails
└── temp\                 # Temporary files
```

## API Documentation

### Authentication

- `POST /api/auth/register.php` - Register new user
- `POST /api/auth/login.php` - Login
- `POST /api/auth/logout.php` - Logout
- `GET /api/auth/check.php` - Check auth status

### Videos

- `POST /api/videos/upload.php` - Upload video
- `GET /api/videos/list.php` - List videos
- `GET /api/videos/detail.php?id={id}` - Video details
- `DELETE /api/videos/delete.php?id={id}` - Delete video

### Progress

- `GET /api/progress/poll.php?video_id={id}` - Poll encoding progress

## Troubleshooting

### FFmpeg not found
- Verify FFmpeg is installed: `ffmpeg -version`
- Update `FFMPEG_PATH` in `config/config.php`

### Upload fails
- Check PHP upload limits in `php.ini`:
  ```ini
  upload_max_filesize = 5G
  post_max_size = 5G
  max_execution_time = 7200
  max_input_time = 7200
  ```

### Videos not processing
- Ensure background worker is running
- Check `logs/app.log` for errors
- Verify write permissions on `E:\videos`

### Database connection error
- Verify MySQL is running
- Check database credentials in `config/config.php`
- Ensure database `hls_streaming` exists

## Performance Optimization

### Hardware Acceleration (Optional)

For faster encoding, enable GPU acceleration in FFmpeg:

**NVIDIA:**
```php
// In FFmpegEncoder.php, change:
'-c:v libx264' to '-c:v h264_nvenc'
```

**Intel QSV:**
```php
'-c:v h264_qsv'
```

### Concurrent Encoding

Adjust in `config/config.php`:
```php
define('MAX_CONCURRENT_ENCODES', 3); // Based on your CPU cores
```

## Security Recommendations

### Production Deployment

1. **Enable HTTPS** - Use SSL certificate
2. **Change default passwords** - Especially admin account
3. **Restrict file access** - Move uploads outside web root if possible
4. **Enable PHP security settings**:
   ```ini
   expose_php = Off
   display_errors = Off
   ```
5. **Use strong session security**
6. **Implement rate limiting**
7. **Regular backups** - Database and video files

## License

This project is for educational/commercial use. Ensure you have proper licenses for:
- JWPlayer (if using paid features)
- Any third-party libraries used

## Support

For issues or questions:
- Check logs in `logs/app.log`
- Verify worker status
- Check browser console for frontend errors

## Credits

Built with:
- PHP
- MySQL
- FFmpeg
- JWPlayer
- Modern CSS (Glassmorphism)
