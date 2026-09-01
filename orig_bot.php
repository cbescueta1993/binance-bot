<?php

require __DIR__ . '/vendor/autoload.php';

use Ratchet\Client\Connector;
use Ratchet\Client\WebSocket;
use React\EventLoop\Loop;
use React\Socket\Connector as ReactConnector;


/*
|--------------------------------------------------------------------------
| CONFIG
|--------------------------------------------------------------------------
*/

$config = [
    'apiKey' => getenv('BINANCE_API_KEY') ?: '',
    'secretKey' => getenv('BINANCE_SECRET_KEY') ?: '',

    'symbol' => strtoupper(
        getenv('SYMBOL') ?: 'BTCUSDT'
    ),

    'interval' =>
        getenv('INTERVAL') ?: '5m',

    'atrPeriod' =>
        (int)(getenv('ATR_PERIOD') ?: 10),

    'multiplier' =>
        (float)(getenv('SUPERTREND_MULTIPLIER') ?: 3),

    /*
     * Initial/base quantity
     */
    'baseMargin' =>
        (float)(getenv('BASE_MARGIN') ?: 10),

    /*
     * Martingale multiplier
     *
     * Level 0 = 1x
     * Level 1 = 2x
     * Level 2 = 4x
     * Level 3 = 8x
     */
    'martingaleMultiplier' =>
        (float)(getenv('MARTINGALE_MULTIPLIER') ?: 2),

    /*
     * Safety cap. After this many consecutive
     * losses the martingale level resets to 0
     * instead of continuing to double forever.
     * This is a real position-size safety limit -
     * do not remove it without understanding that
     * martingale sizing is unbounded otherwise.
     */
    'maxMartingaleLevel' =>
        (int)(getenv('MAX_MARTINGALE_LEVEL') ?: 4),

    'marginType' =>
        strtoupper(
            getenv('MARGIN_TYPE') ?: 'ISOLATED'
        ),

    'leverage' =>
        (int)(
            getenv('LEVERAGE') ?: 5
        ),

    'takeProfitPercent' =>
        (float)(
            getenv('TP_PERCENT') ?: 0.3
        ),

    'stopLossPercent' =>
        (float)(
            getenv('SL_PERCENT') ?: 0.3
        ),

    /*
     * IMPORTANT
     *
     * Start with true.
     */
    'dryRun' =>
        strtolower(
            getenv('DRY_RUN') ?: 'true'
        ) === 'true'
];


/*
|--------------------------------------------------------------------------
| FILE DATABASE
|--------------------------------------------------------------------------
*/

define(
    'DATA_DIR',
    __DIR__ . '/data'
);

define(
    'STATE_FILE',
    DATA_DIR . '/state.json'
);

define(
    'TRADES_FILE',
    DATA_DIR . '/trades.jsonl'
);

define(
    'LOG_FILE',
    DATA_DIR . '/bot.log'
);


/*
|--------------------------------------------------------------------------
| GLOBALS
|--------------------------------------------------------------------------
*/

$candles = [];

$lastProcessedCandle = null;

$orderWs = null;

$orderWsConnected = false;

/*
 * Requests we've sent via order.place and are
 * waiting to be *acknowledged* (status 200).
 * Keyed by the JSON-RPC request id we generated.
 */
$pendingRequests = [];

/*
 * Orders that Binance has acknowledged (we have
 * a real Binance orderId for them) and that we
 * are waiting to see FILLED on the user data stream.
 * Keyed by Binance orderId.
 */
$openOrders = [];

/*
 * Links a TP orderId <-> SL orderId so that when
 * one fills we can cancel the other (manual OCO).
 * Populated once BOTH protection orders for an
 * entry have been acknowledged.
 */
$protectionPairs = [];

/*
 * Temporary holder while we wait for both the TP
 * and SL acks to come back for a given entry, so
 * we can link them into $protectionPairs.
 * Keyed by entry orderId.
 */
$pendingProtection = [];

/*
 * Symbol trading filters (quantity step size,
 * price tick size, decimal precisions) fetched
 * once at startup from /fapi/v1/exchangeInfo.
 */
$symbolFilters = null;

$listenKey = null;


/*
|--------------------------------------------------------------------------
| LOG
|--------------------------------------------------------------------------
*/

function botLog(
    string $message
): void {

    $line =
        '['
        . date('Y-m-d H:i:s')
        . '] '
        . $message
        . PHP_EOL;

    echo $line;

    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0755, true);
    }

    file_put_contents(
        LOG_FILE,
        $line,
        FILE_APPEND | LOCK_EX
    );
}


/*
|--------------------------------------------------------------------------
| DATABASE
|--------------------------------------------------------------------------
*/

function initializeDatabase(): void
{
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0755, true);
    }

    if (!file_exists(STATE_FILE)) {

        $state = [

            'symbol' => 'BTCUSDT',
            'trend' => null,

            'martingaleLevel' => 0,
            'consecutiveLosses' => 0,

            'lastWinPnl' => 0,
            'lastWinTime' => null,

            'lastSignal' => null,
            'lastCandle' => null,

            'position' => 'NONE',
            'positionSide' => null,
            'quantity' => 0,
            'entryPrice' => 0,

            'lastOrderId' => null,
            'lastOrderTime' => null,

            'tpOrderId' => null,
            'slOrderId' => null,

            'totalTrades' => 0,
            'wins' => 0,
            'losses' => 0,
            'realizedPnl' => 0
        ];

        saveState($state);
    }

    if (!file_exists(TRADES_FILE)) {
        touch(TRADES_FILE);
    }

    if (!file_exists(LOG_FILE)) {
        touch(LOG_FILE);
    }
}


