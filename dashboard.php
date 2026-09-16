<?php

/**
 * Read-only dashboard. Reuses the same StateStore class the bot
 * itself writes with, so this always reflects exactly what's in
 * data/state.json and data/trades.jsonl - no separate database,
 * no writes from this file at all.
 *
 * Open in a browser while bot.php is running, e.g.:
 *   http://localhost/supertrend-bot/dashboard.php
 */

require __DIR__ . '/src/StateStore.php';

use SupertrendBot\StateStore;

$state = new StateStore(__DIR__ . '/data/state.json', __DIR__ . '/data/trades.jsonl');
$s = $state->load();

$trades = [];
$tradesFile = __DIR__ . '/data/trades.jsonl';

if (file_exists($tradesFile)) {
    foreach (file($tradesFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $t = json_decode($line, true);
        if (is_array($t)) {
            $trades[] = $t;
        }
    }
}

$totalTrades = (int)($s['totalTrades'] ?? 0);
$wins = (int)($s['wins'] ?? 0);
$losses = (int)($s['losses'] ?? 0);
$winRate = $totalTrades > 0 ? round(($wins / $totalTrades) * 100, 1) : 0;
$realizedPnl = (float)($s['realizedPnl'] ?? 0);

$recentTrades = array_reverse($trades);

// Cumulative PnL, in chronological order, for the chart.
$cumulative = 0;
$chartLabels = [];
$chartData = [];

foreach ($trades as $i => $t) {
    $cumulative += (float)($t['realizedPnl'] ?? 0);
    $chartLabels[] = $i + 1;
    $chartData[] = round($cumulative, 4);
}

$pos = $s['position'] ?? 'NONE';
$posClass = $pos === 'LONG' ? 'long' : ($pos === 'SHORT' ? 'short' : 'none');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta http-equiv="refresh" content="15">
<title>Supertrend Bot Dashboard</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<style>
  :root{
    --bg:#0b0f14; --card:#131a22; --border:#22303c; --text:#e6edf3; --muted:#8b98a5;
    --green:#3fb950; --red:#f85149; --accent:#58a6ff;
  }
  *{box-sizing:border-box;}
  body{margin:0;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:var(--bg);color:var(--text);padding:24px;}
  h1{font-size:20px;margin:0 0 4px;}
  .sub{color:var(--muted);font-size:13px;margin-bottom:24px;}
  .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:24px;}
  .card{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:16px;}
  .card .label{font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px;}
  .card .value{font-size:24px;font-weight:600;}
  .pos{color:var(--green);} .neg{color:var(--red);}
  .badge{display:inline-block;padding:2px 10px;border-radius:6px;font-size:14px;font-weight:600;}
  .badge.long{background:rgba(63,185,80,.15);color:var(--green);}
  .badge.short{background:rgba(248,81,73,.15);color:var(--red);}
  .badge.none{background:rgba(139,148,165,.15);color:var(--muted);}
  table{width:100%;border-collapse:collapse;background:var(--card);border:1px solid var(--border);border-radius:10px;overflow:hidden;}
  th,td{padding:10px 14px;text-align:left;font-size:13px;border-bottom:1px solid var(--border);}
  th{color:var(--muted);font-weight:600;text-transform:uppercase;font-size:11px;letter-spacing:.05em;}
  tr:last-child td{border-bottom:none;}
  .win{color:var(--green);font-weight:600;} .loss{color:var(--red);font-weight:600;}
  .chart-wrap{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:16px;margin-bottom:24px;height:260px;}
  .section-title{font-size:14px;color:var(--muted);margin:0 0 12px;text-transform:uppercase;letter-spacing:.05em;}
  .empty{color:var(--muted);}
</style>
</head>
<body>

<h1>Supertrend Bot &mdash; <?= htmlspecialchars($s['symbol'] ?? '?') ?></h1>
<div class="sub">Auto-refreshes every 15s &middot; Last loaded <?= date('Y-m-d H:i:s') ?></div>

<div class="grid">
  <div class="card">
    <div class="label">Win Rate</div>
    <div class="value <?= $winRate >= 50 ? 'pos' : ($totalTrades > 0 ? 'neg' : '') ?>"><?= $winRate ?>%</div>
  </div>
  <div class="card">
    <div class="label">Total Trades</div>
    <div class="value"><?= $totalTrades ?></div>
  </div>
  <div class="card">
    <div class="label">Wins / Losses</div>
    <div class="value"><span class="pos"><?= $wins ?></span> / <span class="neg"><?= $losses ?></span></div>
  </div>
  <div class="card">
    <div class="label">Realized PnL</div>
    <div class="value <?= $realizedPnl >= 0 ? 'pos' : 'neg' ?>"><?= ($realizedPnl >= 0 ? '+' : '') . number_format($realizedPnl, 4) ?></div>
  </div>
  <div class="card">
    <div class="label">Position</div>
    <div class="value"><span class="badge <?= $posClass ?>"><?= htmlspecialchars($pos) ?></span></div>
  </div>
  <div class="card">
    <div class="label">Martingale Level</div>
    <div class="value"><?= (int)($s['martingaleLevel'] ?? 0) ?></div>
  </div>
  <div class="card">
    <div class="label">Trend</div>
    <div class="value"><?= htmlspecialchars($s['trend'] ?? '—') ?></div>
  </div>
  <div class="card">
    <div class="label">Last Signal</div>
    <div class="value"><?= htmlspecialchars($s['lastSignal'] ?? '—') ?></div>
  </div>
</div>

<div class="chart-wrap">
  <div class="section-title">Cumulative Realized PnL</div>
  <canvas id="pnlChart"></canvas>
</div>

<div class="section-title">Recent Trades</div>
<table>
  <thead>
    <tr><th>Time</th><th>Result</th><th>Closed By</th><th>Realized PnL</th><th>Next Martingale Level</th></tr>
  </thead>
  <tbody>
    <?php if (empty($recentTrades)): ?>
      <tr><td colspan="5" class="empty">No closed trades yet.</td></tr>
    <?php else: foreach (array_slice($recentTrades, 0, 50) as $t): ?>
      <tr>
        <td><?= htmlspecialchars($t['time'] ?? '') ?></td>
        <td class="<?= ($t['result'] ?? '') === 'WIN' ? 'win' : 'loss' ?>"><?= htmlspecialchars($t['result'] ?? '') ?></td>
        <td><?= htmlspecialchars($t['closedBy'] ?? '') ?></td>
        <td class="<?= ($t['realizedPnl'] ?? 0) >= 0 ? 'pos' : 'neg' ?>"><?= number_format((float)($t['realizedPnl'] ?? 0), 4) ?></td>
        <td><?= (int)($t['nextMartingaleLevel'] ?? 0) ?></td>
      </tr>
    <?php endforeach; endif; ?>
  </tbody>
</table>

<script>
const ctx = document.getElementById('pnlChart');
new Chart(ctx, {
  type: 'line',
  data: {
    labels: <?= json_encode($chartLabels) ?>,
    datasets: [{
      label: 'Cumulative PnL',
      data: <?= json_encode($chartData) ?>,
      borderColor: '#58a6ff',
      backgroundColor: 'rgba(88,166,255,0.1)',
      fill: true,
      tension: 0.2,
      pointRadius: 2,
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
      x: { ticks: { color: '#8b98a5' }, grid: { color: '#22303c' }, title: { display: true, text: 'Trade #', color: '#8b98a5' } },
      y: { ticks: { color: '#8b98a5' }, grid: { color: '#22303c' } }
    }
  }
});
</script>

</body>
</html>
