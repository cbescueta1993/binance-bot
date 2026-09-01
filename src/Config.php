<?php

namespace SupertrendBot;

/**
 * Plain settings holder, populated from environment variables.
 * Nothing in here talks to the network or the filesystem.
 */
class Config
{
    public string $apiKey;
    public string $secretKey;

    public string $symbol;
    public string $interval;

    public int $atrPeriod;
    public float $multiplier;

    public float $baseMargin;
    public float $martingaleMultiplier;

    /**
     * Safety cap: after this many consecutive losses the
     * martingale level resets to 0 instead of continuing to
     * double forever. Martingale sizing is unbounded without
     * this - do not remove it without understanding that.
     */
    public int $maxMartingaleLevel;

    public string $marginType;
    public int $leverage;

    public float $takeProfitPercent;
    public float $stopLossPercent;

    public bool $dryRun;

    /**
     * PHP has no OS timezone by default - without setting this,
     * date()/Logger timestamps can end up hours off from your
     * actual system clock (commonly UTC on Windows XAMPP builds).
     */
    public string $timezone;

    /**
     * 'poll' (default) fetches candles via plain REST GET requests
     * on a timer - the same endpoint that already works reliably.
     * 'websocket' uses the persistent wss:// kline stream instead,
     * which is faster but has been observed to silently receive
     * zero data on some networks (confirmed via VPN and browser
     * testing) - only switch to it once that's been verified fixed.
     */
    public string $marketDataMode;
    public int $pollIntervalSeconds;

    public static function fromEnv(): self
    {
        $c = new self();

        $c->apiKey = getenv('BINANCE_API_KEY') ?: '';
        $c->secretKey = getenv('BINANCE_SECRET_KEY') ?: '';

        $c->symbol = strtoupper(getenv('SYMBOL') ?: 'BTCUSDT');
        $c->interval = getenv('INTERVAL') ?: '5m';

        $c->atrPeriod = (int)(getenv('ATR_PERIOD') ?: 10);
        $c->multiplier = (float)(getenv('SUPERTREND_MULTIPLIER') ?: 3);

        $c->baseMargin = (float)(getenv('BASE_MARGIN') ?: 10);
        $c->martingaleMultiplier = (float)(getenv('MARTINGALE_MULTIPLIER') ?: 2);
        $c->maxMartingaleLevel = (int)(getenv('MAX_MARTINGALE_LEVEL') ?: 4);

        $c->marginType = strtoupper(getenv('MARGIN_TYPE') ?: 'ISOLATED');
        $c->leverage = (int)(getenv('LEVERAGE') ?: 5);

        $c->takeProfitPercent = (float)(getenv('TP_PERCENT') ?: 0.3);
        $c->stopLossPercent = (float)(getenv('SL_PERCENT') ?: 0.3);

        $c->dryRun = strtolower(getenv('DRY_RUN') ?: 'true') === 'true';

        $c->timezone = getenv('TIMEZONE') ?: 'Asia/Manila';

        $mode = strtolower(getenv('MARKET_DATA_MODE') ?: 'poll');
        $c->marketDataMode = in_array($mode, ['poll', 'websocket'], true) ? $mode : 'poll';
        $c->pollIntervalSeconds = (int)(getenv('POLL_INTERVAL_SECONDS') ?: 15);

        return $c;
    }
}