function loadState(): array
{
    if (!file_exists(STATE_FILE)) {
        initializeDatabase();
    }

    $json = file_get_contents(STATE_FILE);

    $state = json_decode($json, true);

    if (!is_array($state)) {
        throw new RuntimeException('Invalid state.json');
    }

    return $state;
}


function saveState(array $state): void
{
    file_put_contents(
        STATE_FILE,
        json_encode($state, JSON_PRETTY_PRINT),
        LOCK_EX
    );
}


/*
|--------------------------------------------------------------------------
| SYMBOL FILTERS (quantity step size / price tick size)
|--------------------------------------------------------------------------
|
| Binance rejects orders whose quantity/price don't match the symbol's
| LOT_SIZE / PRICE_FILTER. BTCUSDT happens to want 3 decimal qty and
| 1 decimal price, but that is NOT true for every symbol, so we fetch
| the real filters instead of hardcoding decimals.
|--------------------------------------------------------------------------
*/

function fetchSymbolFilters(string $symbol): array
{
    $url = 'https://fapi.binance.com/fapi/v1/exchangeInfo';

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        throw new RuntimeException(curl_error($ch));
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($httpCode !== 200) {
        throw new RuntimeException("exchangeInfo HTTP $httpCode: $response");
    }

    $data = json_decode($response, true);

    $symbolInfo = null;

    foreach (($data['symbols'] ?? []) as $s) {
        if ($s['symbol'] === $symbol) {
            $symbolInfo = $s;
            break;
        }
    }

    if ($symbolInfo === null) {
        throw new RuntimeException("Symbol $symbol not found in exchangeInfo");
    }

    $stepSize = '1';
    $tickSize = '0.01';
    $minQty = '0';

    foreach ($symbolInfo['filters'] as $filter) {

        if ($filter['filterType'] === 'LOT_SIZE') {
            $stepSize = $filter['stepSize'];
            $minQty = $filter['minQty'];
        }

        if ($filter['filterType'] === 'PRICE_FILTER') {
            $tickSize = $filter['tickSize'];
        }
    }

    return [
        'stepSize' => $stepSize,
        'minQty' => (float)$minQty,
        'qtyPrecision' => decimalsFromStep($stepSize),
        'tickSize' => $tickSize,
        'pricePrecision' => decimalsFromStep($tickSize),
    ];
}

function decimalsFromStep(string $step): int
{
    $step = rtrim($step, '0');

    if (strpos($step, '.') === false) {
        return 0;
    }

    return strlen(substr($step, strpos($step, '.') + 1));
}

/**
 * Round DOWN to the nearest valid step (never round up
 * quantity - that could exceed the margin we calculated).
 */
function roundToStep(float $value, string $stepSize): float
{
    $step = (float)$stepSize;

    if ($step <= 0) {
        return $value;
    }

    $steps = floor($value / $step);

    return $steps * $step;
}

/**
 * Round price to the nearest valid tick.
 */
function roundToTick(float $value, string $tickSize): float
{
    $tick = (float)$tickSize;

    if ($tick <= 0) {
        return $value;
    }

    return round($value / $tick) * $tick;
}

function formatDecimal(float $value, int $precision): string
{
    return number_format($value, $precision, '.', '');
}


function calculateOrderQuantity(
    float $margin,
    int $leverage,
    float $price
): float {

    if ($margin <= 0) {
        throw new InvalidArgumentException('Margin must be greater than zero.');
    }

    if ($leverage <= 0) {
        throw new InvalidArgumentException('Leverage must be greater than zero.');
    }

    if ($price <= 0) {
        throw new InvalidArgumentException('Price must be greater than zero.');
    }

    $notional = $margin * $leverage;

    $quantity = $notional / $price;

    return $quantity;
}

function calculateMartingaleMargin(
    float $baseMargin,
    float $multiplier,
    int $level
): float {

    return $baseMargin * pow($multiplier, $level);
}

function getLatestPrice(): float
{
    global $config;

    $url =
        'https://fapi.binance.com/fapi/v1/ticker/price'
        . '?symbol='
        . urlencode($config['symbol']);

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        throw new RuntimeException(curl_error($ch));
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($httpCode !== 200) {
        throw new RuntimeException("Binance price error: $response");
    }

    $data = json_decode($response, true);

    if (!isset($data['price'])) {
        throw new RuntimeException('Invalid Binance price response');
    }

    return (float)$data['price'];
}

function setMarginType(): bool
{
    global $config;

    $marginType = strtoupper($config['marginType']);

    if (!in_array($marginType, ['ISOLATED', 'CROSSED'], true)) {
        throw new InvalidArgumentException('MARGIN_TYPE must be ISOLATED or CROSSED');
    }

    try {

        binanceSignedRequest(
            'POST',
            '/fapi/v1/marginType',
            [
                'symbol' => $config['symbol'],
                'marginType' => $marginType
            ]
        );

        botLog('Margin Type set: ' . $marginType);

        return true;

    } catch (Throwable $e) {

        /*
         * Binance returns an error if the symbol is
         * already using this margin type.
         *
         * -4046 = No need to change margin type.
         */
        if (strpos($e->getMessage(), '-4046') !== false) {
            botLog('Margin Type already set: ' . $marginType);
            return true;
        }

        botLog('ERROR setting margin type: ' . $e->getMessage());

        return false;
    }
}

function setLeverage(): bool
{
    global $config;

    $leverage = (int)$config['leverage'];

    if ($leverage < 1 || $leverage > 125) {
        throw new InvalidArgumentException('Invalid leverage: ' . $leverage);
    }

    try {

        binanceSignedRequest(
            'POST',
            '/fapi/v1/leverage',
            [
                'symbol' => $config['symbol'],
                'leverage' => $leverage
            ]
        );

        botLog('Leverage set: ' . $leverage . 'x');

        return true;

    } catch (Throwable $e) {

        botLog('ERROR setting leverage: ' . $e->getMessage());

        return false;
    }
}

