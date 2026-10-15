@echo off
title CSV Master - Bulk Data Processor
color 0A
cls
echo ======================================================
echo    CSV MASTER - BULK DATA PROCESSOR (PHP ENGINE)
echo ======================================================
echo.
echo Processing files from: input\
echo Saving collections to: output\
echo.
php cleaner.php
echo.
echo ======================================================
echo All processing complete! Press any key to exit.
echo ======================================================
pause >nul
