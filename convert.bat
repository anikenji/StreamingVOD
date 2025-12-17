@echo off
chcp 65001 >nul
title HLS Encoder - 4000kbps
color 0A
echo ══════════════════════════════════════════════════════════════
echo            HLS ENCODER - Bitrate 4000kbps
echo ══════════════════════════════════════════════════════════════
echo.
echo Kéo thả file video vào đây hoặc nhập đường dẫn:
echo.
if "%~1"=="" (
    set /p INPUT="Nhập đường dẫn file: "
) else (
    set "INPUT=%~1"
)
set "OUTPUT=%~dpn1_hls"
mkdir "%OUTPUT%" 2>nul
echo.
echo [INFO] Đang encode: %~nx1
echo [INFO] Bitrate: 4000kbps
echo [INFO] Output: %OUTPUT%
echo.
ffmpeg -i "%INPUT%" ^
    -c:v libx264 ^
    -preset fast ^
    -b:v 4000k ^
    -maxrate 4500k ^
    -bufsize 8000k ^
    -g 48 ^
    -keyint_min 48 ^
    -sc_threshold 0 ^
    -c:a aac ^
    -b:a 192k ^
    -ar 48000 ^
    -hls_time 2 ^
    -hls_playlist_type vod ^
    -hls_segment_filename "%OUTPUT%\seg_%%04d.ts" ^
    -f hls ^
    "%OUTPUT%\video.m3u8" ^
    -y -stats
echo.
echo ══════════════════════════════════════════════════════════════
echo [DONE] Hoàn thành!
echo.
echo Output: %OUTPUT%\video.m3u8
echo ══════════════════════════════════════════════════════════════
pause