<?php

namespace SupertrendBot;

/**
 * All plain REST (curl) calls to Binance Futures live here:
 * signing, prices, klines, exchange filters, margin/leverage
 * setup, order cancellation, and listenKey management.
 *
 * Order *placement* itself goes over the ws-fapi websocket and
 * is handled by OrderManager - this class is REST only.
 */
class BinanceClient
{
    private const BASE_URL = 'https://fapi.binance.com';

    private Config $config;
    private Logger $logger;

    public function __construct(Config $config, Logger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    public static function createSignature(array $params, string $secret): string
    {
        ksort($params);

        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        return hash_hmac('sha256', $query, $secret);
    }

    public function signedRequest(string $method, string $endpoint, array $params): array
    {
        $params['timestamp'] = (int)(microtime(true) * 1000);
        $params['recvWindow'] = 5000;
        $params['signature'] = self::createSignature($params, $this->config->secretKey);

        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $method = strtoupper($method);

        if ($method === 'GET' || $method === 'DELETE') {
            $url = self::BASE_URL . $endpoint . '?' . $query;
            $body = null;
        } else {
            $url = self::BASE_URL . $endpoint;
            $body = $query;
        }

        return $this->curl($method, $url, $body, true);
    }

    public function apiKeyRequest(string $method, string $endpoint): array
    {
        $url = self::BASE_URL . $endpoint;

        return $this->curl(strtoupper($method), $url, null, true);
    }

    public function publicRequest(string $url): array
    {
        return $this->curl('GET', $url, null, false);
    }

    private function curl(string $method, string $url, ?string $body, bool $withApiKeyHeader): array
    {
        $ch = curl_init($url);

        $headers = [];

        if ($withApiKeyHeader) {
            $headers[] = 'X-MBX-APIKEY: ' . $this->config->apiKey;
        }

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 15,
        ];

        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($ch, $opts);

        $response = curl_exec($ch);

        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException($err);
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        $data = json_decode($response, true);

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new \RuntimeException("Binance API error $httpCode: $response");
        }

        return is_array($data) ? $data : [];
    }

    public function getLatestPrice(): float
    {
        $url = self::BASE_URL . '/fapi/v1/ticker/price?symbol=' . urlencode($this->config->symbol);

        $data = $this->publicRequest($url);

        if (!isset($data['price'])) {
            throw new \RuntimeException('Invalid Binance price response');
        }

        return (float)$data['price'];
    }

    public function getHistoricalKlines(string $symbol, string $interval, int $limit = 200): array
    {
        $url = self::BASE_URL . '/fapi/v1/klines'
            . '?symbol=' . urlencode($symbol)
            . '&interval=' . urlencode($interval)
            . '&limit=' . $limit;

        $data = $this->publicRequest($url);

        if (!is_array($data)) {
            throw new \RuntimeException('Invalid Binance response');
        }

        return $data;
    }

    /**
     * Fetches the LOT_SIZE / PRICE_FILTER for a symbol so quantities
     * and prices we send are actually valid. Different symbols have
     * different step/tick sizes - this must never be hardcoded.
     */
    public function fetchSymbolFilters(string $symbol): array
    {
        $url = self::BASE_URL . '/fapi/v1/exchangeInfo';

        $data = $this->publicRequest($url);

        $symbolInfo = null;

        foreach (($data['symbols'] ?? []) as $s) {
            if ($s['symbol'] === $symbol) {
                $symbolInfo = $s;
                break;
            }
        }

        if ($symbolInfo === null) {
            throw new \RuntimeException("Symbol $symbol not found in exchangeInfo");
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
            'qtyPrecision' => self::decimalsFromStep($stepSize),
            'tickSize' => $tickSize,
            'pricePrecision' => self::decimalsFromStep($tickSize),
        ];
    }

    public static function decimalsFromStep(string $step): int
    {
        $step = rtrim($step, '0');

        if (strpos($step, '.') === false) {
            return 0;
        }

        return strlen(substr($step, strpos($step, '.') + 1));
    }

    public function setMarginType(): bool
    {
        $marginType = strtoupper($this->config->marginType);

        if (!in_array($marginType, ['ISOLATED', 'CROSSED'], true)) {
            throw new \InvalidArgumentException('MARGIN_TYPE must be ISOLATED or CROSSED');
        }

        try {

            $this->signedRequest('POST', '/fapi/v1/marginType', [
                'symbol' => $this->config->symbol,
                'marginType' => $marginType,
            ]);

            $this->logger->info('Margin Type set: ' . $marginType);

            return true;

        } catch (\Throwable $e) {

            // -4046 = No need to change margin type (already set).
            if (strpos($e->getMessage(), '-4046') !== false) {
                $this->logger->info('Margin Type already set: ' . $marginType);
                return true;
            }

            $this->logger->info('ERROR setting margin type: ' . $e->getMessage());

            return false;
        }
    }

    public function setLeverage(): bool
    {
        $leverage = (int)$this->config->leverage;

        if ($leverage < 1 || $leverage > 125) {
            throw new \InvalidArgumentException('Invalid leverage: ' . $leverage);
        }

        try {

            $this->signedRequest('POST', '/fapi/v1/leverage', [
                'symbol' => $this->config->symbol,
                'leverage' => $leverage,
            ]);

            $this->logger->info('Leverage set: ' . $leverage . 'x');

            return true;

        } catch (\Throwable $e) {

            $this->logger->info('ERROR setting leverage: ' . $e->getMessage());

            return false;
        }
    }

    public function initializeTradingSettings(): bool
    {
        if ($this->config->dryRun) {
            $this->logger->info('DRY RUN: skipping margin/leverage configuration.');
            return true;
        }

        $this->logger->info('Configuring Binance Futures trading settings...');

        if (!$this->setMarginType()) {
            $this->logger->info('ERROR: Could not configure margin type.');
            return false;
        }

        if (!$this->setLeverage()) {
            $this->logger->info('ERROR: Could not configure leverage.');
            return false;
        }

        $this->logger->info('Trading settings configured successfully.');

        return true;
    }

    /**
     * Manual "OCO": when TP or SL fills, we cancel the other one
     * ourselves via REST. -2011 (unknown order) means it already
     * filled or was already cancelled - that's an expected race,
     * not an error.
     */
    public function cancelOrder(string $symbol, $orderId): void
    {
        if ($this->config->dryRun) {
            return;
        }

        try {

            $this->signedRequest('DELETE', '/fapi/v1/order', [
                'symbol' => $symbol,
                'orderId' => $orderId,
            ]);

            $this->logger->info("Cancelled sibling order #$orderId");

        } catch (\Throwable $e) {

            if (strpos($e->getMessage(), '-2011') !== false) {
                $this->logger->info("Sibling order #$orderId already gone (race with fill), OK.");
                return;
            }

            $this->logger->info("WARNING: failed to cancel order #$orderId: " . $e->getMessage());
        }
    }

    public function getListenKey(): string
    {
        $data = $this->apiKeyRequest('POST', '/fapi/v1/listenKey');

        if (!isset($data['listenKey'])) {
            throw new \RuntimeException('Failed to obtain listenKey');
        }

        return $data['listenKey'];
    }

    public function keepAliveListenKey(): void
    {
        try {
            $this->apiKeyRequest('PUT', '/fapi/v1/listenKey');
            $this->logger->info('listenKey keep-alive sent.');
        } catch (\Throwable $e) {
            $this->logger->info('WARNING: listenKey keep-alive failed: ' . $e->getMessage());
        }
    }
}
