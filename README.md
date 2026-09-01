# Binance Supertrend + Martingale Futures Bot

## Setup

```bash
composer install
cp .env.example .env
# edit .env with your API key/secret and settings
export $(grep -v '^#' .env | xargs)
php bot.php
```

Always leave `DRY_RUN=true` until you've watched the logs for a while
and are comfortable with the position sizing. Nothing is sent to
Binance in dry run - it only logs what it *would* have done.

## File layout

```
bot.php               Thin bootstrap: wires classes together, starts the loop
src/Config.php         Reads settings from environment variables
src/Logger.php         Writes timestamped lines to console + data/bot.log
src/StateStore.php      Reads/writes data/state.json and data/trades.jsonl
src/BinanceClient.php   All REST calls (signing, prices, klines, exchange
                        filters, margin/leverage setup, cancel, listenKey)
src/Supertrend.php      Pure ATR + Supertrend indicator math, no I/O
src/MarketStream.php    The market-data websocket: candles in, BUY/SELL
                        signals out (via callback). Knows nothing about orders.
src/OrderManager.php    The order websocket + user-data websocket: entry
                        placement, fill confirmation, TP/SL placement,
                        martingale sizing, manual OCO cancellation
```

`MarketStream` and `OrderManager` only talk to each other through the
callback passed in from `bot.php` - neither one calls the other
directly, so you can test or swap either side independently.

## What was fixed from the original version

1. **Position never reset (critical).** The original code had no
   listener for TP/SL fills at all, so `state.position` stayed
   `LONG`/`SHORT` forever after the first trade and `executeOrder()`
   would refuse to trade again. `OrderManager` now opens Binance's
   **user data stream** (`listenKey` websocket) and reacts to real
   `ORDER_TRADE_UPDATE` "FILLED" events - the only reliable way to
   know an order actually executed, since an `order.place`
   acknowledgement just means "accepted", not "filled".

2. **Martingale never advanced.** `martingaleLevel` and
   `consecutiveLosses` existed in state but nothing updated them.
   They now increment on a stop-loss fill and reset to 0 on a
   take-profit fill, in `OrderManager::handleProtectionFilled()`.

3. **Martingale safety cap added.** `MAX_MARTINGALE_LEVEL` (default 4)
   stops the position size from doubling forever through a losing
   streak - it resets to level 0 instead of continuing past the cap.
   This is a real risk control; understand it before removing it.

4. **Manual OCO.** When TP or SL fills, the code now cancels the
   sibling order via REST instead of leaving it live (which could
   otherwise trigger a second, unwanted close).

5. **`$symbol` bug.** The market websocket referenced an undefined
   global `$symbol` instead of `$config['symbol']`, so the stream URL
   was built from `null`. Fixed.

6. **Hardcoded precision.** Quantity and TP/SL price used fixed
   decimal counts that only happen to work for BTCUSDT. The bot now
   fetches the symbol's real `LOT_SIZE`/`PRICE_FILTER` from
   `/fapi/v1/exchangeInfo` at startup and rounds accordingly
   (`BinanceClient::fetchSymbolFilters`).

7. **TP/SL now use `closePosition=true`** instead of a hand-tracked
   quantity + `reduceOnly`, so there's no chance of a quantity
   mismatch between what the bot thinks the position is and what
   Binance actually holds.

## A note on the strategy itself

Martingale position sizing (doubling size after a loss) is high risk
by construction: a losing streak grows your exposure exponentially,
and a long enough streak can wipe an account regardless of how good
the underlying signal is. The safety cap above limits *how far* it
doubles, but doesn't make the approach low-risk - that's inherent to
martingale sizing, not something a code fix can remove. Size
`BASE_MARGIN` and `LEVERAGE` accordingly, and don't run this
unattended with money you can't afford to lose.

## Changelog (this update)

1. **Timezone fix.** Log timestamps were showing 6 hours off from the
   real clock (PHP has no OS-default timezone - it silently used its
   own default, usually UTC, instead of matching the machine). Now
   set explicitly via `Config::$timezone` / the `TIMEZONE` env var,
   applied before anything logs. Defaults to `Asia/Manila` if unset.

2. **Watchdog for the market data websocket.** If no message arrives
   for 90 seconds (kline streams normally push updates roughly every
   second, even mid-candle), the connection is force-closed and
   reconnected. This is what catches a silently-dead connection - the
   kind that happens after the host machine sleeps/wakes, or a
   network path drops packets without a clean TCP close - which
   otherwise leaves the bot frozen indefinitely with no error and no
   reconnect attempt.

3. **Scheduled refresh reconnect for the order and user-data
   websockets.** These can go long stretches with zero traffic when
   nothing is trading, so a "silence = dead" check would misfire
   constantly. Instead they force a clean reconnect every 4 hours on
   a fixed schedule, so a connection that quietly died never lingers
   as an undetected zombie.

4. **`.bat` file: `MARGIN_TYPE=CROSS` -> `CROSSED`.** Binance's API
   only accepts `ISOLATED` or `CROSSED`; `CROSS` would have thrown an
   error the moment `DRY_RUN` was switched to `false`. Harmless in
   dry run, since that check is skipped entirely there.

### Windows / sleep mode note

If your PC goes to sleep, Windows suspends the whole PHP process and
its network connections along with it. The watchdog above will force
a reconnect once you're back and the bot notices the silence, but the
cleanest fix is to prevent sleep entirely while the bot needs to run:
**Settings -> System -> Power & battery -> Screen and sleep** -> set
"sleep" to Never while plugged in. For anything you intend to run
unattended for real, a small always-on VPS avoids this class of
problem altogether.

## Market data mode: poll vs websocket

`MARKET_DATA_MODE` controls how candle data is fetched:

- **`poll` (default)** — fetches candles via a plain REST GET
  (`/fapi/v1/klines`) every `POLL_INTERVAL_SECONDS` (default 15).
  Slightly slower to react to a candle close (up to ~15s of lag) but
  uses the exact same REST endpoint that already works reliably for
  historical data and price lookups. Use this if the websocket
  connects but never receives data - some networks (observed:
  certain ISPs, even across home WiFi/mobile hotspot/VPN) silently
  drop the actual WebSocket data frames after completing the
  handshake, which looks like "CONNECTED" but nothing ever arrives.

- **`websocket`** — the original persistent `wss://` kline stream.
  Reacts to a candle close almost instantly, but only switch to this
  once you've confirmed with `test-connection.php` that `MSG` lines
  actually appear (not just "CONNECTED" followed by silence).

Both modes run through the exact same signal/indicator logic
(`MarketStream::processCandle()`), so switching between them never
changes trading behavior - only how quickly a closed candle is seen.

**Important caveat if you go live:** order placement
(`OrderManager::connectOrderWebSocket()`) and fill confirmation
(`connectUserDataWebSocket()`) still require persistent WebSocket
connections to `ws-fapi.binance.com` / `fstream.binance.com` - there
is no REST equivalent for those. If your network silently blocks
WebSocket data the same way it did for market data, going live will
hit the same wall even with `MARKET_DATA_MODE=poll`. If that turns
out to be the case, running the bot from a VPS outside the affected
network is the durable fix for the trading side, not just monitoring.
