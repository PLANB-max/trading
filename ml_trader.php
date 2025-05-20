<?php
require_once __DIR__ . '/../vendor/autoload.php';


use Rubix\ML\PersistentModel;
use Rubix\ML\Persisters\Filesystem;
use Rubix\ML\Datasets\Unlabeled;
use Rubix\ML\Datasets\Labeled;
use Rubix\ML\Classifiers\RandomForest;
use Rubix\ML\Classifiers\ClassificationTree;

class MLTrader {
    private $model;
    private $lastPrediction;
    private $predictionHistory = [];
    private $confidenceThreshold;
    private $isTrained = false;
    private $lastCombinedDebug = [];
    private string $trainingDataFile = 'ml_training_classic.json';
    private string $modelFile = 'trading_model.rbx';
    private bool $useMTFModel = false;

public function __construct(bool $useMTFModel = false, string $modelFile = null) {
    $this->useMTFModel = $useMTFModel;
    $this->trainingDataFile = $useMTFModel ? 'ml_training_mtf.json' : 'ml_training_classic.json';

    // Fix: Setze Model-Datei passend zum MTF-Status, wenn kein Modell explizit übergeben wurde
    if ($modelFile) {
        $this->modelFile = $modelFile;
    } else {
        $this->modelFile = $useMTFModel ? 'trading_model_mtf.rbx' : 'trading_model_classic.rbx';
    }

    $this->initializeModel();
    $this->confidenceThreshold = defined('ML_CONFIDENCE_THRESHOLD') ? ML_CONFIDENCE_THRESHOLD : 0.65;
}


    public function overrideTrainingFile(string $filename): void {
        $this->trainingDataFile = $filename;
    }

private function initializeModel() {
    try {
        if (file_exists($this->modelFile)) {
            $this->model = PersistentModel::load(new Filesystem($this->modelFile));
            $this->isTrained = true;
        } else {
            $baseLearner = new ClassificationTree(10, 3);
            $this->model = new PersistentModel(
                new RandomForest($baseLearner, 100, 0.5, true),
                new Filesystem($this->modelFile)
            );
            $this->isTrained = false;
        }
    } catch (Exception $e) {
        echo "\n⚠️ ML Initialization Fallback: " . $e->getMessage() . "\n";
        $this->model = $this->createFallbackModel();
        $this->isTrained = false;
    }
}



    public function predictWithConfidence(array $candle): array {
        $sample = $this->useMTFModel
            ? $this->buildMTFSample($candle)
            : $this->buildClassicSample($candle);

        $prediction = $this->predict($sample);
        $confidence = $this->lastPrediction['confidence'] ?? 0.5;

        if (defined('DEBUG_ML') && DEBUG_ML) {
            echo "<pre>🤖 ML Sample: ";
            print_r($sample);
            echo "Prediction: $prediction | Confidence: $confidence</pre>";
        }

        return [
            'prediction' => $prediction,
            'confidence' => $confidence,
            'threshold' => $this->confidenceThreshold
        ];
    }
	
	    
    public function predict(array $features): string {
        try {
            if (!$this->isTrained || !$this->model) return 'neutral';
            $dataset = new Unlabeled([$features]);
            $probs = $this->model->proba($dataset)[0];
            $prediction = array_search(max($probs), $probs);
            $confidence = max($probs);
            $this->storePrediction($prediction, $features, $confidence);
            return $confidence >= $this->confidenceThreshold ? $prediction : 'neutral';
        } catch (Exception $e) {
            error_log("Prediction Error: " . $e->getMessage());
            return 'neutral';
        }
    }

    private function buildClassicSample(array $candle): array {
        return [
            round($candle['rsi'] ?? 0, 2),
            round($candle['sma_50'] ?? $candle['sma50'] ?? 0, 2),
            round($candle['macdLine'] ?? 0, 3),
            ($candle['sma_50'] ?? 0) > ($candle['sma_200'] ?? 0) ? 1 : 0
        ];
    }