function initializeTradingSettings(): bool
{
    global $config;

    if ($config['dryRun']) {
        botLog('DRY RUN: skipping margin/leverage configuration.');
        return true;
    }

    botLog('Configuring Binance Futures trading settings...');

    if (!setMarginType()) {
        botLog('ERROR: Could not configure margin type.');
        return false;
    }

    if (!setLeverage()) {
        botLog('ERROR: Could not configure leverage.');
        return false;
    }

    botLog('Trading settings configured successfully.');

    return true;
}

function calculateTPSL(string $side, float $entryPrice): array
{
    global $config;

    $tpPercent = $config['takeProfitPercent'] / 100;
    $slPercent = $config['stopLossPercent'] / 100;

    if ($side === 'BUY') {

        $takeProfit = $entryPrice * (1 + $tpPercent);
        $stopLoss = $entryPrice * (1 - $slPercent);

    } else {

        $takeProfit = $entryPrice * (1 - $tpPercent);
        $stopLoss = $entryPrice * (1 + $slPercent);
    }

    return [
        'takeProfit' => $takeProfit,
        'stopLoss' => $stopLoss
    ];
}

/**
 * Builds a TAKE_PROFIT_MARKET / STOP_MARKET order that closes
 * the ENTIRE open position (closePosition=true) instead of
 * relying on a hand-tracked quantity. This avoids any mismatch
 * between what we think the position size is and what Binance
 * actually holds, and means we don't need `reduceOnly` either
 * (closePosition implies it).
 */
function createProtectionOrderMessage(
    string $symbol,
    string $side,
    string $type,
    float $stopPrice,
    int $pricePrecision
): array {

    global $config;

    $requestId = bin2hex(random_bytes(16));

    $params = [

        'apiKey' => $config['apiKey'],
        'symbol' => $symbol,
        'side' => $side,
        'type' => $type,

        'stopPrice' => formatDecimal($stopPrice, $pricePrecision),

        'closePosition' => 'true',
        'workingType' => 'MARK_PRICE',

        'timestamp' => (int)(microtime(true) * 1000),
        'recvWindow' => 5000
    ];

    $params['signature'] = createSignature($params, $config['secretKey']);

    $message = json_encode([
        'id' => $requestId,
        'method' => 'order.place',
        'params' => $params
    ]);

    return [
        'requestId' => $requestId,
        'message' => $message
    ];
}

function getExitSide(string $entrySide): string
{
    return $entrySide === 'BUY' ? 'SELL' : 'BUY';
}

