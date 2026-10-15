@echo off
title CSV Master - Web UI Dashboard
color 0B
cls
echo ======================================================
echo       CSV MASTER - BULK DATA STUDIO (WEB UI)
echo ======================================================
echo.
echo Starting Local Web Server at: http://localhost:8000
echo.
echo Press Ctrl+C in this window anytime to stop the server.
echo.
timeout /t 2 >nul
start http://localhost:8000
php -S localhost:8000
pause
