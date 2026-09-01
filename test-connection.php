<?php

/**
 * STANDALONE CONNECTION TEST
 * --------------------------
 * Does nothing except connect to Binance's public kline stream and
 * print every message it receives, plus a heartbeat every 10 seconds
 * showing how long it's been since the last message arrived.
 *
 * This uses NONE of the bot's own code (no OrderManager, no
 * MarketStream, no watchdog) - if THIS also goes silent or drops,
 * the problem is your network/machine/antivirus, not the bot.
 *
 * Run it from the same folder as the bot (it reuses vendor/):
 *   php test-connection.php
 *
 * Let it run for at least 10-15 minutes and watch for gaps.
 * Press Ctrl+C to stop it.
 */

require __DIR__ . '/vendor/autoload.php';

use Ratchet\Client\Connector;
use React\EventLoop\Loop;
use React\Socket\Connector as ReactConnector;

$symbol = 'btcusdt';
$interval = '1m'; // 1-minute candles so you don't have to wait 5 min to see activity

$url = "wss://fstream.binance.com/ws/{$symbol}@kline_{$interval}";

$lastMessageAt = time();
$messageCount = 0;

function ts(): string
{
    return '[' . date('Y-m-d H:i:s') . ']';
}

echo ts() . " Connecting to $url ...\n";

$connector = new Connector(Loop::get(), new ReactConnector(['timeout' => 10]));

$connector($url)->then(

    function ($conn) use (&$lastMessageAt, &$messageCount) {

        echo ts() . " CONNECTED. Waiting for messages (should arrive every ~1-2s)...\n";
        $lastMessageAt = time();

        $conn->on('message', function ($msg) use (&$lastMessageAt, &$messageCount) {
            $lastMessageAt = time();
            $messageCount++;

            $data = json_decode((string)$msg, true);
            $close = $data['k']['c'] ?? '?';
            $isClosed = ($data['k']['x'] ?? false) ? 'CLOSED' : 'forming';

            echo ts() . " msg #$messageCount | close=$close | candle $isClosed\n";
        });

        $conn->on('close', function ($code = null, $reason = null) {
            echo ts() . " CLOSED by remote/local. code=" . var_export($code, true)
                . " reason=" . var_export($reason, true) . "\n";
        });
    },

    function (\Exception $e) {
        echo ts() . " CONNECTION ERROR: " . $e->getMessage() . "\n";
    }
);

// Heartbeat: print silence duration every 10s so gaps are obvious
// even if you're not staring at the screen when they happen.
Loop::addPeriodicTimer(10, function () use (&$lastMessageAt) {
    $silentFor = time() - $lastMessageAt;
    echo ts() . " (heartbeat) last message was {$silentFor}s ago\n";
});

Loop::get()->run();