function logTrade(array $trade): void
{
    file_put_contents(
        TRADES_FILE,
        json_encode($trade) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}


/*
|--------------------------------------------------------------------------
| ORDER CANCELLATION (used for manual OCO between TP and SL)
|--------------------------------------------------------------------------
*/

function cancelOrder(string $symbol, $orderId): void
{
    global $config;

    if ($config['dryRun']) {
        return;
    }

    try {

        binanceSignedRequest(
            'DELETE',
            '/fapi/v1/order',
            [
                'symbol' => $symbol,
                'orderId' => $orderId
            ]
        );

        botLog("Cancelled sibling order #$orderId");

    } catch (Throwable $e) {

        /*
         * -2011 = Unknown order sent (it already
         * filled or was already cancelled). That's
         * fine - it's exactly the race we're guarding
         * against.
         */
        if (strpos($e->getMessage(), '-2011') !== false) {
            botLog("Sibling order #$orderId already gone (race with fill), OK.");
            return;
        }

        botLog("WARNING: failed to cancel order #$orderId: " . $e->getMessage());
    }
}


/*
|--------------------------------------------------------------------------
| HISTORICAL DATA
|--------------------------------------------------------------------------
*/

function getHistoricalKlines(
    string $symbol,
    string $interval,
    int $limit = 200
): array {

    $url =
        'https://fapi.binance.com/fapi/v1/klines'
        . '?symbol=' . urlencode($symbol)
        . '&interval=' . urlencode($interval)
        . '&limit=' . $limit;

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        throw new RuntimeException(curl_error($ch));
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($httpCode !== 200) {
        throw new RuntimeException("Binance HTTP $httpCode: $response");
    }

    $data = json_decode($response, true);

    if (!is_array($data)) {
        throw new RuntimeException('Invalid Binance response');
    }

    return $data;
}


function convertKline(array $k): array
{
    return [
        'openTime' => (int)$k[0],
        'open' => (float)$k[1],
        'high' => (float)$k[2],
        'low' => (float)$k[3],
        'close' => (float)$k[4],
        'volume' => (float)$k[5],
        'closeTime' => (int)$k[6]
    ];
}


/*
|--------------------------------------------------------------------------
| TRUE RANGE
|--------------------------------------------------------------------------
*/

function trueRange(array $current, ?array $previous): float
{
    if ($previous === null) {
        return $current['high'] - $current['low'];
    }

    return max(
        $current['high'] - $current['low'],
        abs($current['high'] - $previous['close']),
        abs($current['low'] - $previous['close'])
    );
}


/*
|--------------------------------------------------------------------------
| TRADINGVIEW ATR (ta.atr() - Wilder RMA)
|--------------------------------------------------------------------------
*/

function calculateATR(array $candles, int $period): array
{
    $count = count($candles);

    $atr = [];

    if ($count < $period) {
        return $atr;
    }

    $tr = [];

    for ($i = 0; $i < $count; $i++) {

        $previous = $i > 0 ? $candles[$i - 1] : null;

        $tr[$i] = trueRange($candles[$i], $previous);
    }

    /*
     * Initial SMA
     */
    $sum = 0;

    for ($i = 0; $i < $period; $i++) {
        $sum += $tr[$i];
    }

    $atr[$period - 1] = $sum / $period;

    /*
     * Wilder RMA
     */
    for ($i = $period; $i < $count; $i++) {

        $atr[$i] =
            (($atr[$i - 1] * ($period - 1)) + $tr[$i]) / $period;
    }

    return $atr;
}


/*
|--------------------------------------------------------------------------
| TRADINGVIEW SUPERTREND
|--------------------------------------------------------------------------
*/

function calculateSupertrend(
    array $candles,
    int $period = 10,
    float $multiplier = 3
): array {

    $count = count($candles);

    $atr = calculateATR($candles, $period);

    $up = [];
    $dn = [];
    $trend = [];
    $result = [];

    for ($i = $period - 1; $i < $count; $i++) {

        $high = $candles[$i]['high'];
        $low = $candles[$i]['low'];
        $close = $candles[$i]['close'];

        // Pine: src = hl2
        $src = ($high + $low) / 2;

        // Pine: up = src-(Multiplier*atr)
        $currentUp = $src - ($multiplier * $atr[$i]);

        // Pine: up1 = nz(up[1],up)
        $up1 = ($i === $period - 1) ? $currentUp : $up[$i - 1];

        // Pine: up := close[1] > up1 ? max(up,up1) : up
        if ($i > 0) {
            $previousClose = $candles[$i - 1]['close'];
            if ($previousClose > $up1) {
                $currentUp = max($currentUp, $up1);
            }
        }

        $up[$i] = $currentUp;

        // DOWN BAND
        $currentDn = $src + ($multiplier * $atr[$i]);

        $dn1 = ($i === $period - 1) ? $currentDn : $dn[$i - 1];

        // Pine: dn := close[1] < dn1 ? min(dn,dn1) : dn
        if ($i > 0) {
            $previousClose = $candles[$i - 1]['close'];
            if ($previousClose < $dn1) {
                $currentDn = min($currentDn, $dn1);
            }
        }

        $dn[$i] = $currentDn;

        // TREND
        if ($i === $period - 1) {

            $currentTrend = 1;

        } else {

            $previousTrend = $trend[$i - 1];
            $currentTrend = $previousTrend;

            // Pine: trend == -1 && close > dn1
            if ($previousTrend === -1 && $close > $dn1) {
                $currentTrend = 1;

            // Pine: trend == 1 && close < up1
            } elseif ($previousTrend === 1 && $close < $up1) {
                $currentTrend = -1;
            }
        }

        $trend[$i] = $currentTrend;

        // SIGNAL
        $buySignal = false;
        $sellSignal = false;

        if ($i > $period - 1) {

            $previousTrend = $trend[$i - 1];

            $buySignal = $currentTrend === 1 && $previousTrend === -1;
            $sellSignal = $currentTrend === -1 && $previousTrend === 1;
        }

        $result[$i] = [
            'atr' => $atr[$i],
            'source' => $src,
            'up' => $currentUp,
            'dn' => $currentDn,
            'trend' => $currentTrend,
            'direction' => $currentTrend === 1 ? 'UP' : 'DOWN',
            'buySignal' => $buySignal,
            'sellSignal' => $sellSignal
        ];
    }

    return $result;
}


/*
|--------------------------------------------------------------------------
| BINANCE SIGNATURE / REST HELPERS
|--------------------------------------------------------------------------
*/

function createSignature(array $params, string $secret): string
{
    ksort($params);

    $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

    return hash_hmac('sha256', $query, $secret);
}

function binanceSignedRequest(
    string $method,
    string $endpoint,
    array $params
): array {

    global $config;

    $params['timestamp'] = (int)(microtime(true) * 1000);
    $params['recvWindow'] = 5000;

    $params['signature'] = createSignature($params, $config['secretKey']);

    $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

    $method = strtoupper($method);

    if ($method === 'GET' || $method === 'DELETE') {
        $url = 'https://fapi.binance.com' . $endpoint . '?' . $query;
        $body = null;
    } else {
        $url = 'https://fapi.binance.com' . $endpoint;
        $body = $query;
    }

    $ch = curl_init($url);

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['X-MBX-APIKEY: ' . $config['apiKey']],
        CURLOPT_TIMEOUT => 10
    ];

    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = $body;
    }

    curl_setopt_array($ch, $opts);

    $response = curl_exec($ch);

    if ($response === false) {
        throw new RuntimeException(curl_error($ch));
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    $data = json_decode($response, true);

    if ($httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException(
            'Binance API error ' . $httpCode . ': ' . $response
        );
    }

    return is_array($data) ? $data : [];
}

/**
 * For endpoints that only need the API-key header
 * (no HMAC signature) - listenKey management.
 */
function binanceApiKeyRequest(string $method, string $endpoint): array
{
    global $config;

    $url = 'https://fapi.binance.com' . $endpoint;

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => ['X-MBX-APIKEY: ' . $config['apiKey']],
        CURLOPT_TIMEOUT => 10
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        throw new RuntimeException(curl_error($ch));
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    $data = json_decode($response, true);

    if ($httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException(
            'Binance API error ' . $httpCode . ': ' . $response
        );
    }

    return is_array($data) ? $data : [];
}


/*
|--------------------------------------------------------------------------
| USER DATA STREAM (listenKey) - needed to know when TP/SL actually FILL
|--------------------------------------------------------------------------
*/

