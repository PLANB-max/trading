<?php
// Fehlerbehandlung
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
Helper::logError("🚨 TEST LOG ERROR", ['foo' => 'bar']);


// Konfiguration
define('SYMBOL', 'BTCUSDT');
define('TIMEFRAME', '1H');
define('LIMIT', 1000);
define('PRODUCT_TYPE', 'usdt-futures');
define('API_BASE_URL', 'https://api.bitget.com/api/v2/mix/market/');
define('TELEGRAM_API_URL', 'https://api.telegram.org/bot');
define('USE_OPTIMIZED_PARAMS', true); // auf false setzen, wenn du die feste Strategie testen willst


// [2.] NEU: Diese zwei Zeilen EINFÜGEN (nach den 'define'-Anweisungen)
require_once __DIR__.'/ml_trader.php';
$mlTrader = new MLTrader();

// Indikator-Einstellungen (werden durch Backtesting optimiert)
class IndicatorSettings {
	public static $rsiAngleThresholdLong = 10;    // minimaler Winkel für LONG
	public static $rsiAngleThresholdShort = -10;  // maximaler Winkel für SHORT
	public static $atrPeriod = 14; // Für dynamische SL/TP
	public static $riskRewardRatio = 2; // Standard 1:2 RR
    public static $rsiOverbought = 70;  //70
    public static $rsiOversold = 30;	//30
    public static $smaPeriods = [20, 50, 200];
    public static $bbPeriod = 20;
    public static $bbMultiplier = 2;
    public static $macdSettings = [
        'shortPeriod' => 12,
        'longPeriod' => 26,
        'signalPeriod' => 9
    ];
}



// Backtesting-Einstellungen
class BacktestConfig {
    public static $periods = [
        'short' => 30,
        'medium' => 90,
        'long' => 180
    ];
    public static $riskPerTrade = 0.01;
    public static $optimizationIterations = 100;
    public static $confidenceThreshold = 0.4; //0.65
    public static $monteCarloIterations = 500;
	public static $tradingFees = 0.0004; // 0.04% pro Trade
	public static $slippage = 0.0005; // 0.05% Slippage
}

// Hilfsfunktionen
class Helper {
    public static function logError($message, $context = []) {
        $logFile = 'error.log';
        $timestamp = date("Y-m-d H:i:s");
        $logMessage = "[$timestamp] ❌ $message\n";
        if (!empty($context)) {
            $logMessage .= "Context: " . json_encode($context, JSON_PRETTY_PRINT) . "\n";
        }
        file_put_contents($logFile, $logMessage, FILE_APPEND);
    }

    public static function logInfo($message, $context = []) {
        $logFile = 'error.log';
        $timestamp = date("Y-m-d H:i:s");
        $logMessage = "[$timestamp] ℹ️ $message\n";
        if (!empty($context)) {
            $logMessage .= "Context: " . json_encode($context, JSON_PRETTY_PRINT) . "\n";
        }
        file_put_contents($logFile, $logMessage, FILE_APPEND);
    }

    public static function createApiContext($timeout = 10) {
        return stream_context_create([
            'http' => [
                'timeout' => $timeout,
                'ignore_errors' => true
            ]
        ]);
    }

    public static function standardDeviation(array $values): float {
        $n = count($values);
        if ($n < 2) return 0.0;
        
        $mean = array_sum($values) / $n;
        $squares = array_map(fn($x) => pow($x - $mean, 2), $values);
        return sqrt(array_sum($squares) / ($n - 1)); // Sample standard deviation
    }
}


// API-Kommunikation
class BitgetAPI {
public static function getCandles($symbol, $timeframe, $limit, $productType) {
    $url = API_BASE_URL . "candles?symbol=$symbol&granularity=$timeframe&limit=$limit&productType=$productType";
    $context = Helper::createApiContext();
    
    try {
        $response = file_get_contents($url, false, $context);
        if ($response === false) {
            throw new Exception("API request failed");
        }

        $apiResponse = json_decode($response, true);

        // ✅ Hier der richtige Platz für dein Log
        Helper::logError("Empfangene Rohdaten", [
            'candles_count' => count($apiResponse['data'] ?? [])
        ]);

        return $apiResponse;
    } catch (Exception $e) {
        Helper::logError("Fehler beim Abrufen der Candlestick-Daten", [
            'url' => $url,
            'error' => $e->getMessage()
        ]);
        return false;
    }
}


