                                                                                                                                                                                                                                                                                                                                                                                                            @echo off
title HLS Video Processing Worker
color 0B
echo ===============================================
echo   HLS Streaming Video Processing Worker
echo ===============================================
echo.
echo Worker started at %date% %time%
echo Press Ctrl+C to stop the worker
echo.

:loop
php "c:\wamp64\www\StreamingVOD\workers\process-video.php"
if errorlevel 1 (
    echo.
    echo [ERROR] Worker crashed. Restarting in 10 seconds...
    timeout /t 10 /nobreak
)
goto loop