function getListenKey(): string
{
    $data = binanceApiKeyRequest('POST', '/fapi/v1/listenKey');

    if (!isset($data['listenKey'])) {
        throw new RuntimeException('Failed to obtain listenKey');
    }

    return $data['listenKey'];
}

function keepAliveListenKey(): void
{
    try {
        binanceApiKeyRequest('PUT', '/fapi/v1/listenKey');
        botLog('listenKey keep-alive sent.');
    } catch (Throwable $e) {
        botLog('WARNING: listenKey keep-alive failed: ' . $e->getMessage());
    }
}


/*
|--------------------------------------------------------------------------
| MARTINGALE / TRADE SIZING
|--------------------------------------------------------------------------
*/

function getTradeMargin(): float
{
    global $config;

    $state = loadState();

    $level = (int)$state['martingaleLevel'];

    return calculateMartingaleMargin(
        $config['baseMargin'],
        $config['martingaleMultiplier'],
        $level
    );
}


/*
|--------------------------------------------------------------------------
| EXECUTE ORDER (open a new position)
|--------------------------------------------------------------------------
*/

function executeOrder(string $signal): void
{
    global $config, $orderWs, $orderWsConnected, $pendingRequests, $symbolFilters;

    $state = loadState();

    /*
     * Don't open another position.
     */
    if ($state['position'] !== 'NONE') {

        botLog(
            'Position already exists: '
            . $state['position'] . ' ' . $state['positionSide']
        );

        return;
    }

    $side = $signal === 'BUY' ? 'BUY' : 'SELL';

    $currentPrice = getLatestPrice();

    $margin = getTradeMargin();

    $rawQuantity = calculateOrderQuantity(
        $margin,
        $config['leverage'],
        $currentPrice
    );

    $qtyPrecision = $symbolFilters['qtyPrecision'] ?? 3;
    $stepSize = $symbolFilters['stepSize'] ?? '0.001';
    $minQty = $symbolFilters['minQty'] ?? 0.0;

    $quantity = roundToStep($rawQuantity, $stepSize);

    if ($quantity <= 0 || $quantity < $minQty) {

        botLog(
            "ERROR: computed quantity ($rawQuantity -> $quantity) is below "
            . "the exchange minimum ($minQty) for {$config['symbol']}. "
            . 'Increase BASE_MARGIN or LEVERAGE. Skipping this signal.'
        );

        return;
    }

    $quantityString = formatDecimal($quantity, $qtyPrecision);

    $protection = calculateTPSL($side, $currentPrice);

    botLog(
        "SIGNAL: $signal | SIDE: $side | PRICE: $currentPrice"
        . ' | MARGIN: $' . number_format($margin, 2)
        . ' | LEVERAGE: ' . $config['leverage'] . 'x'
        . " | QTY: $quantityString"
        . ' | TP: ' . $protection['takeProfit']
        . ' | SL: ' . $protection['stopLoss']
        . ' | MARTINGALE LEVEL: ' . $state['martingaleLevel']
    );

    /*
     * DRY RUN
     */
    if ($config['dryRun']) {
        botLog('DRY RUN - order NOT sent');
        botLog('DRY RUN TP: ' . $protection['takeProfit']);
        botLog('DRY RUN SL: ' . $protection['stopLoss']);
        return;
    }

    if (!$orderWsConnected || $orderWs === null) {
        botLog('ERROR: Binance order WebSocket is not connected.');
        return;
    }

    $requestId = bin2hex(random_bytes(16));

    $params = [
        'apiKey' => $config['apiKey'],
        'symbol' => $config['symbol'],
        'side' => $side,
        'type' => 'MARKET',
        'quantity' => $quantityString,
        'timestamp' => (int)(microtime(true) * 1000),
        'recvWindow' => 5000
    ];

    $params['signature'] = createSignature($params, $config['secretKey']);

    $message = json_encode([
        'id' => $requestId,
        'method' => 'order.place',
        'params' => $params
    ]);

    $pendingRequests[$requestId] = [
        'type' => 'ENTRY',
        'signal' => $signal,
        'side' => $side,
        'quantity' => $quantityString,
        'estimatedPrice' => $currentPrice,
        'time' => time()
    ];

    $orderWs->send($message);

    botLog("ENTRY ORDER SENT: $side $quantityString {$config['symbol']}");
}


/*
|--------------------------------------------------------------------------
| HANDLE: entry order has actually FILLED (from user data stream)
|--------------------------------------------------------------------------
*/

function handleEntryFilled(
    int $orderId,
    array $pending,
    float $entryPrice
): void {

    global $config, $orderWs, $orderWsConnected, $pendingRequests,
           $pendingProtection, $symbolFilters;

    botLog("ENTRY FILLED: #$orderId @ $entryPrice");

    $state = loadState();

    $state['position'] = $pending['side'] === 'BUY' ? 'LONG' : 'SHORT';
    $state['positionSide'] = $pending['side'];
    $state['quantity'] = (float)$pending['quantity'];
    $state['entryPrice'] = $entryPrice;
    $state['lastOrderId'] = $orderId;
    $state['lastOrderTime'] = time();

    saveState($state);

    if (!$orderWsConnected || $orderWs === null) {
        botLog('ERROR: order WS down, cannot place TP/SL! Manual intervention needed.');
        return;
    }

    $protection = calculateTPSL($pending['side'], $entryPrice);
    $exitSide = getExitSide($pending['side']);

    $pricePrecision = $symbolFilters['pricePrecision'] ?? 2;
    $tickSize = $symbolFilters['tickSize'] ?? '0.1';

    $tpPrice = roundToTick($protection['takeProfit'], $tickSize);
    $slPrice = roundToTick($protection['stopLoss'], $tickSize);

    $tp = createProtectionOrderMessage(
        $config['symbol'],
        $exitSide,
        'TAKE_PROFIT_MARKET',
        $tpPrice,
        $pricePrecision
    );

    $sl = createProtectionOrderMessage(
        $config['symbol'],
        $exitSide,
        'STOP_MARKET',
        $slPrice,
        $pricePrecision
    );

    $pendingRequests[$tp['requestId']] = [
        'type' => 'TP',
        'entrySide' => $pending['side'],
        'entryOrderId' => $orderId,
        'price' => $tpPrice
    ];

    $pendingRequests[$sl['requestId']] = [
        'type' => 'SL',
        'entrySide' => $pending['side'],
        'entryOrderId' => $orderId,
        'price' => $slPrice
    ];

    $pendingProtection[$orderId] = [
        'tpOrderId' => null,
        'slOrderId' => null
    ];

    $orderWs->send($tp['message']);
    botLog('TP SENT: ' . $tpPrice);

    $orderWs->send($sl['message']);
    botLog('SL SENT: ' . $slPrice);
}