    public static function getMarkPrice($symbol, $productType) {
        $url = API_BASE_URL . "symbol-price?productType=$productType&symbol=$symbol";
        $context = Helper::createApiContext();
        
        try {
            $response = file_get_contents($url, false, $context);
            if ($response === false) {
                throw new Exception("API request failed");
            }
            $data = json_decode($response, true);
            return (float)$data['data'][0]['markPrice'] ?? false;
        } catch (Exception $e) {
            Helper::logError("Fehler beim Abrufen des Mark Price", [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}




// Telegram-Kommunikation
class TelegramBot {
    public static function sendMessage($message) {
        $url = TELEGRAM_API_URL . TelegramConfig::$botToken . "/sendMessage";
        $data = [
            'chat_id' => TelegramConfig::$chatID,
            'text' => $message,
            'parse_mode' => TelegramConfig::$parseMode
        ];

        $options = [
            'http' => [
                'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                'method' => 'POST',
                'content' => http_build_query($data),
            ],
        ];
        
        $context = stream_context_create($options);
        
        try {
            $result = file_get_contents($url, false, $context);
            if ($result === false) {
                throw new Exception("Telegram API request failed");
            }
            return true;
        } catch (Exception $e) {
            Helper::logError("Fehler beim Senden der Telegram-Nachricht", [
                'message' => $message,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}

// Technische Indikatoren
class TechnicalIndicators {
    public static function calculateRSI($closes, $period = 14) {
        if (count($closes) < $period + 1) return null;

        $gains = $losses = [];
        for ($i = 1; $i < count($closes); $i++) {
            $change = $closes[$i] - $closes[$i - 1];
            $gains[] = max(0, $change);
            $losses[] = abs(min(0, $change));
        }

        $avgGain = array_sum(array_slice($gains, 0, $period)) / $period;
        $avgLoss = array_sum(array_slice($losses, 0, $period)) / $period;

        for ($i = $period; $i < count($gains); $i++) {
            $avgGain = (($avgGain * ($period - 1)) + $gains[$i]) / $period;
            $avgLoss = (($avgLoss * ($period - 1)) + $losses[$i]) / $period;
        }

        if ($avgLoss == 0) return 100;

        $rs = $avgGain / $avgLoss;
        return 100 - (100 / (1 + $rs));
    }
	
	// In der TechnicalIndicators-Klasse wurde calculateATR hinzugefügt:
	public static function calculateATR($candles, $period = 14) {
    	$trueRanges = [];
   	 for ($i = 1; $i < count($candles); $i++) {
        $high = $candles[$i]['high'];
        $low = $candles[$i]['low'];
        $prevClose = $candles[$i-1]['close'];
        
        $tr1 = $high - $low;
        $tr2 = abs($high - $prevClose);
        $tr3 = abs($low - $prevClose);
        $trueRanges[] = max($tr1, $tr2, $tr3);
    }
    
    if (count($trueRanges) < $period) return null;
    
    $atr = array_sum(array_slice($trueRanges, 0, $period)) / $period;
    for ($i = $period; $i < count($trueRanges); $i++) {
        $atr = (($atr * ($period - 1)) + $trueRanges[$i]) / $period;
    }
    
    return $atr;
}


    public static function calculateRSIAngle($rsiValues) {
        if (!is_array($rsiValues) || count($rsiValues) < 2) return null;
        
        $firstValue = (float)$rsiValues[0];
        $lastValue = (float)end($rsiValues);
        $deltaY = $lastValue - $firstValue;
        $deltaX = count($rsiValues) - 1;

        $angle = rad2deg(atan2($deltaY, $deltaX));
        return round(max(-90, min(90, $angle)), 2);
    }

    public static function calculateFibonacciRetracements($high, $low) {
        $levels = [0.236, 0.382, 0.5, 0.618, 0.786];
        $range = $high - $low;
        $retracements = [];

        foreach ($levels as $level) {
            $key = (string)($level * 100);
            $retracements[$key] = $high - ($range * $level);
        }

        return $retracements;
    }

    public static function calculateOBV($closes, $volumes) {
        if (count($closes) != count($volumes)) return [];
        
        $obv = [$volumes[0]];
        for ($i = 1; $i < count($closes); $i++) {
            $obv[$i] = $obv[$i - 1] + 
                ($closes[$i] > $closes[$i - 1] ? $volumes[$i] : 
                ($closes[$i] < $closes[$i - 1] ? -$volumes[$i] : 0));
        }
        return $obv;
    }

    public static function calculateEMA($prices, $period) {
        if (count($prices) < $period) return [];
        
        $multiplier = 2 / ($period + 1);
        $ema = [$prices[0]];

        for ($i = 1; $i < count($prices); $i++) {
            $ema[] = ($prices[$i] - $ema[$i - 1]) * $multiplier + $ema[$i - 1];
        }
        return $ema;
    }

    public static function calculateMACD($closes, $shortPeriod = 12, $longPeriod = 26, $signalPeriod = 9) {
        if (count($closes) < $longPeriod + $signalPeriod) {
            return [
                'macdLine' => [],
                'signalLine' => [],
                'histogram' => []
            ];
        }

        $emaShort = self::calculateEMA($closes, $shortPeriod);
        $emaLong = self::calculateEMA($closes, $longPeriod);

        $macdLine = [];
        $offset = $longPeriod - $shortPeriod;
        $maxIndex = min(count($emaShort) - $offset, count($emaLong));
        
        for ($i = 0; $i < $maxIndex; $i++) {
            $macdLine[] = $emaShort[$i + $offset] - $emaLong[$i];
        }

        $signalLine = self::calculateEMA($macdLine, $signalPeriod);

        $histogram = [];
        $signalOffset = $signalPeriod - 1;
        $maxHistoIndex = min(count($macdLine) - $signalOffset, count($signalLine));
        
        for ($i = 0; $i < $maxHistoIndex; $i++) {
            $histogram[] = $macdLine[$i + $signalOffset] - $signalLine[$i];
        }

        return [
            'macdLine' => $macdLine,
            'signalLine' => $signalLine,
            'histogram' => $histogram
        ];
    }

    public static function calculateSMA($prices, $period) {
        if (count($prices) < $period) return [];

        $sma = [];
        for ($i = $period - 1; $i < count($prices); $i++) {
            $sma[] = array_sum(array_slice($prices, $i - $period + 1, $period)) / $period;
        }
        return $sma;
    }

    public static function calculateBollingerBands($prices, $period, $multiplier) {
        if (count($prices) < $period) return [];

        $bands = [];
        for ($i = $period - 1; $i < count($prices); $i++) {
            $slice = array_slice($prices, $i - $period + 1, $period);
            $sma = array_sum($slice) / $period;
            $stdDev = sqrt(array_sum(array_map(function($x) use ($sma) {
                return pow($x - $sma, 2);
            }, $slice)) / $period);

            $bands[] = [
                'upper' => $sma + ($multiplier * $stdDev),
                'middle' => $sma,
                'lower' => $sma - ($multiplier * $stdDev)
            ];
        }
        return $bands;
    }
	public static function prepareCandlesWithIndicators(array $candlesticks): array {
    $closes = array_column($candlesticks, 'close');
    $volumes = array_column($candlesticks, 'volume');

    // SMAs
    $sma20 = self::calculateSMA($closes, 20);
    $sma50 = self::calculateSMA($closes, 50);
    $sma200 = self::calculateSMA($closes, 200);

    // Bollinger Bänder
    $bb = self::calculateBollingerBands($closes, 20, 2);

    // OBV
    $obv = self::calculateOBV($closes, $volumes);

    // RSI
    for ($i = 14; $i < count($candlesticks); $i++) {
        $slice = array_slice($closes, 0, $i + 1);
        $candlesticks[$i]['rsi'] = self::calculateRSI($slice, 14);
    }

    // MACD
    $macd = self::calculateMACD($closes);
    $macdLength = count($macd['macdLine']);
    $macdOffset = count($candlesticks) - $macdLength;

    for ($i = 0; $i < $macdLength; $i++) {
        $index = $i + $macdOffset;
        $candlesticks[$index]['macdLine'] = $macd['macdLine'][$i] ?? null;
        $candlesticks[$index]['signalLine'] = $macd['signalLine'][$i] ?? null;
        $candlesticks[$index]['histogram'] = $macd['histogram'][$i] ?? null;
    }

    // Fibonacci (einmalig für das gesamte Set)
    $highs = array_column($candlesticks, 'high');
    $lows = array_column($candlesticks, 'low');
    $fib = self::calculateFibonacciRetracements(max($highs), min($lows));

    // Daten in Candles eintragen
    foreach ($candlesticks as $i => &$candle) {
        $candle['sma'] = $sma20[$i - 19] ?? null;
        $candle['sma50'] = $sma50[$i - 49] ?? null;
        $candle['sma200'] = $sma200[$i - 199] ?? null;
        $candle['bb_upper'] = $bb[$i - 19]['upper'] ?? null;
        $candle['bb_middle'] = $bb[$i - 19]['middle'] ?? null;
        $candle['bb_lower'] = $bb[$i - 19]['lower'] ?? null;
        $candle['obv'] = $obv[$i] ?? null;
        $candle['fibonacci'] = $fib;
    }
    unset($candle);

    return $candlesticks;
}

}

// Backtesting und Optimierung
class TradingStrategy {
    private static $optimizedParams = [];

public static function calculateMetrics(array $trades, array $results): array
{
    $returns     = [];
    $exitReasons = [];
    $durations   = [];
    $grossProfit = 0.0;
    $grossLoss   = 0.0;

    // → Startkapital als Ausgangswert für Equity und Peak
    $equity = $results['initial_capital'] ?? 0.0;
    $peak   = $equity;
    $maxDD  = 0.0;

    foreach ($trades as $trade) {
        $p = $trade['profit'] ?? 0.0;
        $equity += $p;
        $returns[]   = $p;
        $durations[] = $trade['duration'] ?? 0;

        $reason = $trade['exit_reason'] ?? ($trade['exitReason'] ?? 'manual');
        $exitReasons[$reason] = ($exitReasons[$reason] ?? 0) + 1;

        if ($p >= 0) {
            $grossProfit += $p;
        } else {
            $grossLoss += abs($p);
        }

        // Drawdown berechnen: Differenz Peak minus aktuelle Equity
        $peak  = max($peak, $equity);
        $maxDD = max($maxDD, $peak - $equity);
    }

    $totalTrades = count($trades);

    // Win-Rate als Bruch (0–1)
    $winRate = $totalTrades > 0
        ? (array_sum(array_map(fn($r) => $r >= 0 ? 1 : 0, $returns)) / $totalTrades)
        : 0.0;

    // Durchschnittliche Trade-Dauer
    $avgDuration = $totalTrades > 0
        ? array_sum($durations) / $totalTrades
        : 0.0;

    // Sharpe Ratio
    $sharpe = self::calculateSharpeRatio($returns);

    // Profit Factor
    $profitFactor = $grossLoss > 0
        ? $grossProfit / $grossLoss
        : 0.0;

    // Risk-Reward-Ratio (avg Gewinn / avg Verlust)
    $winsArr = array_filter($returns, fn($r) => $r > 0);
    $lossArr = array_filter($returns, fn($r) => $r < 0);
    $avgGain = count($winsArr) > 0
        ? array_sum($winsArr) / count($winsArr)
        : 0.0;
    $avgLoss = count($lossArr) > 0
        ? array_sum(array_map('abs', $lossArr)) / count($lossArr)
        : 0.0;
    $rrRatio = $avgLoss > 0
        ? $avgGain / $avgLoss
        : 0.0;

    // Exit‑Raten (Bruchteile)
    $stopLossRate   = $totalTrades > 0
        ? ($exitReasons['stop_loss']   ?? 0) / $totalTrades
        : 0.0;
    $takeProfitRate = $totalTrades > 0
        ? ($exitReasons['take_profit'] ?? 0) / $totalTrades
        : 0.0;
    $manualRate     = $totalTrades > 0
        ? ($exitReasons['manual']      ?? 0) / $totalTrades
        : 0.0;

    // Drawdown in Prozent relativ zum Peak‐Kapital
    $maxDDPercent = $peak > 0
        ? ($maxDD / $peak) * 100
        : 0.0;

    return array_merge($results, [
        'total_trades'         => $totalTrades,
        'win_rate'             => round($winRate, 4),            // Bruch
        'sharpe_ratio'         => round($sharpe,  2),
        'profit_factor'        => round($profitFactor, 2),
        'risk_reward_ratio'    => round($rrRatio, 2),
        'max_drawdown'         => round($maxDDPercent, 2),       // Prozent
        'avg_trade_duration'   => round($avgDuration, 1),
        'avg_profit_per_trade' => $totalTrades > 0
            ? round(($results['final_capital'] - $results['initial_capital']) / $totalTrades, 2)
            : 0.0,
        'best_trade'           => $totalTrades > 0 ? max(array_column($trades, 'profit')) : 0.0,
        'worst_trade'          => $totalTrades > 0 ? min(array_column($trades, 'profit')) : 0.0,
        'stop_loss_rate'       => round($stopLossRate,  4),      // Bruch
        'take_profit_rate'     => round($takeProfitRate, 4),     // Bruch
        'manual_exit_rate'     => round($manualRate, 4),         // Bruch
    ]);
}



	
	private static function updateEquityMetrics(array $results): array {
    $currentEquity = $results['final_capital'] ?? 0;

    // Peak aktualisieren
    if ($currentEquity > $results['peak']) {
        $results['peak'] = $currentEquity;
    }

    // Drawdown berechnen
    $drawdown = $results['peak'] - $currentEquity;
    if ($drawdown > $results['max_drawdown']) {
        $results['max_drawdown'] = $drawdown;
    }

    // Equity-Verlauf speichern
    $results['equity_curve'][] = $currentEquity;

    return $results;
}

	
	public static function executeBacktestPhase($candles, $riskPerTrade, $params = []) {
    $initialCapital = 1000;
    $results = [
        'initial_capital' => $initialCapital,
        'final_capital' => $initialCapital,
        'wins' => 0,
        'losses' => 0,
        'equity_curve' => [],
        'trade_results' => [],
        'trades' => [],
        'max_drawdown' => 0,
        'peak' => $initialCapital
    ];

    $startIndex = max(
        $params['bbPeriod'] ?? 20,
        $params['smaLong'] ?? 200,
        IndicatorSettings::$macdSettings['longPeriod'] ?? 26
    );

    if (count($candles) <= $startIndex) {
        Helper::logError("⚠️ Nicht genug Candles für Backtest", [
            'required' => $startIndex + 1,
            'given' => count($candles)
        ]);
        return self::calculateMetrics([], $results);
    }

    Helper::logError("🚀 Starte Backtest-Schleife", [
        'start_index' => $startIndex,
        'available_candles' => count($candles)
    ]);

    for ($i = $startIndex; $i < count($candles); $i++) {
        $current = $candles[$i];
        $previous = $candles[$i - 1];

        $signal = self::getSignalWithParams($current, $previous, $params);

        Helper::logError("🔍 Signal bei Index $i", [
            'signal' => $signal,
            'close' => $current['close'] ?? null,
            'rsi' => $current['rsi'] ?? null,
            'sma50' => $current['sma50'] ?? null,
            'sma200' => $current['sma200'] ?? null
        ]);

        if ($signal === 'neutral') {
            Helper::logError("⏭️ Kein Trade-Signal bei Index $i", $current);
            continue;
        }

        $trade = self::executeTrade($signal, $current, array_slice($candles, $i), $riskPerTrade);
        $results['final_capital'] += $trade['profit'];
        $results['trade_results'][] = $trade['profit'];
        $results['trades'][] = $trade;

        if ($trade['profit'] >= 0) {
            $results['wins']++;
        } else {
            $results['losses']++;
        }

        $results = self::updateEquityMetrics($results);
    }

    if (empty($results['trades'])) {
        Helper::logError("⚠️ executeBacktestPhase: Keine Trades generiert.", []);
    }

    return self::calculateMetrics($results['trades'], $results);
}


    private static function calculateSharpeRatio(array $returns, float $riskFreeRate = 0.0): float
    {
        $n = count($returns);
        if ($n === 0) {
            return 0.0;
        }

        $avgReturn = array_sum($returns) / $n;
        $variance = 0.0;

        foreach ($returns as $r) {
            $variance += pow($r - $avgReturn, 2);
        }

        $stdDev = sqrt($variance / $n);

        return $stdDev > 0 ? (($avgReturn - $riskFreeRate) / $stdDev) * sqrt(365) : 0.0;
    }


    
public static function runAdvancedBacktest($candlesticks, $periods, $riskPerTrade) {
    Helper::logError("🚦 runAdvancedBacktest wurde aufgerufen", [
        'candlestick_count' => count($candlesticks),
        'periods' => $periods,
        'riskPerTrade' => $riskPerTrade
    ]);

    $results = [];

    foreach ($periods as $periodName => $days) {
        $trainingSize = (int)($days * 0.7);
        $validationSize = $days - $trainingSize;

        Helper::logError("📎 Backtest Split für $periodName", [
            'total_days' => $days,
            'training_size' => $trainingSize,
            'validation_size' => $validationSize,
            'candlestick_total' => count($candlesticks)
        ]);

        // Minimum Candle-Anforderung (abhängig von Indikatoren)
        $minCandlesRequired = 201;

        if (count($candlesticks) < ($trainingSize + $minCandlesRequired)) {
            Helper::logError("❌ Nicht genug Candlesticks für $periodName", [
                'benötigt' => $trainingSize + $minCandlesRequired,
                'vorhanden' => count($candlesticks)
            ]);
            continue;
        }

        // Trainingsdaten
        $trainingCandles = array_slice($candlesticks, -($trainingSize + $minCandlesRequired), $trainingSize + $minCandlesRequired);
        Helper::logError("📊 Training-Phase gestartet für $periodName", [
            'candles_in_training' => count($trainingCandles)
        ]);

        $trainingResults = self::executeBacktestPhase(
            $trainingCandles,
            $riskPerTrade,
            ['period' => $periodName, 'days' => $trainingSize]
        );

        // Parameteroptimierung
        $optimizedParams = self::optimizeParameters($trainingCandles);
        Helper::logError("🔧 Optimierte Parameter für $periodName", $optimizedParams);

        // Validierungsdaten (inkl. minCandlesRequired für StartIndex)
        $validationCandles = array_slice($candlesticks, -($validationSize + $minCandlesRequired), $validationSize + $minCandlesRequired);
        Helper::logError("🧪 Validierungsphase gestartet für $periodName", [
            'candles_in_validation' => count($validationCandles)
        ]);

        $validationResults = self::executeBacktestPhase(
            $validationCandles,
            $riskPerTrade,
            array_merge($optimizedParams, ['period' => $periodName, 'days' => $validationSize])
        );

        $combinedResults = self::combineResults($trainingResults, $validationResults);
        $combinedResults['optimized_params'] = $optimizedParams;
        $combinedResults['days'] = $days;

        $results[$periodName] = $combinedResults;
    }

    // Beste Parameter für Analyse setzen
    self::$optimizedParams = self::selectBestParameters($results);
    Helper::logError("🏆 Beste optimierte Parameter ausgewählt", self::$optimizedParams);

    // 🟩 Jetzt: Letzten Candle prüfen für die reale Empfehlung
    $lastCandle = end($candlesticks);
    $prevCandle = prev($candlesticks);

    $liveSignal = self::getSignalWithParams($lastCandle, $prevCandle, self::$optimizedParams);

    Helper::logError("🔁 Finaler Live-Signal-Backtest", [
        'signal' => $liveSignal,
        'price' => $lastCandle['close'] ?? null,
        'rsi' => $lastCandle['rsi'] ?? null
    ]);

    if (in_array($liveSignal, ['long', 'short'])) {
        // Sende mindestens 20 letzte Candles für ATR etc.
        $history = array_slice($candlesticks, -20);
        $simulatedLiveTrade = self::executeTrade($liveSignal, $lastCandle, $history, $riskPerTrade);
        Helper::logError("✅ Simulierter Live-Trade abgeschlossen", $simulatedLiveTrade);
    }

    return $results;
}


private static function calculatePerformanceMetrics(array $results): array {
    // Sicherstellen, dass alle erforderlichen Keys existieren
    $results = array_merge([
        'wins' => 0,
        'losses' => 0,
        'trades' => [],
        'initial_capital' => 1000,
        'final_capital' => 1000,
        'max_drawdown' => 0
    ], $results);

    // Basis-Metriken berechnen
    $totalTrades = count($results['trades']);
    $winRate = ($results['wins'] + $results['losses']) > 0 
        ? $results['wins'] / ($results['wins'] + $results['losses']) 
        : 0;

    // Initialisiere Metriken mit Standardwerten
    $metrics = [
        'total_trades' => $totalTrades,
        'win_rate' => $winRate,
        'sharpe_ratio' => 0,
        'profit_factor' => 0,
        'stop_loss_rate' => 0,
        'take_profit_rate' => 0,
        'avg_trade_duration' => 0,
        'best_trade' => 0,
        'worst_trade' => 0
    ];

    // Nur berechnen wenn Trades vorhanden sind
    if ($totalTrades > 0) {
        $grossProfit = 0;
        $grossLoss = 0;
        $durations = [];
        $exitReasons = ['stop_loss' => 0, 'take_profit' => 0, 'timeout' => 0];

        foreach ($results['trades'] as $trade) {
    // Sicherstellen, dass der Trade die benötigten Felder hat
    $trade = array_merge([
        'profit' => 0,
        'duration' => 0,
        'exit_reason' => 'unknown'
    ], $trade);

    if ($trade['profit'] > 0) {
        $grossProfit += $trade['profit'];
    } else {
        $grossLoss += abs($trade['profit']);
    }

    $durations[] = $trade['duration'];

    // Sicherstellen, dass exit_reason gezählt werden kann
    $exitReasons[$trade['exit_reason']] = ($exitReasons[$trade['exit_reason']] ?? 0) + 1;
}


        // Sharpe Ratio Berechnung
        $returns = array_map(fn($t) => $t['profit'] / $results['initial_capital'], $results['trades']);
        if (count($returns) > 1) {
            $avgReturn = array_sum($returns) / count($returns);
            $stdDev = Helper::standardDeviation($returns);
            $metrics['sharpe_ratio'] = $stdDev > 0 ? $avgReturn / $stdDev * sqrt(365) : 0;
        }

        // Profit Factor
        $metrics['profit_factor'] = $grossLoss > 0 ? $grossProfit / $grossLoss : 0;

        // Exit Statistiken
        $metrics['stop_loss_rate'] = $exitReasons['stop_loss'] / $totalTrades;
        $metrics['take_profit_rate'] = $exitReasons['take_profit'] / $totalTrades;
        
        // Trade-Dauer
        $metrics['avg_trade_duration'] = array_sum($durations) / $totalTrades;
        
        // Beste/Schlechteste Trades
        $profits = array_column($results['trades'] ?? [], 'profit');
        $metrics['best_trade'] = max($profits);
        $metrics['worst_trade'] = min($profits);
    }

    return array_merge($results, $metrics);
	}
	private static function selectBestParameters(array $results): array {
    $bestParams = [];
    $bestScore = -INF;

    foreach ($results as $period => $data) {
        // Wähle z. B. nach bester Sharpe Ratio
        $score = $data['sharpe_ratio'] ?? 0;

        if ($score > $bestScore) {
            $bestScore = $score;
            $bestParams = $data['optimized_params'] ?? [];
        }
    }

    return $bestParams;
}


	
private static function executeTrade($signal, $entryCandle, $candles, $riskPerTrade) {
    $entryPrice = $entryCandle['close'];
    $entryTime = $entryCandle['timestamp'] ?? null;

    $atr = TechnicalIndicators::calculateATR($candles, IndicatorSettings::$atrPeriod);
    if ($atr === null || $atr <= 0) {
        Helper::logError("🚫 ATR konnte nicht berechnet werden – Trade abgebrochen", ['entryPrice' => $entryPrice]);
        return [
            'profit' => 0,
            'entryPrice' => $entryPrice,
            'exitPrice' => $entryPrice,
            'duration' => 0,
            'timestamp' => $entryTime,
            'exitReason' => 'invalid_atr'
        ];
    }

    $slMultiplier = 1;
    $tpMultiplier = IndicatorSettings::$riskRewardRatio;

    if ($signal === 'long') {
        $stopLoss = $entryPrice - $atr * $slMultiplier;
        $takeProfit = $entryPrice + $atr * $tpMultiplier;
    } else {
        $stopLoss = $entryPrice + $atr * $slMultiplier;
        $takeProfit = $entryPrice - $atr * $tpMultiplier;
    }

    $duration = 0;
    $exitReason = 'manual'; // Fallback falls kein SL/TP getroffen wird

    for ($i = array_search($entryCandle, $candles) + 1; $i < count($candles); $i++) {
        $price = $candles[$i]['close'];
        $duration++;

        if ($signal === 'long') {
            if ($price <= $stopLoss) {
                $profit = $stopLoss - $entryPrice;
                $exitPrice = $stopLoss;
                $exitReason = 'stop_loss';
                break;
            }
            if ($price >= $takeProfit) {
                $profit = $takeProfit - $entryPrice;
                $exitPrice = $takeProfit;
                $exitReason = 'take_profit';
                break;
            }
        } else {
            if ($price >= $stopLoss) {
                $profit = $entryPrice - $stopLoss;
                $exitPrice = $stopLoss;
                $exitReason = 'stop_loss';
                break;
            }
            if ($price <= $takeProfit) {
                $profit = $entryPrice - $takeProfit;
                $exitPrice = $takeProfit;
                $exitReason = 'take_profit';
                break;
            }
        }
    }

    // Falls SL oder TP nicht erreicht wurde
    if (!isset($profit)) {
        $exitPrice = $candles[count($candles) - 1]['close'];
        $duration = count($candles) - array_search($entryCandle, $candles);
        $profit = ($signal === 'long') ? ($exitPrice - $entryPrice) : ($entryPrice - $exitPrice);
        $exitReason = 'manual';
    }

    // Gebühren und Slippage abziehen
    $totalFee = BacktestConfig::$tradingFees * 2;
    $slippage = BacktestConfig::$slippage;
    $profit -= ($entryPrice * $totalFee);
    $profit -= ($entryPrice * $slippage);

    // Logging
Helper::logError("✅ Executed trade", [
    'Signal' => $signal,
    'Entry' => $entryPrice,
    'Exit' => $exitPrice,
    'SL' => $stopLoss,
    'TP' => $takeProfit,
    'Reason' => $exitReason,
    'Duration' => $duration,
    'Result' => $profit
]);

        return [
        'signal' => $signal,
        'entryPrice' => $entryPrice,
        'exitPrice' => $exitPrice,
        'profit' => $profit,
        'duration' => $duration,
        'timestamp' => $entryTime,
        'stopLoss' => $stopLoss,
        'takeProfit' => $takeProfit,
        'exit_reason' => $exitReason // ✅ wichtig für Backtest-Analyse
    ];

}
 

private static function combineResults(array $trainingResults, array $validationResults): array
{
    // 1) Standard-Defaults
    $defaults = [
        'initial_capital'      => 1000.0,
        'final_capital'        => 1000.0,
        'wins'                 => 0,
        'losses'               => 0,
        'total_trades'         => 0,
        'win_rate'             => 0.0,  // hier als fraction (0–1)
        'profit_factor'        => 0.0,
        'risk_reward_ratio'    => 0.0,
        'sharpe_ratio'         => 0.0,
        'max_drawdown'         => 0.0,
        'avg_profit_per_trade' => 0.0,
        'best_trade'           => 0.0,
        'worst_trade'          => 0.0,
        'stop_loss_rate'       => 0.0,  // fraction
        'take_profit_rate'     => 0.0,
        'avg_trade_duration'   => 0.0,
    ];

    // 2) Fehlende Keys auffüllen
    $t = array_merge($defaults, $trainingResults);
    $v = array_merge($defaults, $validationResults);

    // 3) Sonderfall: keine Trades im Validation-Split
    if ($v['total_trades'] === 0) {
        // einfach Trainings-Ergebnisse zurückgeben
        return $t + ['final_capital' => $t['final_capital']];
    }

    // 4) Sonderfall: keine Trades im Training
    if ($t['total_trades'] === 0) {
        return $v + ['initial_capital' => $v['initial_capital']];
    }

    // 5) Sonst beide Splits zusammenführen
    $wins   = $t['wins']   + $v['wins'];
    $losses = $t['losses'] + $v['losses'];
    $trades = $wins + $losses;

    $winRate = ($trades > 0)
        ? ($wins / $trades)
        : 0.0;

    // Profit Factor als Mittel, falls in beiden >0
    if ($t['profit_factor'] > 0 && $v['profit_factor'] > 0) {
        $pf = ($t['profit_factor'] + $v['profit_factor']) / 2;
    } else {
        $pf = max($t['profit_factor'], $v['profit_factor']);
    }

    // Risk-Reward-Ratio genauso
    if ($t['risk_reward_ratio'] > 0 && $v['risk_reward_ratio'] > 0) {
        $rr = ($t['risk_reward_ratio'] + $v['risk_reward_ratio']) / 2;
    } else {
        $rr = max($t['risk_reward_ratio'], $v['risk_reward_ratio']);
    }

    // Sharpe einfach mitteln
    $sharpe = ($t['sharpe_ratio'] + $v['sharpe_ratio']) / 2;

    // maximaler Drawdown
    $maxDD = max($t['max_drawdown'], $v['max_drawdown']);

    // avg Profit per Trade (gewichtet)
    $avgProfit = 0.0;
    if ($trades > 0) {
        $sumProfits = ($t['avg_profit_per_trade'] * $t['total_trades'])
                    + ($v['avg_profit_per_trade'] * $v['total_trades']);
        $avgProfit  = $sumProfits / $trades;
    }

    // Best / Worst
    $best  = max($t['best_trade'], $v['best_trade']);
    $worst = min($t['worst_trade'], $v['worst_trade']);

    // Exit-Raten & Dauer (gewichtet)
    $slRate = ($trades > 0)
        ? (($t['stop_loss_rate']   * $t['total_trades'])
         + ($v['stop_loss_rate']   * $v['total_trades'])) / $trades
        : 0.0;

    $tpRate = ($trades > 0)
        ? (($t['take_profit_rate'] * $t['total_trades'])
         + ($v['take_profit_rate'] * $v['total_trades'])) / $trades
        : 0.0;

    $avgDur = ($trades > 0)
        ? (($t['avg_trade_duration'] * $t['total_trades'])
         + ($v['avg_trade_duration'] * $v['total_trades'])) / $trades
        : 0.0;

    return [
        'initial_capital'      => $t['initial_capital'],
        'final_capital'        => $v['final_capital'],
        'wins'                 => $wins,
        'losses'               => $losses,
        'total_trades'         => $trades,
        'win_rate'             => round($winRate, 4),   // Bruch 0–1, wird später *100
        'profit_factor'        => round($pf, 2),
        'risk_reward_ratio'    => round($rr, 2),
        'sharpe_ratio'         => round($sharpe, 2),
        'max_drawdown'         => round($maxDD, 2),
        'avg_profit_per_trade' => round($avgProfit, 2),
        'best_trade'           => round($best, 2),
        'worst_trade'          => round($worst, 2),
        'stop_loss_rate'       => round($slRate, 4),    // Bruch 0–1
        'take_profit_rate'     => round($tpRate, 4),
        'avg_trade_duration'   => round($avgDur, 1),
    ];
}



    public static function optimizeParameters($candlesticks) {
        $bestParams = [
            'rsiOverbought' => IndicatorSettings::$rsiOverbought,
            'rsiOversold' => IndicatorSettings::$rsiOversold,
            'smaShort' => 50,
            'smaLong' => 200,
            'bbPeriod' => IndicatorSettings::$bbPeriod,
            'bbMultiplier' => IndicatorSettings::$bbMultiplier,
            'confidenceThreshold' => BacktestConfig::$confidenceThreshold,
            'performance' => 0,
            'sharpe_ratio' => 0,
            'max_drawdown' => 0
        ];
        
        $phases = [
            ['iterations' => 20, 'range' => 'wide'],
            ['iterations' => 20, 'range' => 'medium'],
            ['iterations' => 10, 'range' => 'narrow']
        ];
        
        foreach ($phases as $phase) {
            for ($i = 0; $i < $phase['iterations']; $i++) {
                $params = self::generateRandomParams($phase['range'], $bestParams);
                
                $results = self::testParameters($candlesticks, $params);
                
                $performanceScore = $results['win_rate'] * (1 - $results['max_drawdown']) * $results['sharpe_ratio'];
                
				
                if ($performanceScore > $bestParams['performance']) {
                    $bestParams = array_merge($params, [
                        'performance' => $performanceScore,
                        'sharpe_ratio' => $results['sharpe_ratio'],
                        'max_drawdown' => $results['max_drawdown']
                    ]);
					
                }
            }
        }

        return $bestParams;
    }
    
private static function generateRandomParams($range, $currentBest) {
    $params = [];
    
    switch ($range) {
        case 'wide':
            $params = [
                'rsiOverbought' => mt_rand(60, 80),
                'rsiOversold' => mt_rand(20, 40),
                'smaShort' => mt_rand(30, 70),
                'smaLong' => mt_rand(150, 250),
                'bbPeriod' => mt_rand(15, 25),
                'bbMultiplier' => mt_rand(15, 25) / 10,
                'confidenceThreshold' => mt_rand(50, 70) / 100
            ];
            break;
            
        case 'medium':
            // Ensure min <= max for all mt_rand calls
            $rsiOverboughtMin = max(65, $currentBest['rsiOverbought'] - 5);
            $rsiOverboughtMax = min(75, $currentBest['rsiOverbought'] + 5);
            
            $rsiOversoldMin = max(25, $currentBest['rsiOversold'] - 5);
            $rsiOversoldMax = min(35, $currentBest['rsiOversold'] + 5);
            
            $smaShortMin = max(40, $currentBest['smaShort'] - 10);
            $smaShortMax = min(60, $currentBest['smaShort'] + 10);
            
            $smaLongMin = max(180, $currentBest['smaLong'] - 20);
            $smaLongMax = min(220, $currentBest['smaLong'] + 20);
            
            $bbPeriodMin = max(18, $currentBest['bbPeriod'] - 2);
            $bbPeriodMax = min(22, $currentBest['bbPeriod'] + 2);
            
            $bbMultMinInt = max(16, (int)($currentBest['bbMultiplier'] * 10 - 2));
            $bbMultMaxInt = min(24, (int)($currentBest['bbMultiplier'] * 10 + 2));
            
            $confThreshMinInt = max(55, (int)($currentBest['confidenceThreshold'] * 100 - 5));
            $confThreshMaxInt = min(65, (int)($currentBest['confidenceThreshold'] * 100 + 5));
            
            $params = [
                'rsiOverbought' => mt_rand(
                    (int)min($rsiOverboughtMin, $rsiOverboughtMax),
                    (int)max($rsiOverboughtMin, $rsiOverboughtMax)
                ),
                'rsiOversold' => mt_rand(
                    (int)min($rsiOversoldMin, $rsiOversoldMax),
                    (int)max($rsiOversoldMin, $rsiOversoldMax)
                ),
                'smaShort' => mt_rand(
                    (int)min($smaShortMin, $smaShortMax),
                    (int)max($smaShortMin, $smaShortMax)
                ),
                'smaLong' => mt_rand(
                    (int)min($smaLongMin, $smaLongMax),
                    (int)max($smaLongMin, $smaLongMax)
                ),
                'bbPeriod' => mt_rand(
                    (int)min($bbPeriodMin, $bbPeriodMax),
                    (int)max($bbPeriodMin, $bbPeriodMax)
                ),
                'bbMultiplier' => mt_rand(
                    (int)min($bbMultMinInt, $bbMultMaxInt),
                    (int)max($bbMultMinInt, $bbMultMaxInt)
                ) / 10,
                'confidenceThreshold' => mt_rand(
                    (int)min($confThreshMinInt, $confThreshMaxInt),
                    (int)max($confThreshMinInt, $confThreshMaxInt)
                ) / 100
            ];
            break;
            
        case 'narrow':
            // Similar protection for narrow range
            $rsiOverboughtMin = max(68, $currentBest['rsiOverbought'] - 2);
            $rsiOverboughtMax = min(72, $currentBest['rsiOverbought'] + 2);
            
            $rsiOversoldMin = max(28, $currentBest['rsiOversold'] - 2);
            $rsiOversoldMax = min(32, $currentBest['rsiOversold'] + 2);
            
            $smaShortMin = max(45, $currentBest['smaShort'] - 5);
            $smaShortMax = min(55, $currentBest['smaShort'] + 5);
            
            $smaLongMin = max(190, $currentBest['smaLong'] - 10);
            $smaLongMax = min(210, $currentBest['smaLong'] + 10);
            
            $bbPeriodMin = max(19, $currentBest['bbPeriod'] - 1);
            $bbPeriodMax = min(21, $currentBest['bbPeriod'] + 1);
            
            $bbMultMinInt = max(18, (int)($currentBest['bbMultiplier'] * 10 - 1));
            $bbMultMaxInt = min(22, (int)($currentBest['bbMultiplier'] * 10 + 1));
            
            $confThreshMinInt = max(58, (int)($currentBest['confidenceThreshold'] * 100 - 2));
            $confThreshMaxInt = min(62, (int)($currentBest['confidenceThreshold'] * 100 + 2));
            
            $params = [
                'rsiOverbought' => mt_rand(
                    (int)min($rsiOverboughtMin, $rsiOverboughtMax),
                    (int)max($rsiOverboughtMin, $rsiOverboughtMax)
                ),
                'rsiOversold' => mt_rand(
                    (int)min($rsiOversoldMin, $rsiOversoldMax),
                    (int)max($rsiOversoldMin, $rsiOversoldMax)
                ),
                'smaShort' => mt_rand(
                    (int)min($smaShortMin, $smaShortMax),
                    (int)max($smaShortMin, $smaShortMax)
                ),
                'smaLong' => mt_rand(
                    (int)min($smaLongMin, $smaLongMax),
                    (int)max($smaLongMin, $smaLongMax)
                ),
                'bbPeriod' => mt_rand(
                    (int)min($bbPeriodMin, $bbPeriodMax),
                    (int)max($bbPeriodMin, $bbPeriodMax)
                ),
                'bbMultiplier' => mt_rand(
                    (int)min($bbMultMinInt, $bbMultMaxInt),
                    (int)max($bbMultMinInt, $bbMultMaxInt)
                ) / 10,
                'confidenceThreshold' => mt_rand(
                    (int)min($confThreshMinInt, $confThreshMaxInt),
                    (int)max($confThreshMinInt, $confThreshMaxInt)
                ) / 100
            ];
            break;
    }
    
    return $params;
}

public static function testParameters($candlesticks, $params) {
    $initialCapital = 1000;
    $results = [
        'initial_capital' => $initialCapital,
        'final_capital' => $initialCapital,
        'wins' => 0,
        'losses' => 0,
        'equity_curve' => [],
        'trade_results' => [],
        'trades' => [],
        'max_drawdown' => 0,
        'peak' => $initialCapital
    ];

    $startIndex = max(
        $params['bbPeriod'] ?? 20,
        $params['smaLong'] ?? 200,
        IndicatorSettings::$macdSettings['longPeriod'] ?? 26
    );

    for ($i = $startIndex; $i < count($candlesticks); $i++) {
        $current = $candlesticks[$i];
        $previous = $candlesticks[$i - 1];

        $signal = self::getSignalWithParams($current, $previous, $params);

        if ($signal === 'long' || $signal === 'short') {
            $trade = self::executeTrade($signal, $current, array_slice($candlesticks, $i), BacktestConfig::$riskPerTrade);
            $results['final_capital'] += $trade['profit'];
            $results['trade_results'][] = $trade['profit'];
            $results['trades'][] = $trade;

            if ($trade['profit'] >= 0) {
                $results['wins']++;
            } else {
                $results['losses']++;
            }

            $results = self::updateEquityMetrics($results);
        }
    }

    if (empty($results['trades'])) {
        Helper::logError("⚠️ testParameters: Keine Trades generiert.", []);
    }

    $finalResults = self::calculateMetrics($results['trades'], $results);
		return $finalResults;

}


public static function getSignalWithParams($current, $previous, $params) {
    // Alle Default-Parameter hier zentral definieren (können durch $params überschrieben werden)
    $defaultParams = [
        'rsiOversold' => IndicatorSettings::$rsiOversold,
        'rsiOverbought' => IndicatorSettings::$rsiOverbought,
        'rsiAngleThresholdLong' => IndicatorSettings::$rsiAngleThresholdLong,
        'rsiAngleThresholdShort' => IndicatorSettings::$rsiAngleThresholdShort,
        'confidenceThreshold' => BacktestConfig::$confidenceThreshold,
    ];

    $params = array_merge($defaultParams, (array)$params);

    if (!isset($current['sma50'], $current['sma200'], $current['rsi'], $current['close'], 
              $current['bb_lower'], $current['bb_upper'], $current['obv'], $previous['obv'])) {
		
		Helper::logError("Missing data in getSignalWithParams", [
    'sma50' => $current['sma50'] ?? null,
    'sma200' => $current['sma200'] ?? null,
    'rsi' => $current['rsi'] ?? null,
    'close' => $current['close'] ?? null,
    'bb_lower' => $current['bb_lower'] ?? null,
    'bb_upper' => $current['bb_upper'] ?? null,
    'obv' => $current['obv'] ?? null,
    'previous_obv' => $previous['obv'] ?? null
]);

        return 'short';
    }

    $signals = [];

    // === SMA-Trend ===
    $signals['sma'] = [
        'signal' => ($current['sma50'] > $current['sma200']) ? 'long' : 'short',
        'weight' => 0.25
    ];

    // === RSI mit Umkehr und Winkelprüfung ===
    $rsiSignal = 'neutral';
    $currentRsi = $current['rsi'] ?? null;
    $previousRsi = $previous['rsi'] ?? null;

    if ($previousRsi !== null && $currentRsi !== null) {
        if ($previousRsi < $params['rsiOversold'] && $currentRsi > $previousRsi && $currentRsi > $params['rsiOversold']) {
            $angle = TechnicalIndicators::calculateRSIAngle([$previousRsi, $currentRsi]);
            if ($angle !== null && $angle >= $params['rsiAngleThresholdLong']) {
                $rsiSignal = 'long';
            }
        }

        if ($previousRsi > $params['rsiOverbought'] && $currentRsi < $previousRsi && $currentRsi < $params['rsiOverbought']) {
            $angle = TechnicalIndicators::calculateRSIAngle([$previousRsi, $currentRsi]);
            if ($angle !== null && $angle <= $params['rsiAngleThresholdShort']) {
                $rsiSignal = 'short';
            }
        }
    }

    $signals['rsi'] = [
        'signal' => $rsiSignal,
        'weight' => 0.20
    ];

    // === Bollinger Bands ===
    $signals['bb'] = [
        'signal' => ($current['close'] < $current['bb_lower']) ? 'long' : 
                   ($current['close'] > $current['bb_upper'] ? 'short' : 'neutral'),
        'weight' => 0.25
    ];

    // === MACD ===
    $signals['macd'] = [
        'signal' => (isset($current['macdLine'], $current['signalLine']) && 
                   $current['macdLine'] > $current['signalLine']) ? 'long' : 'short',
        'weight' => 0.15
    ];

    // === OBV ===
    $signals['obv'] = [
        'signal' => ($current['obv'] > $previous['obv']) ? 'long' : 'short',
        'weight' => 0.15
    ];

// === Gewichtung berechnen ===
$longScore = $shortScore = 0;
foreach ($signals as $signal) {
    if ($signal['signal'] === 'long') {
        $longScore += $signal['weight'];
    } elseif ($signal['signal'] === 'short') {
        $shortScore += $signal['weight'];
    }
}

// Entscheidung vorbereiten
$finalSignal = 'neutral';

if ($longScore >= $params['confidenceThreshold']) {
    $finalSignal = 'long';
}
if ($shortScore >= $params['confidenceThreshold']) {
    $finalSignal = 'short';
}

// Logging unabhängig vom Ergebnis
Helper::logError("📍 Signal wurde berechnet", [
    'finalSignal' => $finalSignal,
    'longScore' => $longScore,
    'shortScore' => $shortScore,
    'threshold' => $params['confidenceThreshold'],
    'rsi' => $current['rsi'],
    'sma50' => $current['sma50'],
    'sma200' => $current['sma200'],
    'macdLine' => $current['macdLine'] ?? null,
    'signalLine' => $current['signalLine'] ?? null,
    'bb_upper' => $current['bb_upper'] ?? null,
    'bb_lower' => $current['bb_lower'] ?? null,
    'obv' => $current['obv'] ?? null,
    'prev_obv' => $previous['obv'] ?? null,
    'individualSignals' => $signals
]);

// SL/TP anhand der Bollinger Bänder bestimmen:
$stopLoss = null;
$takeProfit = null;

if ($finalSignal === 'long') {
    $stopLoss = $current['bb_lower'];
    $takeProfit = $current['bb_upper'];
} elseif ($finalSignal === 'short') {
    $stopLoss = $current['bb_upper'];
    $takeProfit = $current['bb_lower'];
}

return [
    'signal' => $finalSignal,
    'stop_loss' => $stopLoss,
    'take_profit' => $takeProfit
];


}


    public static function getOptimizedSignal($current, $previous) {
        // Verwendung der optimierten Parameter
        return self::getSignalWithParams($current, $previous, self::$optimizedParams);
    }
    
    public static function runMonteCarloSimulation($backtestResults, $iterations = 500) {
        $originalTrades = $backtestResults['trade_results'];
        $numTrades = count($originalTrades);
        
        if ($numTrades < 10) return [];
        
        $simulationResults = [];
        
        for ($i = 0; $i < $iterations; $i++) {
            $equity = $backtestResults['initial_capital'];
            $shuffledTrades = $originalTrades;
            shuffle($shuffledTrades);
            
            foreach ($shuffledTrades as $trade) {
                $equity += $trade;
            }
            
            $simulationResults[] = $equity;
        }
        
        sort($simulationResults);
        
        return [
            'median' => $simulationResults[(int)($iterations * 0.5)],
            'best_5pct' => $simulationResults[(int)($iterations * 0.95)],
            'worst_5pct' => $simulationResults[(int)($iterations * 0.05)],
            'probability_profit' => count(array_filter($simulationResults, function($x) use ($backtestResults) {
                return $x > $backtestResults['initial_capital'];
            })) / $iterations,
            'max_runup' => max($simulationResults) - $backtestResults['initial_capital'],
            'max_drawdown' => $backtestResults['initial_capital'] - min($simulationResults)
        ];
    }
    


public static function outputBacktestResults($backtestResults) {
    // Validate input
      if (!is_array($backtestResults)) {
        return "\n=== Backtesting Results ===\nNo valid data available.\n";
    }

    $output = "\n=== Advanced Backtesting Report ===\n";
    $output .= str_repeat("-", 50) . "\n";
    
    foreach ($backtestResults as $period => $results) {
        if (!is_array($results)) continue;
        
        // Set defaults and ensure all keys exist
        $results = array_merge([
            'days' => 0,
            'initial_capital' => 1000,
            'final_capital' => 1000,
            'wins' => 0,
            'losses' => 0,
            'win_rate' => 0,
            'profit_factor' => 0,
            'sharpe_ratio' => 0,
            'max_drawdown' => 0,
            'total_trades' => 0,
            'avg_profit_per_trade' => 0,
            'best_trade' => 0,
            'worst_trade' => 0,
            'stop_loss_rate' => 0,
            'take_profit_rate' => 0,
            'avg_trade_duration' => 0,
            'risk_reward_ratio' => 0
        ], $results);

        // Calculate derived metrics
        $profit = $results['final_capital'] - $results['initial_capital'];
        $profitPercentage = ($results['initial_capital'] > 0) 
            ? ($profit / $results['initial_capital']) * 100 
            : 0;
        
        $avgRR = $results['total_trades'] > 0 
            ? $results['risk_reward_ratio'] 
            : 'N/A';

        // Format output
        $output .= sprintf(
            "\n** %s-Term Performance (%d days) **\n%s\n",
            ucfirst($period),
            $results['days'],
            str_repeat("-", 30)
        );

        $output .= sprintf(
            "Capital: %s -> %s (Δ %+.2f%%)\n",
            number_format($results['initial_capital'], 2),
            number_format($results['final_capital'], 2),
            round($profitPercentage, 2)
        );

        $output .= sprintf(
            "Trades: %d (W: %d | L: %d) | Win Rate: %.1f%%\n",
            $results['total_trades'],
            $results['wins'],
            $results['losses'],
            $results['win_rate'] * 100
        );

        $output .= sprintf(
            "Risk Metrics: Sharpe %.2f | Max DD %.1f%% | Avg RR %.2f\n",
            $results['sharpe_ratio'],
            $results['max_drawdown'] * 100,
            $avgRR
        );

        $output .= sprintf(
            "Trade Stats: Avg Profit %.2f | Best %.2f | Worst %.2f\n",
            $results['avg_profit_per_trade'],
            $results['best_trade'],
            $results['worst_trade']
        );

        $output .= sprintf(
            "Exit Analysis: SL %.1f%% | TP %.1f%% | Avg Duration %d periods\n",
            $results['stop_loss_rate'] * 100,
            $results['take_profit_rate'] * 100,
            $results['avg_trade_duration']
        );

        // Add visual performance indicator
        $performance = ($profitPercentage >= 0) ? "🟢" : "🔴";
        $output .= sprintf("\n%s Overall Performance: %+.2f%% %s\n",
            $performance,
            $profitPercentage,
            str_repeat($performance, abs((int)round($profitPercentage/5)))
        );
    }

    // Add summary footer
    $output .= "\n" . str_repeat("=", 50) . "\n";
    $output .= "Note: Includes trading fees (0.04%) and slippage (0.05%)\n";
    $output .= "Backtest completed: " . date('Y-m-d H:i:s') . "\n";

    return $output;
	}
}
	
class TradingAnalysis {
public static function performAnalysis() {
    global $mlTrader;

    // 0) Wenn auf den Trainings‑Button geklickt wurde:
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ml_train'])) {
        $mlTrader->trainFromStoredSamples();
        file_put_contents(__DIR__ . '/last_train_time.txt', time());
        header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
        exit;
    }

    // 1) Daten abrufen
    $apiResponse = BitgetAPI::getCandles(SYMBOL, TIMEFRAME, LIMIT, PRODUCT_TYPE);
    if ($apiResponse === false || !isset($apiResponse['data'])) {
        Helper::logError("Keine Candlestick-Daten erhalten");
        return;
    }

    // 2) Daten verarbeiten
    $candlesticks = self::processCandlestickData($apiResponse['data']);
    if (empty($candlesticks)) {
        Helper::logError("Keine verarbeitbaren Candlestick-Daten");
        return;
    }

    // 3) Indikatoren berechnen
    $candlesticks = TechnicalIndicators::prepareCandlesWithIndicators($candlesticks);

    // 4) ECHTE LABELS + FEATURES aus der letzten Kerze
    $len = count($candlesticks);
    if ($len >= 2) {
        $prev = $candlesticks[$len - 2];
        $curr = $candlesticks[$len - 1];

        // 4.1) Label long/short
        $label = $curr['close'] > $prev['close'] ? 'long' : 'short';

        // 4.2) Flaches numerisches Feature‑Array
        $features = [
            $curr['rsi']                  ?? 0.0,
            $curr['volume']               ?? 0.0,
            isset($curr['macdLine'], $curr['signalLine'])
                ? $curr['macdLine'] - $curr['signalLine']
                : 0.0,
            $curr['obv']                  ?? 0.0,
        ];

        // 4.3) Sample speichern
        $mlTrader->storeTrainingSample($features, $label);

        Helper::logInfo("Sample gespeichert", [
            'label'    => $label,
            'features' => $features,
            'time'     => $curr['timestamp']
        ]);

        // 4.4) DEBUG im Browser ausgeben:
        echo "<div style='background:#fee;border:1px solid #f00;padding:8px;margin:10px 0;'>
                <strong>Debug neue Sample-Features:</strong><br>
                <code>" . htmlspecialchars(json_encode($features)) . "</code>
              </div>";
    }

    // 5) Backtesting ...
    $lastCandle     = end($candlesticks);
    $backtestResults = count($candlesticks) > 200
        ? TradingStrategy::runAdvancedBacktest($candlesticks, BacktestConfig::$periods, BacktestConfig::$riskPerTrade)
        : self::getDefaultBacktestResults();

    // 6) Trendanalyse & Empfehlung
    $analysis       = self::analyzeTrend($candlesticks);
    $recommendation = self::generateRecommendation($lastCandle, $analysis, $backtestResults);

    // 7) Ausgabe
    self::outputResults($candlesticks, $lastCandle, $recommendation, $backtestResults);
}


    private static function getDefaultBacktestResults() {
        return [
            'short' => ['win_rate' => 0, 'sharpe_ratio' => 0, 'max_drawdown' => 0],
            'medium' => ['win_rate' => 0, 'sharpe_ratio' => 0, 'max_drawdown' => 0],
            'long' => ['win_rate' => 0, 'sharpe_ratio' => 0, 'max_drawdown' => 0]
        ];
    }

    private static function processCandlestickData($apiData) {
        $markPrice = BitgetAPI::getMarkPrice(SYMBOL, PRODUCT_TYPE);
        $candlesticks = [];
        
        foreach ($apiData as $candle) {
            if (count($candle) >= 7) {
                $currentMarkPrice = ($markPrice !== false) ? $markPrice : (float)$candle[4];
                
                $candlesticks[] = [
                    'timestamp' => isset($candle[0]) && is_numeric($candle[0]) 
    				? (int)($candle[0] / 1000)  // von Millisekunden auf Sekunden
    				: time(),

                    'open' => (float)$candle[1],
                    'high' => (float)$candle[2],
                    'low' => (float)$candle[3],
                    'close' => (float)$candle[4],
                    'volume' => (float)$candle[5],
                    'markPrice' => $currentMarkPrice
                ];
            }
        }
        
        return $candlesticks;
    }
 
    private static function calculateIndicators($candlesticks) {
        $closes = array_column($candlesticks, 'close');
        $volumes = array_column($candlesticks, 'volume');

        // SMAs
        $smaValues = TechnicalIndicators::calculateSMA($closes, IndicatorSettings::$smaPeriods[0]);
        $sma50Values = TechnicalIndicators::calculateSMA($closes, IndicatorSettings::$smaPeriods[1]);
        $sma200Values = TechnicalIndicators::calculateSMA($closes, IndicatorSettings::$smaPeriods[2]);

        // Bollinger Bands
        $bbValues = TechnicalIndicators::calculateBollingerBands(
            $closes,
            IndicatorSettings::$bbPeriod,
            IndicatorSettings::$bbMultiplier
        );

        // OBV
        $obvValues = TechnicalIndicators::calculateOBV($closes, $volumes);

        // Indikatoren hinzufügen
        foreach ($candlesticks as $i => &$candle) {
            if ($i >= IndicatorSettings::$smaPeriods[0] - 1) {
                $candle['sma'] = $smaValues[$i - IndicatorSettings::$smaPeriods[0] + 1];
            }
            if ($i >= IndicatorSettings::$smaPeriods[1] - 1) {
                $candle['sma50'] = $sma50Values[$i - IndicatorSettings::$smaPeriods[1] + 1] ?? null;
            }
            if ($i >= IndicatorSettings::$smaPeriods[2] - 1) {
                $candle['sma200'] = $sma200Values[$i - IndicatorSettings::$smaPeriods[2] + 1] ?? null;
            }
            if ($i >= IndicatorSettings::$bbPeriod - 1) {
                $candle['bb_upper'] = $bbValues[$i - IndicatorSettings::$bbPeriod + 1]['upper'];
                $candle['bb_middle'] = $bbValues[$i - IndicatorSettings::$bbPeriod + 1]['middle'];
                $candle['bb_lower'] = $bbValues[$i - IndicatorSettings::$bbPeriod + 1]['lower'];
            }
            $candle['obv'] = $obvValues[$i] ?? null;
        }
        unset($candle);

        // RSI berechnen
        $rsiPeriod = 14;
        for ($i = $rsiPeriod; $i < count($candlesticks); $i++) {
            $slice = array_slice($closes, 0, $i + 1);
            $rsi = TechnicalIndicators::calculateRSI($slice, $rsiPeriod);
            if ($rsi !== null) {
                $candlesticks[$i]['rsi'] = $rsi;
            }
        }

        // MACD berechnen
        $macd = TechnicalIndicators::calculateMACD(
            $closes,
            IndicatorSettings::$macdSettings['shortPeriod'],
            IndicatorSettings::$macdSettings['longPeriod'],
            IndicatorSettings::$macdSettings['signalPeriod']
        );

        // MACD-Werte zuweisen
        if (!empty($macd['macdLine']) && !empty($macd['signalLine'])) {
            $macdLength = count($macd['macdLine']);
            $startIndex = max(0, count($candlesticks) - $macdLength);

            for ($i = 0; $i < $macdLength; $i++) {
                $idx = $startIndex + $i;
                if ($idx < count($candlesticks)) {
                    $candlesticks[$idx]['macdLine'] = $macd['macdLine'][$i] ?? null;
                    $candlesticks[$idx]['signalLine'] = $macd['signalLine'][$i] ?? null;
                    $candlesticks[$idx]['histogram'] = $macd['histogram'][$i] ?? null;
                }
            }
        }

        // Fibonacci Retracements berechnen
        $highs = array_column($candlesticks, 'high');
        $lows = array_column($candlesticks, 'low');
        $recentHigh = max($highs);
        $recentLow = min($lows);
        $fibonacciLevels = TechnicalIndicators::calculateFibonacciRetracements($recentHigh, $recentLow);
        foreach ($candlesticks as &$candle) {
            $candle['fibonacci'] = $fibonacciLevels;
        }
        unset($candle);

        return $candlesticks;
    }

    private static function analyzeTrend($candlesticks) {
        $lastCandle = end($candlesticks);
        $secondLastCandle = $candlesticks[count($candlesticks) - 2] ?? null;
        
        // MACD-Trend
        $macdTrend = ($lastCandle['macdLine'] > $lastCandle['signalLine']) ? "Aufwärtstrend" : "Abwärtstrend";
        
        // Trendanalyse: SMA 50 vs. SMA 200
        $trend = ($lastCandle['sma50'] > $lastCandle['sma200']) ? "Aufwärtstrend" : "Abwärtstrend";
        
        // Volumenanalyse
        $currentVolume = $lastCandle['volume'];
        $volumeLevel = ($currentVolume > 500) ? "hoch" : (($currentVolume > 200) ? "mittel" : "niedrig");
        
        // OBV-Trendanalyse
        $currentOBV = $lastCandle['obv'];
        $previousOBV = $secondLastCandle['obv'] ?? null;
        $obvTrend = ($previousOBV === null) ? "Neutral" : 
                   ($currentOBV > $previousOBV ? "Aufwärtstrend" : 
                   ($currentOBV < $previousOBV ? "Abwärtstrend" : "Neutral"));
        
        // RSI-Trend
        $rsiValues = array_column(array_slice($candlesticks, -10), 'rsi');
        $rsiAngle = TechnicalIndicators::calculateRSIAngle($rsiValues);
        $rsiTrend = "Unbekannt";
        $rsiAngleText = "nicht berechenbar";
        
        if ($rsiAngle !== null) {
            if ($rsiAngle > 15) {
                $rsiTrend = "Aufwärtstrend";
                $rsiAngleText = "steigend (" . $rsiAngle . "°)";
            } elseif ($rsiAngle < -15) {
                $rsiTrend = "Abwärtstrend";
                $rsiAngleText = "fallend (" . $rsiAngle . "°)";
            } else {
                $rsiTrend = "Seitwärts";
                $rsiAngleText = "neutral (" . $rsiAngle . "°)";
            }
        }
        
        // Bollinger Bänder Position
        $currentPrice = $lastCandle['close'];
        $priceDistanceToUpper = abs($currentPrice - $lastCandle['bb_upper']);
        $priceDistanceToLower = abs($currentPrice - $lastCandle['bb_lower']);
        $bollingerBandPosition = ($priceDistanceToUpper < $priceDistanceToLower) ? 
            "nahe dem oberen Bollinger Band (potenzieller Widerstand)" : 
            "nahe dem unteren Bollinger Band (potenzielle Unterstützung)";
        
        return [
            'lastCandle' => $lastCandle,
            'trend' => $trend,
            'macdTrend' => $macdTrend,
            'volumeLevel' => $volumeLevel,
            'obvTrend' => $obvTrend,
            'rsiTrend' => $rsiTrend,
            'rsiAngleText' => $rsiAngleText,
            'bollingerBandPosition' => $bollingerBandPosition
        ];
    }


    private static function generateRecommendation($lastCandle, $analysis, $backtestResults) {
        $trendIndicators = [
            'SMA' => [
                'trend' => $analysis['trend'], 
                'weight' => 0.3
            ],
            'RSI' => [
                'trend' => ($lastCandle['rsi'] < 50) ? "Abwärtstrend" : "Aufwärtstrend", 
                'weight' => 0.15
            ],
            'Bollinger Bänder' => [
                'trend' => ($lastCandle['close'] < $lastCandle['bb_middle']) ? "Abwärtstrend" : "Aufwärtstrend", 
                'weight' => 0.25
            ],
            'Volumen' => [
                'trend' => ($analysis['volumeLevel'] === "hoch") ? $analysis['trend'] : "Neutral", 
                'weight' => 0.1
            ],
            'MACD' => [
                'trend' => $analysis['macdTrend'], 
                'weight' => 0.1
            ],
            'OBV' => [
                'trend' => $analysis['obvTrend'], 
                'weight' => 0.1
            ]
        ];
        
        // Gewichtung anpassen basierend auf Backtesting-Ergebnissen
        $avgWinRate = ($backtestResults['short']['win_rate'] + $backtestResults['medium']['win_rate'] + $backtestResults['long']['win_rate']) / 3;
        foreach ($trendIndicators as &$indicator) {
            $indicator['weight'] *= (1 + ($avgWinRate - 0.5));
        }
        unset($indicator);
        
        $upCount = $downCount = 0;
        foreach ($trendIndicators as $indicator) {
            if ($indicator['trend'] === "Aufwärtstrend") $upCount += $indicator['weight'];
            elseif ($indicator['trend'] === "Abwärtstrend") $downCount += $indicator['weight'];
        }
        
        $confidenceThreshold = BacktestConfig::$confidenceThreshold;
        $totalWeight = $upCount + $downCount;
        $upRatio = $totalWeight > 0 ? $upCount / $totalWeight : 0;
        $downRatio = $totalWeight > 0 ? $downCount / $totalWeight : 0;
        
        if ($upRatio >= $confidenceThreshold) {
            return self::generateBuyRecommendation($lastCandle, $analysis, $upRatio);
        } elseif ($downRatio >= $confidenceThreshold) {
            return self::generateSellRecommendation($lastCandle, $analysis, $downRatio);
        } else {
            return self::generateNeutralRecommendation($lastCandle, $analysis);
        }
    }

    private static function generateBuyRecommendation($lastCandle, $analysis, $confidence) {
        $recommendation = "🟢 *Long-Signal (Kauf)* 🟢\n"
                       . "Confidence: " . round($confidence * 100) . "%\n"
                       . "Begründung:\n"
                       . "- SMA 50 > SMA 200: " . ($lastCandle['sma50'] > $lastCandle['sma200'] ? "Ja" : "Nein") . "\n"
                       . "- RSI liegt bei {$lastCandle['rsi']} (" 
                       . ($lastCandle['rsi'] > IndicatorSettings::$rsiOverbought ? "überkauft" : 
                         ($lastCandle['rsi'] < IndicatorSettings::$rsiOversold ? "überverkauft" : "neutral")) . ")\n"
                       . "- RSI-Trend: {$analysis['rsiTrend']} ({$analysis['rsiAngleText']})\n"
                       . "- Preis ist {$analysis['bollingerBandPosition']}\n"
                       . "- Volumen ist {$analysis['volumeLevel']}, was den Trend unterstützt\n"
                       . "- MACD zeigt einen Aufwärtstrend (MACD-Linie über der Signallinie)\n"
                       . "- OBV zeigt einen {$analysis['obvTrend']} an (" 
                       . ($analysis['obvTrend'] === "Aufwärtstrend" ? "Kaufdruck steigt" : "Verkaufsdruck steigt") . ")\n"
                       . "Empfehlung:\n"
                       . "- Kaufe mit einem Stop-Loss bei {$lastCandle['bb_lower']} USD.\n"
                       . "- Take-Profit bei {$lastCandle['bb_upper']} USD.\n"
                       . "- Maximales Risiko: 1% des Kontos.\n";
        
        TelegramBot::sendMessage($recommendation);
        return $recommendation;
    }

    private static function generateSellRecommendation($lastCandle, $analysis, $confidence) {
        $recommendation = "🔴 *Short-Signal (Verkauf)* 🔴\n"
                       . "Confidence: " . round($confidence * 100) . "%\n"
                       . "Begründung:\n"
                       . "- SMA 50 < SMA 200: " . ($lastCandle['sma50'] < $lastCandle['sma200'] ? "Ja" : "Nein") . "\n"
                       . "- RSI liegt bei {$lastCandle['rsi']} (" 
                       . ($lastCandle['rsi'] > IndicatorSettings::$rsiOverbought ? "überkauft" : 
                         ($lastCandle['rsi'] < IndicatorSettings::$rsiOversold ? "überverkauft" : "neutral")) . ")\n"
                       . "- RSI-Trend: {$analysis['rsiTrend']} ({$analysis['rsiAngleText']})\n"
                       . "- Preis ist {$analysis['bollingerBandPosition']}\n"
                       . "- Volumen ist {$analysis['volumeLevel']}, was den Trend unterstützt\n"
                       . "- MACD zeigt einen Abwärtstrend (MACD-Linie unter der Signallinie)\n"
                       . "- OBV zeigt einen {$analysis['obvTrend']} an (" 
                       . ($analysis['obvTrend'] === "Abwärtstrend" ? "Verkaufsdruck steigt" : "Kaufdruck steigt") . ")\n"
                       . "Empfehlung:\n"
                       . "- Verkaufe mit einem Stop-Loss bei {$lastCandle['bb_upper']} USD.\n"
                       . "- Take-Profit bei {$lastCandle['bb_lower']} USD.\n"
                       . "- Maximales Risiko: 1% des Kontos.\n";
        
        TelegramBot::sendMessage($recommendation);
        return $recommendation;
    }

    private static function generateNeutralRecommendation($lastCandle, $analysis) {
        return "🟡 *Neutral (Abwarten)* 🟡\n"
             . "Trendanalyse:\n"
             . "- Aktueller Trend: Kein klarer Trend\n"
             . "- Indikatoren, die den Trend unterstützen:\n"
             . "  - SMA 50 > SMA 200: " . ($lastCandle['sma50'] > $lastCandle['sma200'] ? "Ja" : "Nein") . "\n"
             . "  - RSI: " . ($lastCandle['rsi'] < 50 ? "Unterstützt Abwärtstrend" : "Unterstützt Aufwärtstrend") . "\n"
             . "  - Preis relativ zu Bollinger Bändern: " 
             . ($lastCandle['close'] < $lastCandle['bb_middle'] ? "Unterstützt Abwärtstrend" : "Unterstützt Aufwärtstrend") . "\n"
             . "  - Volumen: " . ($analysis['volumeLevel'] === "hoch" ? "Unterstützt Trend" : "Neutral") . "\n"
             . "  - MACD: " . ($analysis['macdTrend'] === "Aufwärtstrend" ? "Unterstützt Aufwärtstrend" : "Unterstützt Abwärtstrend") . "\n"
             . "  - OBV: " . ($analysis['obvTrend'] === "Aufwärtstrend" ? "Unterstützt Aufwärtstrend" : "Unterstützt Abwärtstrend") . "\n"
             . "Empfehlung: Abwarten, bis ein klares Signal vorliegt.\n";
    }



private static function outputResults($candlesticks, $lastCandle, $recommendation, $backtestResults = []) {
    // Nur Backtesting durchführen, wenn nicht bereits vorhanden
    if (empty($backtestResults) && count($candlesticks) > 200) {
        $backtestResults = TradingStrategy::runAdvancedBacktest(
            $candlesticks, 
            BacktestConfig::$periods, 
            BacktestConfig::$riskPerTrade
        );
    }

    $backtestInfo = TradingStrategy::outputBacktestResults($backtestResults);
    $fullRecommendation = $recommendation . $backtestInfo;

    if (php_sapi_name() !== 'cli') {
        self::outputHTML($candlesticks, $lastCandle, $fullRecommendation, $backtestResults);
    } else {
        self::outputCLI($candlesticks, $lastCandle, $fullRecommendation, $backtestResults);
    }
}

private static function outputHTML(array $candlesticks, array $lastCandle, string $recommendation, array $backtestResults) {
    global $mlTrader;

    if (!isset($mlTrader)) {
        $mlTrader = new MLTrader();
    }

    $volumeLevel = ($lastCandle['volume'] > 500)
        ? "hoch"
        : (($lastCandle['volume'] > 200) ? "mittel" : "niedrig");
    $obvTrend = ($lastCandle['obv'] > ($candlesticks[count($candlesticks) - 2]['obv'] ?? 0))
        ? "Aufwärtstrend"
        : "Abwärtstrend";
    $trend = ($lastCandle['sma50'] > $lastCandle['sma200'])
        ? "Aufwärtstrend"
        : "Abwärtstrend";

    // HTML‑Kopf
    echo "<!DOCTYPE html>
<html lang='de'>
<head>
  <meta charset='UTF-8'>
  <title>BTC/USDT Futures Analyse</title>
  <style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    h1 { color: #333; }
    table { border-collapse: collapse; width: 100%; max-width: 700px; margin-bottom: 20px; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    th { background-color: #f2f2f2; }
    .ml-signal { background-color: #f0f0ff; font-weight: bold; }
    .combined-signal { background-color: #f9f9f9; }
    .combined-debug { font-size: 0.8em; color: #666; }
    .recommendation { background-color: #e8f4f8; padding: 15px; border-left: 5px solid #2196F3; margin-top: 20px; }
    pre { white-space: pre-wrap; font-family: monospace; }
  </style>
</head>
<body>
  <h1>BTC/USDT Futures Analyse</h1>
  <p>Letzte Aktualisierung: " . date("Y-m-d H:i:s") . "</p>";

    // ML‑Status
    echo "<div style='padding:10px; background:#f0f0ff; border:1px solid #ccc; margin-bottom:20px;'>
            <strong>ML‑Status:</strong><br>
            Aktive Trainingsdatei: <code>" . htmlspecialchars($mlTrader->getTrainingFileName()) . "</code><br>
            Samples gespeichert: " . $mlTrader->getTrainingSampleCount() . "<br>
            Modell trainiert? " . ($mlTrader->isModelTrained() ? "Ja" : "Nein") . "
          </div>";

    // Tabelle starten
    echo "<table>
            <tr><th>Parameter</th><th>Wert</th></tr>
            <tr><td>Verarbeitete Candlesticks</td><td>" . count($candlesticks) . "</td></tr>
            <tr><td>Letzter Preis</td><td>{$lastCandle['close']} USD</td></tr>
            <tr><td>Mark Price</td><td>{$lastCandle['markPrice']} USD</td></tr>
            <tr><td>RSI</td><td>{$lastCandle['rsi']}</td></tr>
            <tr><td>SMA (20)</td><td>{$lastCandle['sma']}</td></tr>
            <tr><td>SMA (50)</td><td>{$lastCandle['sma50']}</td></tr>
            <tr><td>SMA (200)</td><td>{$lastCandle['sma200']}</td></tr>
            <tr><td>Trend</td><td>{$trend}</td></tr>
            <tr><td>Bollinger Bänder</td><td>
              Oberes Band: {$lastCandle['bb_upper']}<br>
              Mittleres Band: {$lastCandle['bb_middle']}<br>
              Unteres Band: {$lastCandle['bb_lower']}
            </td></tr>
            <tr><td>MACD</td><td>
              MACD-Linie: {$lastCandle['macdLine']}<br>
              Signallinie: {$lastCandle['signalLine']}<br>
              Histogramm: {$lastCandle['histogram']}
            </td></tr>
            <tr><td>Fibonacci</td><td>
              23.6%: {$lastCandle['fibonacci']['23.6']}<br>
              38.2%: {$lastCandle['fibonacci']['38.2']}<br>
              50%:   {$lastCandle['fibonacci']['50']}<br>
              61.8%: {$lastCandle['fibonacci']['61.8']}<br>
              78.6%: {$lastCandle['fibonacci']['78.6']}
            </td></tr>
            <tr><td>Volumen</td><td>{$lastCandle['volume']} BTC ({$volumeLevel})</td></tr>
            <tr><td>OBV</td><td>{$lastCandle['obv']} ({$obvTrend})</td></tr>";

    // ML‑ und kombiniertes Signal
    try {
        // Reines ML‑Signal
        $mlResult   = $mlTrader->predictWithConfidence($lastCandle);
        $mlSignal   = $mlResult['prediction'];
        $confidence = round($mlResult['confidence'] * 100);
        $symbol     = match (strtolower($mlSignal)) {
            'long'  => '🟢',
            'short' => '🔴',
            default => '⚪',
        };
        echo "
            <tr class='ml-signal'>
              <td><strong>ML‑Signal (Rubix ML)</strong></td>
              <td>{$symbol} <strong>" . strtoupper($mlSignal) . "</strong> – Confidence: {$confidence}%</td>
            </tr>
        ";
		
		// 1) die Vor‑Kerze holen
$prevCandle = $candlesticks[count($candlesticks) - 2] ?? [];
// 2) ihren RSI in $lastCandle ablegen
$lastCandle['prevRsi'] = $prevCandle['rsi'] ?? $lastCandle['rsi'] ?? 0;
// 3) jetzt das kombinierte Signal berechnen
$combined = $mlTrader->getCombinedSignal($lastCandle);
$dbg      = $mlTrader->getCombinedDebug();


        // Kombiniertes Signal
// 1.1) Hole die vorletzte Kerze
$prevCandle = $candlesticks[count($candlesticks) - 2];

// 1.2) Pack den vorherigen RSI in $lastCandle
$lastCandle['prevRsi'] = $prevCandle['rsi'] ?? $lastCandle['rsi'];

// 1.3) Jetzt das kombinierte Signal holen
$combined = $mlTrader->getCombinedSignal($lastCandle);

// Debug‑Infos weiter unten anzeigen
$dbg = $mlTrader->getCombinedDebug();
$combinedSymbol = $combined === 'long'  ? '🟢'
                 : ($combined === 'short' ? '🔴' : '🟡');
echo "
    <tr class='combined-signal'>
      <td><strong>Kombiniertes Signal</strong></td>
      <td>{$combinedSymbol} <strong>" . strtoupper($combined) . "</strong> – ML‑Confidence: {$confidence}%</td>
    </tr>
    <tr class='combined-debug'>
      <td colspan='2'>Debug: " . htmlspecialchars(json_encode($dbg)) . "</td>
    </tr>
";

    } catch (Exception $e) {
        echo "
            <tr class='ml-signal'>
              <td><strong>ML‑Signal (Fehler)</strong></td>
              <td>⚠️ Vorhersage fehlgeschlagen</td>
            </tr>
        ";
        error_log("ML Error: " . $e->getMessage());
    }

    // Tabelle und Empfehlung schließen
    echo "</table>
          <div class='recommendation'>
            <h2>Handelsempfehlung</h2>
            <pre>" . htmlspecialchars($recommendation) . "</pre>
          </div>";

    // Trainings‑Button & Zeitstempel
    $stampFile = __DIR__ . '/last_train_time.txt';
    $lastTime = file_exists($stampFile)
        ? date('Y-m-d H:i:s', (int)file_get_contents($stampFile))
        : 'nie';

    echo "
      <form method='post' style='margin-top:10px;'>
        <button type='submit' name='ml_train' value='1'>ML‑Modell trainieren</button>
      </form>
      <p style='font-size:0.9em; color:#666;'>Letztes Training: {$lastTime}</p>
</body>
</html>";
}


private static function outputCLI($candlesticks, $lastCandle, $recommendation) {
    global $mlTrader;
    
    echo "=== BTC/USDT Futures Analyse ===\n";
	echo "🎛️ Optimierungsmodus: " . (USE_OPTIMIZED_PARAMS ? "Automatisch (Backtest)" : "Manuell (Feste Werte)") . "\n";
    echo "Verarbeitete Candlesticks: " . count($candlesticks) . "\n";
    echo "Letzter Preis: {$lastCandle['close']} USD\n";
    echo "Mark Price: {$lastCandle['markPrice']} USD\n";
    echo "RSI: {$lastCandle['rsi']}\n";
    echo "SMA (20): {$lastCandle['sma']}\n";
    echo "SMA (50): {$lastCandle['sma50']}\n";
    echo "SMA (200): {$lastCandle['sma200']}\n";
    echo "Trend: " . ($lastCandle['sma50'] > $lastCandle['sma200'] ? "Aufwärtstrend" : "Abwärtstrend") . "\n";
    echo "Bollinger Bänder:\n";
    echo "  Oberes Band: {$lastCandle['bb_upper']}\n";
    echo "  Mittleres Band: {$lastCandle['bb_middle']}\n";
    echo "  Unteres Band: {$lastCandle['bb_lower']}\n";
    echo "MACD:\n";
    echo "  MACD-Linie: {$lastCandle['macdLine']}\n";
    echo "  Signallinie: {$lastCandle['signalLine']}\n";
    echo "  Histogramm: {$lastCandle['histogram']}\n";
    echo "Fibonacci-Retracements:\n";
    echo "  23.6%: {$lastCandle['fibonacci']['23.6']}\n";
    echo "  38.2%: {$lastCandle['fibonacci']['38.2']}\n";
    echo "  50%: {$lastCandle['fibonacci']['50']}\n";
    echo "  61.8%: {$lastCandle['fibonacci']['61.8']}\n";
    echo "  78.6%: {$lastCandle['fibonacci']['78.6']}\n";
    echo "Volumen: {$lastCandle['volume']} BTC (" . (($lastCandle['volume'] > 500) ? "hoch" : (($lastCandle['volume'] > 200) ? "mittel" : "niedrig")) . ")\n";
    echo "OBV: {$lastCandle['obv']} (" . (($lastCandle['obv'] > ($candlesticks[count($candlesticks)-2]['obv'] ?? 0)) ? "Aufwärtstrend" : "Abwärtstrend") . ")\n";
    echo "=============================\n";
    
// ML-Signal mit Wrapper und Confidence
if ($mlTrader instanceof MLTrader) {
    try {
        $mlResult   = $mlTrader->predictWithConfidence($lastCandle);
        $mlSignal   = $mlResult['prediction'];
        $confidence = round($mlResult['confidence'] * 100);

        if ($mlSignal !== 'neutral') {
            echo "🤖 ML-SIGNAL: " . strtoupper($mlSignal)
               . " – Confidence: {$confidence}%\n";
            echo "-------------------------\n";
			
			// Kombiniertes Signal (CLI)
$prevCandle = $candlesticks[count($candlesticks) - 2] ?? [];
// Prev‑RSI ins $lastCandle packen, damit getCombinedSignal() es lesen kann
$lastCandle['prevRsi'] = $prevCandle['rsi'] ?? $lastCandle['rsi'] ?? 0;

$combined       = $mlTrader->getCombinedSignal($lastCandle);
$dbg            = $mlTrader->getCombinedDebug();
$combinedSymbol = $combined === 'long'  ? '🟢'
                 : ($combined === 'short' ? '🔴' : '🟡');

echo "{$combinedSymbol} Kombiniertes Signal: "
   . strtoupper($combined)
   . " – Debug: " . json_encode($dbg) . "\n\n";

        }
    } catch (Exception $e) {
        Helper::logError("ML Prediction failed: " . $e->getMessage());
    }
}
    
    echo "Handelsempfehlung:\n";
    echo $recommendation . "\n";
}
	}

// Hauptausführungsklasse
final class TradingApp {
    private static $mlTrader;
    
    public static function run() {
        self::initialize();
        
        if (self::isCliMode()) {
            self::runCli();
        } else {
            self::runWeb();
        }
    }
    
    private static function isCliMode(): bool {
        return php_sapi_name() === 'cli';
    }
        private static function initialize(): void {
        try {
            require_once __DIR__.'/ml_trader.php';
            self::$mlTrader = new MLTrader();
            $GLOBALS['mlTrader'] = self::$mlTrader;
        } catch (Exception $e) {
            Helper::logError("MLTrader Initialisierung fehlgeschlagen: " . $e->getMessage());
            if (self::isCliMode()) {
                die("Kritischer Fehler: MLTrader konnte nicht initialisiert werden\n");
            }
        }
    }

    
    private static function runCli(): void {
        while (true) {
            try {
                TradingAnalysis::performAnalysis();
            } catch (Exception $e) {
                Helper::logError("Analysefehler: " . $e->getMessage());
                self::reinitialize();
            }
            sleep(30);
        }
    }
    
    private static function runWeb(): void {
        TradingAnalysis::performAnalysis();
    }
    
    private static function reinitialize(): void {
        try {
            self::$mlTrader = new MLTrader();
            $GLOBALS['mlTrader'] = self::$mlTrader;
        } catch (Exception $e) {
            Helper::logError("MLTrader Re-Initialisierung fehlgeschlagen: " . $e->getMessage());
            sleep(60);
        }
    }
}

// Einstellbare Zeit in Sekunden (Standard: 30 Sekunden)
$refreshInterval = 30;

// Browser Auto-Refresh
if (php_sapi_name() !== 'cli') {
    header("Refresh: $refreshInterval");
}

while (true) {
    // Skript ausführen
    TradingApp::run();

    // CLI-Ausgabe für Status
    if (php_sapi_name() === 'cli') {
        echo "Letzte Aktualisierung: " . date('Y-m-d H:i:s') . "\n";
        echo "Nächste Aktualisierung in $refreshInterval Sekunden...\n";
        sleep($refreshInterval);
    } else {
        // Browser-Ausgabe mit Refresh-Hinweis
        echo "<p>Letzte Aktualisierung: " . date('Y-m-d H:i:s') . "</p>";
        echo "<p>Nächste Aktualisierung in $refreshInterval Sekunden...</p>";
        exit;
    }
}

