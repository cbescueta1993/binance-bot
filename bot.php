<?php

require __DIR__ . '/vendor/autoload.php';

require __DIR__ . '/src/Config.php';
require __DIR__ . '/src/Logger.php';
require __DIR__ . '/src/StateStore.php';
require __DIR__ . '/src/BinanceClient.php';
require __DIR__ . '/src/Supertrend.php';
require __DIR__ . '/src/MarketStream.php';
require __DIR__ . '/src/OrderManager.php';

use SupertrendBot\Config;
use SupertrendBot\Logger;
use SupertrendBot\StateStore;
use SupertrendBot\BinanceClient;
use SupertrendBot\MarketStream;
use SupertrendBot\OrderManager;
use React\EventLoop\Loop;

$dataDir = __DIR__ . '/data';

$config = Config::fromEnv();

// Must happen before the Logger (or anything else using date()) is
// created, otherwise timestamps default to PHP's own default zone
// (often UTC) instead of matching your actual system clock.
date_default_timezone_set($config->timezone);

$logger = new Logger($dataDir . '/bot.log');
$state = new StateStore($dataDir . '/state.json', $dataDir . '/trades.jsonl');
$client = new BinanceClient($config, $logger);
$orders = new OrderManager($config, $logger, $state, $client);

// Keep the symbol in state.json in sync with current config.
$s = $state->load();
$s['symbol'] = $config->symbol;
$state->save($s);

if (!$config->dryRun) {

    if (empty($config->apiKey) || empty($config->secretKey)) {
        $logger->info('FATAL: BINANCE_API_KEY / BINANCE_SECRET_KEY are required when DRY_RUN=false.');
        exit(1);
    }

    try {

        $filters = $client->fetchSymbolFilters($config->symbol);
        $orders->setSymbolFilters($filters);

        $logger->info(
            'Symbol filters loaded: stepSize=' . $filters['stepSize']
            . ' tickSize=' . $filters['tickSize']
        );

    } catch (Throwable $e) {
        $logger->info('FATAL: could not load symbol filters: ' . $e->getMessage());
        exit(1);
    }

} else {

    // Sane dry-run defaults so quantity math still works without an API key.
    $orders->setSymbolFilters([
        'stepSize' => '0.001',
        'minQty' => 0.0,
        'qtyPrecision' => 3,
        'tickSize' => '0.1',
        'pricePrecision' => 1,
    ]);
}

if (!$client->initializeTradingSettings()) {
    $logger->info('FATAL: Binance trading settings initialization failed.');
    exit(1);
}

$logger->info('========================================');
$logger->info('BINANCE SUPERTREND BOT');
$logger->info('Symbol: ' . $config->symbol);
$logger->info('Timeframe: ' . $config->interval);
$logger->info('ATR: ' . $config->atrPeriod);
$logger->info('Multiplier: ' . $config->multiplier);
$logger->info('Source: (H + L) / 2');
$logger->info('Margin Type: ' . $config->marginType);
$logger->info('Leverage: ' . $config->leverage . 'x');
$logger->info('Take Profit: ' . $config->takeProfitPercent . '%');
$logger->info('Stop Loss: ' . $config->stopLossPercent . '%');
$logger->info('Base Margin: $' . number_format($config->baseMargin, 2));
$logger->info(
    'Martingale: ' . $config->martingaleMultiplier . 'x'
    . ' (max level ' . $config->maxMartingaleLevel . ')'
);
$logger->info('DRY RUN: ' . ($config->dryRun ? 'YES' : 'NO'));
$logger->info('========================================');

$market = new MarketStream(
    $config,
    $logger,
    $state,
    $client,
    function (string $signal) use ($orders) {
        $orders->executeOrder($signal);
    }
);

$market->loadHistory(200);

$orders->connectOrderWebSocket();
$orders->connectUserDataWebSocket();
$market->start();

Loop::get()->run();