/*
|--------------------------------------------------------------------------
| HANDLE: TP or SL has actually FILLED (position closed)
|--------------------------------------------------------------------------
*/

function handleProtectionFilled(
    int $orderId,
    array $order,
    float $realizedPnl
): void {

    global $config, $protectionPairs;

    $type = $order['type'];
    $isWin = $type === 'TP';

    botLog(
        ($isWin ? 'TAKE PROFIT' : 'STOP LOSS')
        . " HIT: #$orderId | Realized PnL: $realizedPnl"
    );

    /*
     * Cancel the sibling order (manual OCO). If it already
     * filled/cancelled itself this is a harmless no-op.
     */
    if (isset($protectionPairs[$orderId])) {

        $siblingId = $protectionPairs[$orderId];

        cancelOrder($config['symbol'], $siblingId);

        unset($protectionPairs[$siblingId]);
        unset($protectionPairs[$orderId]);
    }

    $state = loadState();

    $level = (int)$state['martingaleLevel'];

    if ($isWin) {

        $level = 0;
        $state['consecutiveLosses'] = 0;
        $state['lastWinPnl'] = $realizedPnl;
        $state['lastWinTime'] = time();
        $state['wins'] = (int)($state['wins'] ?? 0) + 1;

    } else {

        $state['consecutiveLosses'] = (int)($state['consecutiveLosses'] ?? 0) + 1;
        $level++;
        $state['losses'] = (int)($state['losses'] ?? 0) + 1;

        if ($level > $config['maxMartingaleLevel']) {

            botLog(
                'MARTINGALE SAFETY CAP REACHED (level '
                . $level . ' > max ' . $config['maxMartingaleLevel']
                . '). Resetting to level 0 instead of doubling further.'
            );

            $level = 0;
        }
    }

    $state['martingaleLevel'] = $level;
    $state['totalTrades'] = (int)($state['totalTrades'] ?? 0) + 1;
    $state['realizedPnl'] = (float)($state['realizedPnl'] ?? 0) + $realizedPnl;

    $state['position'] = 'NONE';
    $state['positionSide'] = null;
    $state['quantity'] = 0;
    $state['entryPrice'] = 0;
    $state['tpOrderId'] = null;
    $state['slOrderId'] = null;

    saveState($state);

    logTrade([
        'time' => date('c'),
        'symbol' => $config['symbol'],
        'result' => $isWin ? 'WIN' : 'LOSS',
        'closedBy' => $type,
        'orderId' => $orderId,
        'realizedPnl' => $realizedPnl,
        'nextMartingaleLevel' => $level
    ]);
}


/*
|--------------------------------------------------------------------------
| ORDER WEBSOCKET (order.place acknowledgements)
|--------------------------------------------------------------------------
*/

function connectOrderWebSocket(): void
{
    global $orderWs, $orderWsConnected, $config, $pendingRequests,
           $openOrders, $pendingProtection, $protectionPairs;

    if ($config['dryRun']) {
        botLog('DRY RUN: order WebSocket disabled.');
        return;
    }

    $connector = new Connector(
        Loop::get(),
        new ReactConnector(['timeout' => 10])
    );

    $url = 'wss://ws-fapi.binance.com/ws-fapi/v1';

    botLog('Connecting Binance Order WebSocket...');

    $connector($url)->then(

        function (WebSocket $conn) use (&$orderWs, &$orderWsConnected) {

            $orderWs = $conn;
            $orderWsConnected = true;

            botLog('Binance Order WebSocket CONNECTED.');

            $conn->on('message', function ($message) {

                global $pendingRequests, $openOrders, $pendingProtection,
                       $protectionPairs;

                $data = json_decode((string)$message, true);

                if (!is_array($data)) {
                    botLog('Invalid order WS response: ' . (string)$message);
                    return;
                }

                if (!isset($data['id']) || !isset($pendingRequests[$data['id']])) {
                    return;
                }

                $id = $data['id'];
                $pending = $pendingRequests[$id];

                if (!isset($data['status']) || $data['status'] !== 200) {

                    botLog(
                        'ORDER REJECTED (' . $pending['type'] . '): '
                        . json_encode($data)
                    );

                    unset($pendingRequests[$id]);
                    return;
                }

                $result = $data['result'] ?? [];
                $orderId = $result['orderId'] ?? null;

                if ($orderId === null) {
                    botLog('WARNING: order ack with no orderId: ' . (string)$message);
                    unset($pendingRequests[$id]);
                    return;
                }

                if ($pending['type'] === 'ENTRY') {

                    /*
                     * Just record it - the actual fill (and therefore
                     * placing TP/SL) is confirmed via the user data
                     * stream's ORDER_TRADE_UPDATE event, not here.
                     * For MARKET orders the fill event usually arrives
                     * within the same second, but we don't assume it.
                     */
                    $openOrders[$orderId] = array_merge($pending, [
                        'type' => 'ENTRY'
                    ]);

                    botLog("ENTRY ORDER ACCEPTED: #$orderId (awaiting fill confirmation)");

                } elseif ($pending['type'] === 'TP' || $pending['type'] === 'SL') {

                    $openOrders[$orderId] = $pending;

                    $entryOrderId = $pending['entryOrderId'];

                    if (isset($pendingProtection[$entryOrderId])) {

                        if ($pending['type'] === 'TP') {
                            $pendingProtection[$entryOrderId]['tpOrderId'] = $orderId;
                        } else {
                            $pendingProtection[$entryOrderId]['slOrderId'] = $orderId;
                        }

                        $tpId = $pendingProtection[$entryOrderId]['tpOrderId'];
                        $slId = $pendingProtection[$entryOrderId]['slOrderId'];

                        if ($tpId !== null && $slId !== null) {
                            $protectionPairs[$tpId] = $slId;
                            $protectionPairs[$slId] = $tpId;
                            unset($pendingProtection[$entryOrderId]);
                        }
                    }

                    botLog("{$pending['type']} ORDER ACCEPTED: #$orderId @ {$pending['price']}");
                }

                unset($pendingRequests[$id]);
            });

            $conn->on('close', function () {

                global $orderWs, $orderWsConnected;

                $orderWs = null;
                $orderWsConnected = false;

                botLog('Order WebSocket disconnected.');

                Loop::addTimer(3, 'connectOrderWebSocket');
            });
        },

        function (Exception $e) {

            global $orderWs, $orderWsConnected;

            $orderWs = null;
            $orderWsConnected = false;

            botLog('Order WebSocket ERROR: ' . $e->getMessage());

            Loop::addTimer(3, 'connectOrderWebSocket');
        }
    );
}


