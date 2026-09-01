<?php

namespace SupertrendBot;

/**
 * Reads/writes the bot's persisted state (state.json) and
 * appends closed trades to trades.jsonl. This is the only
 * class that touches those two files.
 */
class StateStore
{
    private string $stateFile;
    private string $tradesFile;

    public function __construct(string $stateFile, string $tradesFile)
    {
        $this->stateFile = $stateFile;
        $this->tradesFile = $tradesFile;

        $dir = dirname($stateFile);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (!file_exists($stateFile)) {
            $this->save($this->defaultState());
        }

        if (!file_exists($tradesFile)) {
            touch($tradesFile);
        }
    }

    private function defaultState(): array
    {
        return [
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
            'realizedPnl' => 0,
        ];
    }

    public function load(): array
    {
        if (!file_exists($this->stateFile)) {
            $this->save($this->defaultState());
        }

        $json = file_get_contents($this->stateFile);

        $state = json_decode($json, true);

        if (!is_array($state)) {
            throw new \RuntimeException('Invalid state.json');
        }

        return $state;
    }

    public function save(array $state): void
    {
        file_put_contents(
            $this->stateFile,
            json_encode($state, JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }

    public function logTrade(array $trade): void
    {
        file_put_contents(
            $this->tradesFile,
            json_encode($trade) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }
}