    private function buildMTFSample(array $candle): array {
        return [
            round($candle['rsi_1m_slope'] ?? 0, 3),
            round($candle['rsi_5m_slope'] ?? 0, 3),
            round($candle['macdLine'] ?? 0, 3),
            round($candle['signalLine'] ?? 0, 3),
            ($candle['ema_10_15m'] ?? 0) > ($candle['ema_21_15m'] ?? 0) ? 1 : 0
        ];
    }

public function storeTrainingSample(array $features, string $label): void {
    // 1) Sample direkt aus den übergebenen Features und Label bauen
    $sample = [
        'features' => $features,
        'label'    => $label,
    ];

    // 2) Existierende Samples laden
    $data = [];
    if (file_exists($this->trainingDataFile)) {
        $json = file_get_contents($this->trainingDataFile);
        $data = json_decode($json, true) ?: [];
    }

    // 3) Neues Sample anhängen
    $data[] = $sample;

    // 4) Zurückschreiben
    file_put_contents(
        $this->trainingDataFile,
        json_encode($data, JSON_PRETTY_PRINT)
    );
}


   public function trainFromStoredSamples(): bool {
    if (!file_exists($this->trainingDataFile)) {
        return false;
    }
    $data = json_decode(file_get_contents($this->trainingDataFile), true);
    if (!is_array($data) || empty($data)) {
        return false;
    }

    // 1) Samples nach Label gruppieren
    $groups = [];
    foreach ($data as $entry) {
        $groups[$entry['label']][] = $entry;
    }

    // 2) Kleinstes Label‑Cluster ermitteln
    $counts   = array_map('count', $groups);
    $minCount = min($counts);

    // 3) Jedes Label auf gleichen Umfang zuschneiden (Undersampling)
    $balanced = [];
    foreach ($groups as $lbl => $entries) {
        shuffle($entries);
        $balanced = array_merge($balanced, array_slice($entries, 0, $minCount));
    }

    // 4) Gesamten Datensatz mischen
    shuffle($balanced);

    // 5) Features und Labels extrahieren
    $samples = array_column($balanced, 'features');
    $labels  = array_column($balanced, 'label');

    // 6) Modell trainieren
    return $this->trainModel($samples, $labels);
}

    public function trainModel(array $samples, array $labels): bool {
        try {
            if (!$this->model instanceof PersistentModel) throw new Exception("Kein trainierbares ML-Modell verfügbar.");
            if (count($samples) !== count($labels)) throw new Exception("Samples und Labels sind unterschiedlich lang.");
            $dataset = new Labeled($samples, $labels);
            $this->model->train($dataset);
			$this->model->save(new Filesystem($this->modelFile));
            $this->isTrained = true;
            return true;
        } catch (Exception $e) {
            error_log("Training Error: " . $e->getMessage());
            echo "⚠️ Training fehlgeschlagen: " . $e->getMessage() . "\n";
            return false;
        }
    }

public function getCombinedSignal(array $candle): string
{
    // ML‑Prediction + Confidence
    $mlRes      = $this->predictWithConfidence($candle);
    $prediction = $mlRes['prediction'];
    $confidence = $mlRes['confidence'];

    // === SMA‑Trend ===
    $smaTrend = isset($candle['sma50'], $candle['sma200'])
              && $candle['sma50'] > $candle['sma200'];

    // === Neuer RSI‑Trend ===
    // true, wenn aktueller RSI größer als prevRsi
    $rsiTrend = ($candle['rsi']  ?? 0) > ($candle['prevRsi'] ?? 0);

    // für Debug
    $this->lastCombinedDebug = compact(
        'prediction',
        'confidence',
        'smaTrend',
        'rsiTrend'
    );

    // === Kombinations‑Logik ===
    if ($prediction === 'long'
     && $confidence >= $this->confidenceThreshold
     && ($smaTrend || $rsiTrend)
	//&& ($smaTrend && $rsiTrend)
	
		
    ) {
        return 'long';
    }

    if ($prediction === 'short'
     && $confidence >= $this->confidenceThreshold
     && (!$smaTrend || !$rsiTrend)
	//&& (!$smaTrend && !$rsiTrend)
	
    ) {
        return 'short';
    }

    return 'neutral';
}



