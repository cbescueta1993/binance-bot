<?php

/**
 * ENVIRONMENT + GENERIC WEBSOCKET TEST
 * -------------------------------------
 * Step 1: prints PHP/OpenSSL/cURL versions so we can see what
 *         XAMPP's bundled PHP is actually using.
 * Step 2: connects to a well-known PUBLIC echo websocket server
 *         (nothing to do with Binance at all) and sends it a
 *         message every few seconds, printing whatever comes back.
 *
 * If THIS also fails/goes silent/gets abruptly closed, the problem
 * is this PC's PHP/SSL/network stack in general - not anything
 * about Binance specifically.
 *
 * Run it the same way as the other test:
 *   php test-echo.php
 */

require __DIR__ . '/vendor/autoload.php';

use Ratchet\Client\Connector;
use React\EventLoop\Loop;
use React\Socket\Connector as ReactConnector;

function ts(): string
{
    return '[' . date('Y-m-d H:i:s') . ']';
}

echo "=========================================\n";
echo "ENVIRONMENT INFO\n";
echo "=========================================\n";
echo 'PHP version: ' . PHP_VERSION . "\n";
echo 'OpenSSL: ' . (defined('OPENSSL_VERSION_TEXT') ? OPENSSL_VERSION_TEXT : 'not available') . "\n";
echo 'cURL: ' . (function_exists('curl_version') ? curl_version()['version'] : 'not available') . "\n";
echo 'cURL SSL: ' . (function_exists('curl_version') ? curl_version()['ssl_version'] : 'not available') . "\n";
echo "=========================================\n\n";

$url = 'wss://ws.postman-echo.com/raw';

echo ts() . " Connecting to PUBLIC ECHO TEST SERVER: $url ...\n";
echo ts() . " (this has nothing to do with Binance - pure sanity check)\n";

$connector = new Connector(Loop::get(), new ReactConnector(['timeout' => 10]));

$pingCount = 0;

$connector($url)->then(

    function ($conn) use (&$pingCount) {

        echo ts() . " CONNECTED to echo server.\n";

        $conn->on('message', function ($msg) {
            echo ts() . " ECHO RECEIVED: " . (string)$msg . "\n";
        });

        $conn->on('close', function ($code = null, $reason = null) {
            echo ts() . " CLOSED. code=" . var_export($code, true)
                . " reason=" . var_export($reason, true) . "\n";
        });

        // Send a ping every 5 seconds; a working connection echoes it right back.
        Loop::addPeriodicTimer(5, function () use ($conn, &$pingCount) {
            $pingCount++;
            $msg = "ping #$pingCount at " . date('H:i:s');
            echo ts() . " Sending: $msg\n";
            $conn->send($msg);
        });
    },

    function (\Exception $e) {
        echo ts() . " CONNECTION ERROR: " . $e->getMessage() . "\n";
        echo ts() . " Exception class: " . get_class($e) . "\n";
        echo $e->getTraceAsString() . "\n";
    }
);

Loop::get()->run();
