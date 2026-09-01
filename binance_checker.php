<?php
/**
 * Binance COIN-M Futures Minimum Amount Checker
 * Queries: https://dapi.binance.com/dapi/v1/exchangeInfo
 */

// Helper function to make cURL requests to Binance API
function callBinanceApi($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['error' => 'cURL Error: ' . $err];
    }
    
    return json_decode($response, true);
}

// Fetch Exchange Info & Ticker Prices
$exchangeInfoUrl = 'https://dapi.binance.com/dapi/v1/exchangeInfo';
$tickerPriceUrl = 'https://dapi.binance.com/dapi/v1/premiumIndex';

$exchangeData = callBinanceApi($exchangeInfoUrl);
$tickerData = callBinanceApi($tickerPriceUrl);

// Map prices by symbol for quick lookup
$prices = [];
if (is_array($tickerData)) {
    foreach ($tickerData as $ticker) {
        if (isset($ticker['symbol'], $ticker['markPrice'])) {
            $prices[$ticker['symbol']] = $ticker['markPrice'];
        }
    }
}

// Handle search/filter selection
$selectedSymbol = $_GET['symbol'] ?? 'BTCUSD_PERP';
$symbolDetails = null;
$calculatedMinUsd = 0;

if (isset($exchangeData['symbols']) && is_array($exchangeData['symbols'])) {
    foreach ($exchangeData['symbols'] as $sym) {
        if ($sym['symbol'] === $selectedSymbol) {
            $symbolDetails = $sym;
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Binance COIN-M Futures Minimum Amount Inquiry</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <div class="card shadow-sm">
                <div class="card-header bg-dark text-white">
                    <h4 class="mb-0">Binance COIN-M Futures - Minimum Order Inquiry</h4>
                </div>
                <div class="card-body">
                    
                    <!-- Search Form -->
                    <form method="GET" class="row g-3 mb-4">
                        <div class="col-md-9">
                            <label for="symbol" class="form-label">Select COIN-M Contract Symbol:</label>
                            <select name="symbol" id="symbol" class="form-select">
                                <?php if (isset($exchangeData['symbols'])): ?>
                                    <?php foreach ($exchangeData['symbols'] as $s): ?>
                                        <?php if ($s['contractStatus'] === 'TRADING'): ?>
                                            <option value="<?= htmlspecialchars($s['symbol']) ?>" <?= ($selectedSymbol === $s['symbol']) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($s['symbol']) ?> (Base: <?= htmlspecialchars($s['baseAsset']) ?>)
                                            </option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <option value="">Unable to connect to Binance API</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">Inquire Limits</button>
                        </div>
                    </form>

                    <?php if (isset($exchangeData['error'])): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($exchangeData['error']) ?></div>
                    <?php endif; ?>

                    <!-- Results Section -->
                    <?php if ($symbolDetails): ?>
                        <hr>
                        <h5 class="text-secondary mb-3">Specifications for: <span class="text-dark"><?= htmlspecialchars($symbolDetails['symbol']) ?></span></h5>
                        
                        <?php 
                            $minQty = 0;
                            $stepSize = 0;
                            $minNotional = 0;

                            if (isset($symbolDetails['filters'])) {
                                foreach ($symbolDetails['filters'] as $filter) {
                                    if ($filter['filterType'] === 'LOT_SIZE') {
                                        $minQty = $filter['minQty'] ?? 0;
                                        $stepSize = $filter['stepSize'] ?? 0;
                                    }
                                    if ($filter['filterType'] === 'MIN_NOTIONAL') {
                                        $minNotional = $filter['notional'] ?? ($filter['minNotional'] ?? 0);
                                    }
                                }
                            }

                            $markPrice = $prices[$selectedSymbol] ?? 0;
                            // COIN-M contracts are inverse contracts valued in USD (e.g., 1 contract = $100 worth of crypto)
                            // Contract value calculation approximation based on mark price
                            $contractSize = $symbolDetails['contractSize'] ?? 100;
                        ?>

                        <div class="row g-3">
                            <div class="col-md-4">
                                <div class="p-3 border bg-white rounded">
                                    <small class="text-muted d-block">Base Asset</small>
                                    <span class="fs-5 fw-bold"><?= htmlspecialchars($symbolDetails['baseAsset']) ?></span>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="p-3 border bg-white rounded">
                                    <small class="text-muted d-block">Current Mark Price</small>
                                    <span class="fs-5 fw-bold">$<?= number_format((float)$markPrice, 2) ?></span>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="p-3 border bg-white rounded">
                                    <small class="text-muted d-block">Contract Size Face Value</small>
                                    <span class="fs-5 fw-bold">$<?= htmlspecialchars($contractSize) ?> USD</span>
                                </div>
                            </div>
                        </div>

                        <div class="table-responsive mt-4">
                            <table class="table table-bordered table-striped">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Filter Rule / Parameter</th>
                                        <th>API Value</th>
                                        <th>Description</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><strong>Minimum Quantity (minQty)</strong></td>
                                        <td><code><?= htmlspecialchars($minQty) ?> contracts</code></td>
                                        <td>Absolute minimum number of contracts required per order.</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Step Size (stepSize)</strong></td>
                                        <td><code><?= htmlspecialchars($stepSize) ?></code></td>
                                        <td>Interval limits specifying how quantity can increment.</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Quantity Precision</strong></td>
                                        <td><code><?= htmlspecialchars($symbolDetails['quantityPrecision']) ?></code></td>
                                        <td>Decimal places allowed for order sizing.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="alert alert-info mt-3 mb-0">
                            <strong>Note on COIN-M Contracts: Unlike USDT-M, COIN-M contracts represent USD value (typically $100 per contract for inverses like BTCUSD). Ensure your calculated quantity satisfies the `minQty` filter specifications above before executing order requests.</strong>
                        </div>
                    <?php endif; ?>

                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>