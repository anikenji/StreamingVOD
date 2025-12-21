@echo off
cd /d "c:\wamp64\www\StreamingVOD\workers"

echo [%date% %time%] Starting One-Shot Video Processor

"C:\wamp64\bin\php\php8.2.26\php.exe" "c:\wamp64\www\StreamingVOD\workers\run-once.php"

echo [%date% %time%] Worker finished with exit code: %ERRORLEVEL%
exit /b %ERRORLEVEL%