/*
|--------------------------------------------------------------------------
| USER DATA WEBSOCKET (this is where fills are actually confirmed)
|--------------------------------------------------------------------------
*/

function connectUserDataWebSocket(): void
{
    global $config, $listenKey, $openOrders;

    if ($config['dryRun']) {
        botLog('DRY RUN: user data WebSocket disabled.');
        return;
    }

    try {
        $listenKey = getListenKey();
    } catch (Throwable $e) {
        botLog('ERROR getting listenKey: ' . $e->getMessage());
        Loop::addTimer(5, 'connectUserDataWebSocket');
        return;
    }

    $connector = new Connector(
        Loop::get(),
        new ReactConnector(['timeout' => 10])
    );

    $url = 'wss://fstream.binance.com/ws/' . $listenKey;

    botLog('Connecting Binance User Data WebSocket...');

    $connector($url)->then(

        function (WebSocket $conn) {

            botLog('User Data WebSocket CONNECTED.');

            /*
             * Keep the listenKey alive (Binance requires a
             * PUT at least every 60 minutes; we do it every 30).
             */
            Loop::addPeriodicTimer(1800, 'keepAliveListenKey');

            $conn->on('message', function ($message) {

                global $openOrders;

                $data = json_decode((string)$message, true);

                if (!is_array($data) || !isset($data['e'])) {
                    return;
                }

                if ($data['e'] !== 'ORDER_TRADE_UPDATE') {
                    return;
                }

                $o = $data['o'] ?? null;

                if ($o === null || !isset($o['i'], $o['X'])) {
                    return;
                }

                if ($o['X'] !== 'FILLED') {
                    return;
                }

                $orderId = (int)$o['i'];

                if (!isset($openOrders[$orderId])) {
                    /*
                     * Fill event for an order we're not tracking
                     * (e.g. placed manually, or from a previous
                     * bot run). Ignore it.
                     */
                    return;
                }

                $order = $openOrders[$orderId];

                $avgPrice = isset($o['ap']) ? (float)$o['ap'] : 0.0;
                $lastPrice = isset($o['L']) ? (float)$o['L'] : 0.0;
                $fillPrice = $avgPrice > 0 ? $avgPrice : $lastPrice;

                $realizedPnl = isset($o['rp']) ? (float)$o['rp'] : 0.0;

                if ($order['type'] === 'ENTRY') {

                    handleEntryFilled($orderId, $order, $fillPrice);

                } elseif ($order['type'] === 'TP' || $order['type'] === 'SL') {

                    handleProtectionFilled($orderId, $order, $realizedPnl);
                }

                unset($openOrders[$orderId]);
            });

            $conn->on('close', function () {

                botLog('User Data WebSocket disconnected.');

                Loop::addTimer(3, 'connectUserDataWebSocket');
            });
        },

        function (Exception $e) {

            botLog('User Data WS ERROR: ' . $e->getMessage());

            Loop::addTimer(3, 'connectUserDataWebSocket');
        }
    );
}


/*
|--------------------------------------------------------------------------
| MARKET WEBSOCKET
|--------------------------------------------------------------------------
*/

