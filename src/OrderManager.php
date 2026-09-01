<?php

namespace SupertrendBot;

use Ratchet\Client\Connector;
use Ratchet\Client\WebSocket;
use React\EventLoop\Loop;
use React\Socket\Connector as ReactConnector;

/**
 * Owns everything about placing and tracking orders:
 *  - the ws-fapi order-entry websocket (order.place acks)
 *  - the user-data-stream websocket (actual FILLED events)
 *  - martingale position sizing
 *  - manual "OCO" between TP and SL (cancel the sibling on fill)
 *
 * IMPORTANT: an order.place acknowledgement (status 200) only
 * means Binance accepted the order, not that it filled. TP/SL
 * orders (STOP_MARKET / TAKE_PROFIT_MARKET) can sit unfilled for
 * a long time. The ONLY reliable fill signal is the user data
 * stream's ORDER_TRADE_UPDATE event with status FILLED, which is
 * why this class opens that stream and reacts to it rather than
 * assuming the order.place ack means "done".
 */
class OrderManager
{
    private Config $config;
    private Logger $logger;
    private StateStore $state;
    private BinanceClient $client;

    private ?array $symbolFilters = null;

    private $orderWs = null;
    private bool $orderWsConnected = false;

    private ?string $listenKey = null;

    /** Requests sent via order.place, awaiting acknowledgement. Keyed by request id. */
    private array $pendingRequests = [];

    /** Orders Binance has acknowledged, awaiting a FILLED event. Keyed by Binance orderId. */
    private array $openOrders = [];

    /** tpOrderId <-> slOrderId, so filling one lets us cancel the other. */
    private array $protectionPairs = [];

    /** Holds partial {tp,sl} orderIds while we wait for both acks. Keyed by entry orderId. */
    private array $pendingProtection = [];

    /**
     * The order-entry and user-data sockets can go long stretches with
     * no traffic at all when nothing is trading, so a "silence means
     * dead" check (like MarketStream uses) would false-positive
     * constantly. Instead, force a clean reconnect on a fixed schedule
     * so a connection that died silently (sleep, dropped network,
     * etc.) never lingers as a zombie indefinitely.
     */
    private const MAX_CONNECTION_AGE_SECONDS = 4 * 3600;

    private $orderWsAgeTimer = null;
    private $userDataAgeTimer = null;

