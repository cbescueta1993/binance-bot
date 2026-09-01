<?php

namespace SupertrendBot;

/**
 * Pure indicator math - no network, no filesystem, no state.
 * Mirrors TradingView's ta.atr() (Wilder RMA) and the standard
 * Supertrend (hl2 source) so it lines up with a Pine chart.
 */
class Supertrend
{
    public static function convertKline(array $k): array
    {
        return [
            'openTime' => (int)$k[0],
            'open' => (float)$k[1],
            'high' => (float)$k[2],
            'low' => (float)$k[3],
            'close' => (float)$k[4],
            'volume' => (float)$k[5],
            'closeTime' => (int)$k[6],
        ];
    }

    public static function trueRange(array $current, ?array $previous): float
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

    // Pine: ta.atr() - Wilder RMA smoothing
    public static function calculateATR(array $candles, int $period): array
    {
        $count = count($candles);

        $atr = [];

        if ($count < $period) {
            return $atr;
        }

        $tr = [];

        for ($i = 0; $i < $count; $i++) {
            $previous = $i > 0 ? $candles[$i - 1] : null;
            $tr[$i] = self::trueRange($candles[$i], $previous);
        }

        // Initial SMA seed
        $sum = 0;

        for ($i = 0; $i < $period; $i++) {
            $sum += $tr[$i];
        }

        $atr[$period - 1] = $sum / $period;

        // Wilder RMA
        for ($i = $period; $i < $count; $i++) {
            $atr[$i] = (($atr[$i - 1] * ($period - 1)) + $tr[$i]) / $period;
        }

        return $atr;
    }

    public static function calculate(array $candles, int $period = 10, float $multiplier = 3): array
    {
        $count = count($candles);

        $atr = self::calculateATR($candles, $period);

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
                'sellSignal' => $sellSignal,
            ];
        }

        return $result;
    }
}
