@echo off
cd /d C:\xampp\htdocs\binance-bot
set BINANCE_API_KEY=bLnWru3nQsTC8SObwWOUJcAJGxYMT82YlYcopWcaRQP2TnM1Cbjl7uxKZzHoAxbW
set BINANCE_SECRET_KEY=pPaNUFs7M585VtUfvgfJIDwKWzjhvdlXvTn7LMPgaUN7opOSqmWCCVyUtaZKV0wg
set SYMBOL=XRPUSDT
set INTERVAL=5m
set ATR_PERIOD=10
set SUPERTREND_MULTIPLIER=3
set BASE_MARGIN=1
set MARTINGALE_MULTIPLIER=2
set MARGIN_TYPE=CROSSED
set LEVERAGE=10
set TP_PERCENT=0.3
set SL_PERCENT=0.3
set DRY_RUN=true
set TIMEZONE=Asia/Manila
set MARKET_DATA_MODE=poll
set POLL_INTERVAL_SECONDS=15
echo Starting Binance Supertrend Bot...
echo.
C:\xampp\php\php.exe bot.php
pause