    public function __construct(Config $config, Logger $logger, StateStore $state, BinanceClient $client)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->state = $state;
        $this->client = $client;
    }

    public function setSymbolFilters(array $filters): void
    {
        $this->symbolFilters = $filters;
    }

    /*
    |--------------------------------------------------------------------
    | Sizing helpers
    |--------------------------------------------------------------------
    */

    /** Rounds DOWN to the nearest valid step - never round up quantity. */
    public static function roundToStep(float $value, string $stepSize): float
    {
        $step = (float)$stepSize;

        if ($step <= 0) {
            return $value;
        }

        return floor($value / $step) * $step;
    }

    public static function roundToTick(float $value, string $tickSize): float
    {
        $tick = (float)$tickSize;

        if ($tick <= 0) {
            return $value;
        }

        return round($value / $tick) * $tick;
    }

    public static function formatDecimal(float $value, int $precision): string
    {
        return number_format($value, $precision, '.', '');
    }

    public static function calculateOrderQuantity(float $margin, int $leverage, float $price): float
    {
        if ($margin <= 0) {
            throw new \InvalidArgumentException('Margin must be greater than zero.');
        }

        if ($leverage <= 0) {
            throw new \InvalidArgumentException('Leverage must be greater than zero.');
        }

        if ($price <= 0) {
            throw new \InvalidArgumentException('Price must be greater than zero.');
        }

        return ($margin * $leverage) / $price;
    }

    public static function calculateMartingaleMargin(float $baseMargin, float $multiplier, int $level): float
    {
        return $baseMargin * pow($multiplier, $level);
    }

    public static function calculateTPSL(
        string $side,
        float $entryPrice,
        float $tpPercent,
        float $slPercent
    ): array {

        $tpPercent /= 100;
        $slPercent /= 100;

        if ($side === 'BUY') {
            $takeProfit = $entryPrice * (1 + $tpPercent);
            $stopLoss = $entryPrice * (1 - $slPercent);
        } else {
            $takeProfit = $entryPrice * (1 - $tpPercent);
            $stopLoss = $entryPrice * (1 + $slPercent);
        }

        return ['takeProfit' => $takeProfit, 'stopLoss' => $stopLoss];
    }

    public static function getExitSide(string $entrySide): string
    {
        return $entrySide === 'BUY' ? 'SELL' : 'BUY';
    }

    private function getTradeMargin(): float
    {
        $state = $this->state->load();

        $level = (int)$state['martingaleLevel'];

        return self::calculateMartingaleMargin(
            $this->config->baseMargin,
            $this->config->martingaleMultiplier,
            $level
        );
    }

    /*
    |--------------------------------------------------------------------
    | Entry
    |--------------------------------------------------------------------
    */

    public function executeOrder(string $signal): void
    {
        $state = $this->state->load();
        $targetSide = ($signal === 'BUY') ? 'BUY' : 'SELL';

        // If a position is already open
        if ($state['position'] !== 'NONE') {
            // If the signal is in the same direction, ignore it
            if ($state['positionSide'] === $targetSide) {
                $this->logger->info(
                    'Position already exists in the same direction: ' . $state['position'] . ' ' . $state['positionSide']
                );
                return;
            }

            // Opposite signal detected: Close and Reverse!
            $this->logger->info("REVERSE SIGNAL: Current position is {$state['positionSide']}, new signal is $targetSide. Closing existing position early.");
            
            if (!$this->config->dryRun) {
                $this->closeExistingPositionAndReverse($targetSide, $signal);
                return;
            } else {
                $this->logger->info('DRY RUN - would close existing position and reverse to ' . $targetSide);
                return;
            }
        }

        // Standard entry logic when no position is open
        $this->placeNewEntry($signal, $targetSide);
    }

    /**
     * Helper to open a fresh entry order (extracted from original executeOrder)
     */
    private function placeNewEntry(string $signal, string $side): void
    {
        $state = $this->state->load();
        $currentPrice = $this->client->getLatestPrice();
        $margin = $this->getTradeMargin();

        $rawQuantity = self::calculateOrderQuantity($margin, $this->config->leverage, $currentPrice);

        $qtyPrecision = $this->symbolFilters['qtyPrecision'] ?? 3;
        $stepSize = $this->symbolFilters['stepSize'] ?? '0.001';
        $minQty = $this->symbolFilters['minQty'] ?? 0.0;

        $quantity = self::roundToStep($rawQuantity, $stepSize);

        if ($quantity <= 0 || $quantity < $minQty) {
            $this->logger->info(
                "ERROR: computed quantity ($rawQuantity -> $quantity) is below exchange minimum ($minQty) for {$this->config->symbol}. Skipping."
            );
            return;
        }

        $quantityString = self::formatDecimal($quantity, $qtyPrecision);

        $protection = self::calculateTPSL(
            $side, $currentPrice,
            $this->config->takeProfitPercent, $this->config->stopLossPercent
        );

        $this->logger->info(
            "SIGNAL: $signal | SIDE: $side | PRICE: $currentPrice"
            . ' | MARGIN: $' . number_format($margin, 2)
            . ' | LEVERAGE: ' . $this->config->leverage . 'x'
            . " | QTY: $quantityString"
            . ' | TP: ' . $protection['takeProfit']
            . ' | SL: ' . $protection['stopLoss']
            . ' | MARTINGALE LEVEL: ' . $state['martingaleLevel']
        );

        if (!$this->orderWsConnected || $this->orderWs === null) {
            $this->logger->info('ERROR: Binance order WebSocket is not connected.');
            return;
        }

        $requestId = bin2hex(random_bytes(16));

        $params = [
            'apiKey' => $this->config->apiKey,
            'symbol' => $this->config->symbol,
            'side' => $side,
            'type' => 'MARKET',
            'quantity' => $quantityString,
            'timestamp' => (int)(microtime(true) * 1000),
            'recvWindow' => 5000,
        ];

        $params['signature'] = BinanceClient::createSignature($params, $this->config->secretKey);

        $message = json_encode([
            'id' => $requestId,
            'method' => 'order.place',
            'params' => $params,
        ]);

        $this->pendingRequests[$requestId] = [
            'type' => 'ENTRY',
            'signal' => $signal,
            'side' => $side,
            'quantity' => $quantityString,
            'estimatedPrice' => $currentPrice,
            'time' => time(),
        ];

        $this->orderWs->send($message);
        $this->logger->info("ENTRY ORDER SENT: $side $quantityString {$this->config->symbol}");
    }

    /**
     * Closes current active position early using a market order with closePosition=true
     */
    private function closeExistingPositionAndReverse(string $targetSide, string $signal): void
    {
        $state = $this->state->load();
        $currentPositionSide = $state['positionSide'];
        $exitSide = self::getExitSide($currentPositionSide);

        // Cancel any pending protection sibling orders first
        // Binance futures allows clearing open orders or sending a market close with closePosition=true
        $requestId = bin2hex(random_bytes(16));

        $params = [
            'apiKey' => $this->config->apiKey,
            'symbol' => $this->config->symbol,
            'side' => $exitSide,
            'type' => 'MARKET',
            'closePosition' => 'true', // Closes the entire position immediately via market order
            'timestamp' => (int)(microtime(true) * 1000),
            'recvWindow' => 5000,
        ];

        $params['signature'] = BinanceClient::createSignature($params, $this->config->secretKey);

        $message = json_encode([
            'id' => $requestId,
            'method' => 'order.place',
            'params' => $params,
        ]);

        $this->pendingRequests[$requestId] = [
            'type' => 'EARLY_CLOSE_AND_REVERSE',
            'nextSignal' => $signal,
            'nextSide' => $targetSide,
            'time' => time(),
        ];

        $this->orderWs->send($message);
        $this->logger->info("EARLY CLOSE SENT: Market order to close {$state['position']} position.");
    }

    /*
    |--------------------------------------------------------------------
    | Fill handling (driven by the user data stream)
    |--------------------------------------------------------------------
    */

    private function handleEntryFilled(int $orderId, array $pending, float $entryPrice): void
    {
        $this->logger->info("ENTRY FILLED: #$orderId @ $entryPrice");

        $state = $this->state->load();
        $state['position'] = $pending['side'] === 'BUY' ? 'LONG' : 'SHORT';
        $state['positionSide'] = $pending['side'];
        $state['quantity'] = (float)$pending['quantity'];
        $state['entryPrice'] = $entryPrice;
        $state['lastOrderId'] = $orderId;
        $state['lastOrderTime'] = time();
        $this->state->save($state);

        if (!$this->orderWsConnected || $this->orderWs === null) {
            $this->logger->info('ERROR: order WS down, cannot place TP/SL! Manual intervention needed.');
            return;
        }

        $protection = self::calculateTPSL(
            $pending['side'], $entryPrice,
            $this->config->takeProfitPercent, $this->config->stopLossPercent
        );

        $exitSide = self::getExitSide($pending['side']);

        $pricePrecision = $this->symbolFilters['pricePrecision'] ?? 2;
        $tickSize = $this->symbolFilters['tickSize'] ?? '0.1';

        $tpPrice = self::roundToTick($protection['takeProfit'], $tickSize);
        $slPrice = self::roundToTick($protection['stopLoss'], $tickSize);

        $tp = $this->buildProtectionOrder($exitSide, 'TAKE_PROFIT_MARKET', $tpPrice, $pricePrecision);
        $sl = $this->buildProtectionOrder($exitSide, 'STOP_MARKET', $slPrice, $pricePrecision);

        $this->pendingRequests[$tp['requestId']] = [
            'type' => 'TP',
            'entrySide' => $pending['side'],
            'entryOrderId' => $orderId,
            'price' => $tpPrice,
        ];

        $this->pendingRequests[$sl['requestId']] = [
            'type' => 'SL',
            'entrySide' => $pending['side'],
            'entryOrderId' => $orderId,
            'price' => $slPrice,
        ];

        $this->pendingProtection[$orderId] = ['tpOrderId' => null, 'slOrderId' => null];

        $this->orderWs->send($tp['message']);
        $this->logger->info('TP SENT: ' . $tpPrice);

        $this->orderWs->send($sl['message']);
        $this->logger->info('SL SENT: ' . $slPrice);
    }

    /**
     * TP/SL close the ENTIRE position (closePosition=true) rather than
     * a hand-tracked quantity, so there's no risk of a quantity mismatch
     * between what we think the position is and what Binance holds.
     * closePosition implies reduceOnly, so it isn't sent separately.
     */
    private function buildProtectionOrder(
        string $side,
        string $type,
        float $stopPrice,
        int $pricePrecision
    ): array {

        $requestId = bin2hex(random_bytes(16));

        $params = [
            'apiKey' => $this->config->apiKey,
            'symbol' => $this->config->symbol,
            'side' => $side,
            'type' => $type,
            'stopPrice' => self::formatDecimal($stopPrice, $pricePrecision),
            'closePosition' => 'true',
            'workingType' => 'MARK_PRICE',
            'timestamp' => (int)(microtime(true) * 1000),
            'recvWindow' => 5000,
        ];

        $params['signature'] = BinanceClient::createSignature($params, $this->config->secretKey);

        $message = json_encode([
            'id' => $requestId,
            'method' => 'order.place',
            'params' => $params,
        ]);

        return ['requestId' => $requestId, 'message' => $message];
    }

    private function handleProtectionFilled(int $orderId, array $order, float $realizedPnl): void
    {
        $type = $order['type'];
        $isWin = $type === 'TP';

        $this->logger->info(
            ($isWin ? 'TAKE PROFIT' : 'STOP LOSS') . " HIT: #$orderId | Realized PnL: $realizedPnl"
        );

        // Manual OCO: cancel the sibling order.
        if (isset($this->protectionPairs[$orderId])) {

            $siblingId = $this->protectionPairs[$orderId];

            $this->client->cancelOrder($this->config->symbol, $siblingId);

            unset($this->protectionPairs[$siblingId], $this->protectionPairs[$orderId]);
        }

        $state = $this->state->load();

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

            if ($level > $this->config->maxMartingaleLevel) {

                $this->logger->info(
                    'MARTINGALE SAFETY CAP REACHED (level ' . $level . ' > max '
                    . $this->config->maxMartingaleLevel
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

        $this->state->save($state);

        $this->state->logTrade([
            'time' => date('c'),
            'symbol' => $this->config->symbol,
            'result' => $isWin ? 'WIN' : 'LOSS',
            'closedBy' => $type,
            'orderId' => $orderId,
            'realizedPnl' => $realizedPnl,
            'nextMartingaleLevel' => $level,
        ]);
    }

    /*
    |--------------------------------------------------------------------
    | Order-entry websocket (ws-fapi order.place acknowledgements only)
    |--------------------------------------------------------------------
    */

    public function connectOrderWebSocket(): void
    {
        if ($this->config->dryRun) {
            $this->logger->info('DRY RUN: order WebSocket disabled.');
            return;
        }

        $connector = new Connector(Loop::get(), new ReactConnector(['timeout' => 10]));

        $url = 'wss://ws-fapi.binance.com/ws-fapi/v1';

        $this->logger->info('Connecting Binance Order WebSocket...');

        $connector($url)->then(

            function (WebSocket $conn) {

                $this->orderWs = $conn;
                $this->orderWsConnected = true;

                $this->logger->info('Binance Order WebSocket CONNECTED.');

                $conn->on('message', function ($message) {
                    $this->handleOrderMessage((string)$message);
                });

                $this->orderWsAgeTimer = Loop::addTimer(
                    self::MAX_CONNECTION_AGE_SECONDS,
                    function () use ($conn) {
                        $this->logger->info('Order WebSocket: scheduled refresh reconnect.');
                        $conn->close();
                    }
                );

                $conn->on('close', function () {

                    if ($this->orderWsAgeTimer !== null) {
                        Loop::cancelTimer($this->orderWsAgeTimer);
                        $this->orderWsAgeTimer = null;
                    }

                    $this->orderWs = null;
                    $this->orderWsConnected = false;
                    $this->logger->info('Order WebSocket disconnected.');
                    Loop::addTimer(3, fn() => $this->connectOrderWebSocket());
                });
            },

            function (\Exception $e) {
                $this->orderWs = null;
                $this->orderWsConnected = false;
                $this->logger->info('Order WebSocket ERROR: ' . $e->getMessage());
                Loop::addTimer(3, fn() => $this->connectOrderWebSocket());
            }
        );
    }

    private function handleOrderMessage(string $message): void
    {
        $data = json_decode($message, true);

        if (!is_array($data) || !isset($data['id']) || !isset($this->pendingRequests[$data['id']])) {
            return;
        }

        $id = $data['id'];
        $pending = $this->pendingRequests[$id];

        if (!isset($data['status']) || $data['status'] !== 200) {

            $this->logger->info('ORDER REJECTED (' . $pending['type'] . '): ' . json_encode($data));

            unset($this->pendingRequests[$id]);
            return;
        }

        $result = $data['result'] ?? [];
        $orderId = $result['orderId'] ?? null;

        if ($orderId === null) {
            $this->logger->info('WARNING: order ack with no orderId: ' . $message);
            unset($this->pendingRequests[$id]);
            return;
        }

        if ($pending['type'] === 'ENTRY') {

            /*
             * Just record it. The real fill confirmation (and the
             * decision to place TP/SL) happens off the user data
             * stream's ORDER_TRADE_UPDATE event, not here.
             */
            $this->openOrders[$orderId] = array_merge($pending, ['type' => 'ENTRY']);

            $this->logger->info("ENTRY ORDER ACCEPTED: #$orderId (awaiting fill confirmation)");

        } elseif ($pending['type'] === 'TP' || $pending['type'] === 'SL') {

            $this->openOrders[$orderId] = $pending;

            $entryOrderId = $pending['entryOrderId'];

            if (isset($this->pendingProtection[$entryOrderId])) {

                if ($pending['type'] === 'TP') {
                    $this->pendingProtection[$entryOrderId]['tpOrderId'] = $orderId;
                } else {
                    $this->pendingProtection[$entryOrderId]['slOrderId'] = $orderId;
                }

                $tpId = $this->pendingProtection[$entryOrderId]['tpOrderId'];
                $slId = $this->pendingProtection[$entryOrderId]['slOrderId'];

                if ($tpId !== null && $slId !== null) {
                    $this->protectionPairs[$tpId] = $slId;
                    $this->protectionPairs[$slId] = $tpId;
                    unset($this->pendingProtection[$entryOrderId]);
                }
            }

            $this->logger->info("{$pending['type']} ORDER ACCEPTED: #$orderId @ {$pending['price']}");
        }

        unset($this->pendingRequests[$id]);
    }

    /*
    |--------------------------------------------------------------------
    | User data stream (this is where fills are actually confirmed)
    |--------------------------------------------------------------------
    */

    public function connectUserDataWebSocket(): void
    {
        if ($this->config->dryRun) {
            $this->logger->info('DRY RUN: user data WebSocket disabled.');
            return;
        }

        try {
            $this->listenKey = $this->client->getListenKey();
        } catch (\Throwable $e) {
            $this->logger->info('ERROR getting listenKey: ' . $e->getMessage());
            Loop::addTimer(5, fn() => $this->connectUserDataWebSocket());
            return;
        }

        $connector = new Connector(Loop::get(), new ReactConnector(['timeout' => 10]));

        $url = 'wss://fstream.binance.com/ws/' . $this->listenKey;

        $this->logger->info('Connecting Binance User Data WebSocket...');

        $connector($url)->then(

            function (WebSocket $conn) {

                $this->logger->info('User Data WebSocket CONNECTED.');

                // Binance requires a PUT at least every 60 minutes; do it every 30.
                $keepAliveTimer = Loop::addPeriodicTimer(1800, function () {
                    $this->client->keepAliveListenKey();
                });

                $this->userDataAgeTimer = Loop::addTimer(
                    self::MAX_CONNECTION_AGE_SECONDS,
                    function () use ($conn) {
                        $this->logger->info('User Data WebSocket: scheduled refresh reconnect.');
                        $conn->close();
                    }
                );

                $conn->on('message', function ($message) {
                    $this->handleUserDataMessage((string)$message);
                });

                $conn->on('close', function () use ($keepAliveTimer) {

                    Loop::cancelTimer($keepAliveTimer);

                    if ($this->userDataAgeTimer !== null) {
                        Loop::cancelTimer($this->userDataAgeTimer);
                        $this->userDataAgeTimer = null;
                    }

                    $this->logger->info('User Data WebSocket disconnected.');
                    Loop::addTimer(3, fn() => $this->connectUserDataWebSocket());
                });
            },

            function (\Exception $e) {
                $this->logger->info('User Data WS ERROR: ' . $e->getMessage());
                Loop::addTimer(3, fn() => $this->connectUserDataWebSocket());
            }
        );
    }

    private function handleUserDataMessage(string $message): void
    {
        $data = json_decode($message, true);

        if (!is_array($data) || !isset($data['e']) || $data['e'] !== 'ORDER_TRADE_UPDATE') {
            return;
        }

        $o = $data['o'] ?? null;

        if ($o === null || !isset($o['i'], $o['X']) || $o['X'] !== 'FILLED') {
            return;
        }

        $orderId = (int)$o['i'];

        if (!isset($this->openOrders[$orderId])) {
            // Fill for an order we're not tracking - ignore.
            return;
        }

        $order = $this->openOrders[$orderId];

        $avgPrice = isset($o['ap']) ? (float)$o['ap'] : 0.0;
        $lastPrice = isset($o['L']) ? (float)$o['L'] : 0.0;
        $fillPrice = $avgPrice > 0 ? $avgPrice : $lastPrice;

        $realizedPnl = isset($o['rp']) ? (float)$o['rp'] : 0.0;

        if ($order['type'] === 'ENTRY') {
            $this->handleEntryFilled($orderId, $order, $fillPrice);
        } elseif ($order['type'] === 'TP' || $order['type'] === 'SL') {
            $this->handleProtectionFilled($orderId, $order, $realizedPnl);
        }

        unset($this->openOrders[$orderId]);
    }
}
