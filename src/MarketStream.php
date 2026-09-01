<?php

namespace SupertrendBot;

use Ratchet\Client\Connector;
use Ratchet\Client\WebSocket;
use React\EventLoop\Loop;
use React\Socket\Connector as ReactConnector;

/**
 * Owns the kline (candlestick) market data websocket. Keeps a
 * rolling candle buffer, runs the Supertrend indicator on every
 * closed candle, and fires the onSignal callback with 'BUY' or
 * 'SELL' when a flip happens. Knows nothing about orders.
 */
class MarketStream
{
    private Config $config;
    private Logger $logger;
    private StateStore $state;
    private BinanceClient $client;

    /** @var callable(string $signal): void */
    private $onSignal;

    /**
     * If no message at all (not just a closed candle - kline
     * streams push an update roughly every second even mid-candle)
     * has arrived in this many seconds, the connection is treated
     * as dead and force-reconnected. This is what catches a
     * WebSocket that never fires a 'close' event - e.g. after the
     * host machine sleeps/wakes, or a silent network drop - which
     * would otherwise leave the bot frozen indefinitely with no
     * error and no reconnect attempt.
     */
    private const MAX_SILENCE_SECONDS = 90;
    private const WATCHDOG_CHECK_INTERVAL = 30;

    private array $candles = [];
    private ?int $lastProcessedCandle = null;
    private int $lastMessageAt = 0;
    private $watchdogTimer = null;

    public function __construct(
        Config $config,
        Logger $logger,
        StateStore $state,
        BinanceClient $client,
        callable $onSignal
    ) {
        $this->config = $config;
        $this->logger = $logger;
        $this->state = $state;
        $this->client = $client;
        $this->onSignal = $onSignal;
    }

    public function loadHistory(int $limit = 200): void
    {
        $raw = $this->client->getHistoricalKlines(
            $this->config->symbol,
            $this->config->interval,
            $limit
        );

        foreach ($raw as $k) {
            $this->candles[] = Supertrend::convertKline($k);
        }

        // Remove the currently-open (unclosed) candle.
        $now = (int)(microtime(true) * 1000);

        $this->candles = array_values(array_filter(
            $this->candles,
            fn($c) => $c['closeTime'] < $now
        ));

        $this->logger->info('Historical closed candles: ' . count($this->candles));
    }

    public function start(): void
    {
        if ($this->config->marketDataMode === 'poll') {
            $this->startPolling();
        } else {
            $this->connect();
        }
    }

    /**
     * Fetches candles via a plain REST GET on a timer instead of the
     * persistent kline websocket. Slower (up to pollIntervalSeconds
     * of lag on a close) but uses the exact same endpoint that
     * already works reliably for historical data and price lookups,
     * for environments where the wss:// stream itself is silently
     * blocked (observed: connects, then zero data ever arrives).
     */
    public function startPolling(): void
    {
        $this->logger->info(
            'Market data mode: POLL (REST every '
            . $this->config->pollIntervalSeconds . 's)'
        );

        $poll = function () {

            try {

                $raw = $this->client->getHistoricalKlines(
                    $this->config->symbol,
                    $this->config->interval,
                    2
                );

            } catch (\Throwable $e) {
                $this->logger->info('POLL ERROR: ' . $e->getMessage());
                return;
            }

            $now = (int)(microtime(true) * 1000);

            foreach ($raw as $k) {

                $candle = Supertrend::convertKline($k);

                // Only ever process candles that have actually closed.
                if ($candle['closeTime'] >= $now) {
                    continue;
                }

                $this->processCandle($candle);
            }
        };

        // Run once immediately, then on the configured interval.
        $poll();

        Loop::addPeriodicTimer($this->config->pollIntervalSeconds, $poll);
    }

