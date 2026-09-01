@echo off
cd /d C:\xampp\htdocs\supertrend-bot
echo Running standalone Binance connection test...
echo This will run until you press Ctrl+C.
echo.
C:\xampp\php\php.exe test-connection.php
pause
