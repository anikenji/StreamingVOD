@echo off
title One-Shot Video Processor
color 0A
echo ===============================================
echo   One-Shot Video Processing
echo ===============================================
echo.
echo Processing all pending videos...
echo.

php "c:\wamp64\www\StreamingVOD\workers\run-once.php"

echo.
echo ===============================================
echo Press any key to exit...
pause >nul