    public function connect(): void
    {
        $connector = new Connector(Loop::get(), new ReactConnector(['timeout' => 10]));

        $streamSymbol = strtolower($this->config->symbol);

        $url = 'wss://fstream.binance.com/market/ws/' . $streamSymbol . '@kline_' . $this->config->interval;

        $this->logger->info('Connecting Market WebSocket...');

        $connector($url)->then(

            function (WebSocket $conn) {

                $this->logger->info('Market WebSocket CONNECTED.');

                $this->lastMessageAt = time();

                $conn->on('message', function ($message) {
                    $this->lastMessageAt = time();
                    $this->handleWebSocketMessage((string)$message);
                });

                $this->watchdogTimer = Loop::addPeriodicTimer(
                    self::WATCHDOG_CHECK_INTERVAL,
                    function () use ($conn) {

                        $silentFor = time() - $this->lastMessageAt;

                        if ($silentFor > self::MAX_SILENCE_SECONDS) {

                            $this->logger->info(
                                "WATCHDOG: no market data received in {$silentFor}s "
                                . '(machine sleep or dead network link?) - forcing reconnect.'
                            );

                            // This triggers the 'close' handler below,
                            // which schedules the actual reconnect.
                            $conn->close();
                        }
                    }
                );

                $conn->on('close', function () {

                    if ($this->watchdogTimer !== null) {
                        Loop::cancelTimer($this->watchdogTimer);
                        $this->watchdogTimer = null;
                    }

                    $this->logger->info('Market WebSocket disconnected.');
                    Loop::addTimer(3, fn() => $this->connect());
                });
            },

            function (\Exception $e) {
                $this->logger->info('Market WS ERROR: ' . $e->getMessage());
                Loop::addTimer(3, fn() => $this->connect());
            }
        );
    }

    private function handleWebSocketMessage(string $message): void
    {
        $data = json_decode($message, true);

        if (!$data || !isset($data['k'])) {
            return;
        }

        $k = $data['k'];

        // Only act on a CLOSED candle (x = true).
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
            'closeTime' => (int)$k['T'],
        ];

        $this->processCandle($candle);
    }

    /**
     * Shared by both the websocket path and the REST-polling path -
     * whichever one delivers a closed candle, the indicator/signal
     * logic behaves identically.
     */
    private function processCandle(array $candle): void
    {
        // Prevent duplicate processing.
        if ($this->lastProcessedCandle === $candle['openTime']) {
            return;
        }

        $this->lastProcessedCandle = $candle['openTime'];

        $found = false;

        foreach ($this->candles as $i => $existing) {
            if ($existing['openTime'] === $candle['openTime']) {
                $this->candles[$i] = $candle;
                $found = true;
                break;
            }
        }

        if (!$found) {
            $this->candles[] = $candle;
        }

        // Keep history bounded.
        if (count($this->candles) > 300) {
            $this->candles = array_slice($this->candles, -300);
        }

        $st = Supertrend::calculate($this->candles, $this->config->atrPeriod, $this->config->multiplier);

        $index = count($this->candles) - 1;

        if (!isset($st[$index])) {
            return;
        }

        $current = $st[$index];

        $state = $this->state->load();
        $state['trend'] = $current['direction'];
        $state['lastCandle'] = $candle['openTime'];
        $this->state->save($state);

        $this->logger->info(
            'CANDLE CLOSED'
            . ' | ' . $this->config->symbol
            . ' | CLOSE=' . $candle['close']
            . ' | ATR=' . number_format($current['atr'], 2)
            . ' | ST=' . number_format($current['up'] ?? $current['dn'], 2)
            . ' | ' . $current['direction']
        );

        if ($current['buySignal']) {

            $this->logger->info('******** BUY SIGNAL ********');

            $state = $this->state->load();
            $state['lastSignal'] = 'BUY';
            $this->state->save($state);

            ($this->onSignal)('BUY');
        }

        if ($current['sellSignal']) {

            $this->logger->info('******** SELL SIGNAL ********');

            $state = $this->state->load();
            $state['lastSignal'] = 'SELL';
            $this->state->save($state);

            ($this->onSignal)('SELL');
        }
    }
}