    private function storePrediction(string $prediction, array $features, float $confidence): void {
        $this->lastPrediction = [
            'prediction' => $prediction,
            'confidence' => $confidence,
            'timestamp' => time(),
            'features' => $features
        ];
        $this->predictionHistory[] = $this->lastPrediction;
        if (count($this->predictionHistory) > 100) {
            array_shift($this->predictionHistory);
        }
    }

    public function getCombinedDebug(): array {
        return $this->lastCombinedDebug;
    }

    public function isModelTrained(): bool {
        return $this->isTrained;
    }

    public function getLastPrediction(): array {
        return $this->lastPrediction ?? [
            'prediction' => 'neutral',
            'confidence' => 0,
            'timestamp' => time()
        ];
    }

    public function getTrainingSampleCount(): int {
        if (!file_exists($this->trainingDataFile)) return 0;
        $data = json_decode(file_get_contents($this->trainingDataFile), true);
        return is_array($data) ? count($data) : 0;
    }

    public function getTrainingFileName(): string {
        return $this->trainingDataFile;
    }
	    public static function renderTrainingSection(): string
    {
        if (!MLBacktestStrategy::$useML) {
            return '';
        }

        $trainingFile = MLBacktestStrategy::$useMTFModel ? 'ml_training_mtf.json' : 'ml_training_classic.json';
        $mlInstance = new MLTrader(MLBacktestStrategy::$useMTFModel);
        $mlInstance->overrideTrainingFile($trainingFile);
        $count = $mlInstance->getTrainingSampleCount();
        $modelType = MLBacktestStrategy::$useMTFModel ? "🔀 Multi-Timeframe (MTF)" : "🧠 Klassisches ML";

        ob_start();

        echo "<form method='GET' style='margin-top:20px; padding:10px; border:1px solid #ccc; border-radius:6px; background:#f9f9f9; max-width:550px;'>";
        echo "<fieldset style='border:none;'>";
        echo "<legend style='font-weight:bold;'>⚙️ Modell-Einstellungen</legend>";
        echo "<label style='margin-right:20px;'>
                <input type='checkbox' name='ml' value='1' " . (MLBacktestStrategy::$useML ? "checked" : "") . ">
                <strong>ML aktivieren</strong>
              </label>";
        echo "<label style='margin-right:20px;'>
                <input type='checkbox' name='mtf' value='1' " . (MLBacktestStrategy::$useMTFModel ? "checked" : "") . ">
                <strong>Multi-Timeframe-Modell (MTF)</strong>
              </label>";
        echo "<button type='submit' style='padding:6px 12px; background:#2196F3; color:#fff; border:none; border-radius:4px; cursor:pointer;'>Übernehmen</button>";
        echo "</fieldset>";
        echo "<div style='margin-top:10px;'><b>Aktives Modell:</b> $modelType</div>";
        echo "</form>";

        echo "<form method='post' style='margin-top:-10px;'>";
        echo "<div style='margin-top:10px;'><b>Trainingsdatei:</b> <code>" . htmlspecialchars($trainingFile) . "</code></div>";
        echo "<div style='margin-top:10px;'><b>Trainingsdaten vorhanden:</b> $count Samples (Quelle: <code>$trainingFile</code>)</div>";
        echo "<button name='ml_train' style='padding: 8px 12px; margin-top:10px;'>📈 Jetzt ML trainieren ($count Samples)</button>";
        echo "<button name='ml_train_reset' style='padding: 8px 12px; background:#f44336; color:white; margin-left:10px;'>🧹 Trainingsdaten zurücksetzen</button>";
        echo "</form>";

        return ob_get_clean();
    }

}