function connectMarketWebSocket(): void
{
    global $config;

    $connector = new Connector(
        Loop::get(),
        new ReactConnector(['timeout' => 10])
    );

    $streamSymbol = strtolower($config['symbol']);

    $url =
        'wss://fstream.binance.com/ws/'
        . $streamSymbol
        . '@kline_'
        . $config['interval'];

    botLog('Connecting Market WebSocket...');

    $connector($url)->then(

        function (WebSocket $conn) {

            botLog('Market WebSocket CONNECTED.');

            $conn->on('message', function ($message) {

                global $candles, $config, $lastProcessedCandle;

                $data = json_decode((string)$message, true);

                if (!$data || !isset($data['k'])) {
                    return;
                }

                $k = $data['k'];

                // ONLY CLOSED CANDLE (x = true)
                if (!isset($k['x']) || $k['x'] !== true) {
                    return;
                }

                $candle = [
                    'openTime' => (int)$k['t'],
                    'open' => (float)$k['o'],
                    'high' => (float)$k['h'],
                    'low' => (float)$k['l'],
                    'close' => (float)$k['c'],
                    'volume' => (float)$k['v'],
                    'closeTime' => (int)$k['T']
                ];

                // Prevent duplicate processing
                if ($lastProcessedCandle === $candle['openTime']) {
                    return;
                }

                $lastProcessedCandle = $candle['openTime'];

                // Update candle
                $found = false;

                foreach ($candles as $i => $existing) {
                    if ($existing['openTime'] === $candle['openTime']) {
                        $candles[$i] = $candle;
                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    $candles[] = $candle;
                }

                // Keep history
                if (count($candles) > 300) {
                    $candles = array_slice($candles, -300);
                }

                // Calculate Supertrend
                $st = calculateSupertrend(
                    $candles,
                    $config['atrPeriod'],
                    $config['multiplier']
                );

                $index = count($candles) - 1;

                if (!isset($st[$index])) {
                    return;
                }

                $current = $st[$index];

                // Save current trend
                $state = loadState();

                $state['trend'] = $current['direction'];
                $state['lastCandle'] = $candle['openTime'];

                saveState($state);

                botLog(
                    'CANDLE CLOSED'
                    . ' | ' . $config['symbol']
                    . ' | CLOSE=' . $candle['close']
                    . ' | ATR=' . number_format($current['atr'], 2)
                    . ' | ST=' . number_format($current['up'] ?? $current['dn'], 2)
                    . ' | ' . $current['direction']
                );

                // BUY
                if ($current['buySignal']) {

                    botLog('******** BUY SIGNAL ********');

                    $state = loadState();
                    $state['lastSignal'] = 'BUY';
                    saveState($state);

                    executeOrder('BUY');
                }

                // SELL
                if ($current['sellSignal']) {

                    botLog('******** SELL SIGNAL ********');

                    $state = loadState();
                    $state['lastSignal'] = 'SELL';
                    saveState($state);

                    executeOrder('SELL');
                }
            });

            $conn->on('close', function () {

                botLog('Market WebSocket disconnected.');

                Loop::addTimer(3, 'connectMarketWebSocket');
            });
        },

        function (Exception $e) {

            botLog('Market WS ERROR: ' . $e->getMessage());

            Loop::addTimer(3, 'connectMarketWebSocket');
        }
    );
}


/*
|--------------------------------------------------------------------------
| START
|--------------------------------------------------------------------------
*/

initializeDatabase();

$state = loadState();
$state['symbol'] = $config['symbol'];
saveState($state);

if (!$config['dryRun']) {

    if (empty($config['apiKey']) || empty($config['secretKey'])) {
        botLog('FATAL: BINANCE_API_KEY / BINANCE_SECRET_KEY are required when DRY_RUN=false.');
        exit(1);
    }

    try {
        $symbolFilters = fetchSymbolFilters($config['symbol']);

        botLog(
            'Symbol filters loaded: stepSize=' . $symbolFilters['stepSize']
            . ' tickSize=' . $symbolFilters['tickSize']
        );

    } catch (Throwable $e) {
        botLog('FATAL: could not load symbol filters: ' . $e->getMessage());
        exit(1);
    }

} else {

    // Sane dry-run defaults so quantity math still works without a key.
    $symbolFilters = [
        'stepSize' => '0.001',
        'minQty' => 0.0,
        'qtyPrecision' => 3,
        'tickSize' => '0.1',
        'pricePrecision' => 1,
    ];
}

if (!initializeTradingSettings()) {
    botLog('FATAL: Binance trading settings initialization failed.');
    exit(1);
}

botLog('========================================');
botLog('BINANCE SUPERTREND BOT');
botLog('Symbol: ' . $config['symbol']);
botLog('Timeframe: ' . $config['interval']);
botLog('ATR: ' . $config['atrPeriod']);
botLog('Multiplier: ' . $config['multiplier']);
botLog('Source: (H + L) / 2');
botLog('Margin Type: ' . $config['marginType']);
botLog('Leverage: ' . $config['leverage'] . 'x');
botLog('Take Profit: ' . $config['takeProfitPercent'] . '%');
botLog('Stop Loss: ' . $config['stopLossPercent'] . '%');
botLog('Base Margin: $' . number_format($config['baseMargin'], 2));
botLog('Martingale: ' . $config['martingaleMultiplier'] . 'x (max level ' . $config['maxMartingaleLevel'] . ')');
botLog('DRY RUN: ' . ($config['dryRun'] ? 'YES' : 'NO'));
botLog('========================================');


/*
|--------------------------------------------------------------------------
| INITIAL HISTORY
|--------------------------------------------------------------------------
*/

$raw = getHistoricalKlines($config['symbol'], $config['interval'], 200);

foreach ($raw as $k) {
    $candles[] = convertKline($k);
}

// Remove currently open candle
$now = (int)(microtime(true) * 1000);

$candles = array_values(array_filter(
    $candles,
    function ($c) use ($now) {
        return $c['closeTime'] < $now;
    }
));

botLog('Historical closed candles: ' . count($candles));


/*
|--------------------------------------------------------------------------
| START WEBSOCKETS
|--------------------------------------------------------------------------
*/

connectOrderWebSocket();
connectUserDataWebSocket();
connectMarketWebSocket();


/*
|--------------------------------------------------------------------------
| RUN FOREVER
|--------------------------------------------------------------------------
*/

Loop::get()->run();