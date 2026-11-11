<?php

namespace App\Console\Commands;

use App\Support\Ispu;
use App\Support\KnnPredictor;
use App\Support\Ml\DecisionTree;
use App\Support\Ml\Metrics;
use App\Support\Ml\NaiveBayes;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Evaluasi lengkap untuk Bab 4: k-fold cross-validation, perbandingan model,
 * pemilihan k, confusion matrix, precision/recall/F1 per kelas, dan permutation importance.
 * Hasil disimpan di storage/app/model_eval.json dan ditampilkan di halaman /evaluasi.
 */
class EvaluateModels extends Command
{
    protected $signature = 'ispu:evaluate-models
        {--folds=5 : Jumlah fold cross-validation}
        {--kmax=30 : Nilai k terbesar yang diuji}
        {--perm=500 : Jumlah data uji untuk permutation importance}';

    protected $description = 'Bandingkan kNN, Decision Tree, Naive Bayes, baseline, dan rumus resmi ISPU (k-fold CV)';

    public const FILE = 'app/model_eval.json';

    public const WEATHER = ['temperature', 'humidity', 'wind_speed', 'rainfall'];

    public const MODELS = [
        'baseline' => 'Baseline (kelas terbanyak / rata-rata)',
        'nb' => 'Gaussian Naive Bayes',
        'tree' => 'Decision Tree (CART, kedalaman 8)',
        'knn' => 'kNN berbobot jarak',
        'formula' => 'Rumus resmi ISPU (bukan ML)',
    ];

    private array $yCat = [];
    private array $yTier = [];
    private array $yIspu = [];
    private array $yBurden = [];

    public function handle(): int
    {
        $started = microtime(true);
        $folds = max(2, (int) $this->option('folds'));
        $kmax = max(1, (int) $this->option('kmax'));
        $allFeatures = array_keys(KnnPredictor::FEATURES);

        $scenarios = [
            'semua' => ['label' => 'Semua fitur (6 polutan + 4 cuaca)', 'features' => $allFeatures],
            'pm25_cuaca' => ['label' => 'PM2.5 + cuaca (sensor terbatas)', 'features' => array_merge(['pm25'], self::WEATHER)],
            'cuaca' => ['label' => 'Cuaca saja (tanpa sensor polutan)', 'features' => self::WEATHER],
        ];

        $rows = DB::table('observations')
            ->select(array_merge($allFeatures, ['ispu', 'ispu_category', 'risk_tier', 'ispa_burden']))
            ->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        if (count($rows) < $folds * 10) {
            $this->error('Data terlalu sedikit. Jalankan php artisan ispu:import dulu.');

            return self::FAILURE;
        }

        mt_srand(42);
        shuffle($rows);
        $n = count($rows);
        $fold = [];
        for ($i = 0; $i < $n; $i++) {
            $fold[$i] = $i % $folds;
        }

        $this->yCat = array_column($rows, 'ispu_category');
        $this->yTier = array_column($rows, 'risk_tier');
        $this->yIspu = array_map('floatval', array_column($rows, 'ispu'));
        $this->yBurden = array_map('floatval', array_column($rows, 'ispa_burden'));

        $labels = [
            'category' => array_values(array_filter(array_keys(Ispu::CATEGORIES), fn ($c) => in_array($c, $this->yCat, true))),
            'tier' => array_values(array_unique($this->yTier)),
        ];
        sort($labels['tier']);
        $truth = ['category' => $this->yCat, 'tier' => $this->yTier, 'ispu' => $this->yIspu, 'burden' => $this->yBurden];

        $out = [
            'meta' => [
                'rows' => $n,
                'folds' => $folds,
                'kmax' => $kmax,
                'seed' => 42,
                'features' => collect(KnnPredictor::FEATURES)->map(fn ($f) => $f[0])->all(),
            ],
            'scenarios' => collect($scenarios)->map(fn ($s) => [
                'label' => $s['label'],
                'features' => array_map(fn ($f) => KnnPredictor::FEATURES[$f][0], $s['features']),
            ])->all(),
            'models' => self::MODELS,
            'distribution' => [
                'category' => array_map(fn ($c) => count(array_keys($this->yCat, $c, true)), array_combine($labels['category'], $labels['category'])),
                'tier' => array_map(fn ($c) => count(array_keys($this->yTier, $c, true)), array_combine($labels['tier'], $labels['tier'])),
            ],
        ];

        // Seberapa cocok kolom ISPU dataset dengan rumus resmi?
        $match = 0;
        $dominant = [];
        foreach ($rows as $i => $r) {
            $f = Ispu::fromPollutants($r);
            $match += abs(round($f['ispu']) - $this->yIspu[$i]) <= 1;
            $dominant[KnnPredictor::FEATURES[$f['dominant']][0] ?? '-'] = ($dominant[KnnPredictor::FEATURES[$f['dominant']][0] ?? '-'] ?? 0) + 1;
        }
        arsort($dominant);
        $out['formula_check'] = ['match_rate' => round($match / $n, 4), 'dominant' => $dominant];

        $perm = null;
        foreach ($scenarios as $key => $sc) {
            $this->info("\nSkenario: {$sc['label']}");
            $F = $sc['features'];
            $hasPollutant = (bool) array_intersect($F, array_keys(Ispu::BREAKPOINTS));
            $raw = array_map(fn ($r) => array_map(fn ($f) => (float) $r[$f], $F), $rows);

            $pred = [];       // [model][target][i]
            $knn = [];        // [target][k][i]
            $bar = $this->output->createProgressBar($n);

            for ($g = 0; $g < $folds; $g++) {
                $tr = $te = [];
                foreach ($fold as $i => $fi) {
                    if ($fi === $g) {
                        $te[] = $i;
                    } else {
                        $tr[] = $i;
                    }
                }
                $Xs = $this->standardize($raw, $tr);
                $Xtr = array_map(fn ($i) => $Xs[$i], $tr);
                $pick = fn (array $y) => array_map(fn ($i) => $y[$i], $tr);

                $majCat = $this->mode($pick($this->yCat));
                $majTier = $this->mode($pick($this->yTier));
                $meanIspu = array_sum($pick($this->yIspu)) / count($tr);
                $meanBurden = array_sum($pick($this->yBurden)) / count($tr);
                $nbCat = (new NaiveBayes)->fit($Xtr, $pick($this->yCat));
                $nbTier = (new NaiveBayes)->fit($Xtr, $pick($this->yTier));
                $dtCat = (new DecisionTree('class'))->fit($Xtr, $pick($this->yCat));
                $dtTier = (new DecisionTree('class'))->fit($Xtr, $pick($this->yTier));
                $dtIspu = (new DecisionTree('reg'))->fit($Xtr, $pick($this->yIspu));
                $dtBurden = (new DecisionTree('reg'))->fit($Xtr, $pick($this->yBurden));

                foreach ($te as $i) {
                    $x = $Xs[$i];
                    $pred['baseline']['category'][$i] = $majCat;
                    $pred['baseline']['tier'][$i] = $majTier;
                    $pred['baseline']['ispu'][$i] = $meanIspu;
                    $pred['baseline']['burden'][$i] = $meanBurden;
                    $pred['nb']['category'][$i] = $nbCat->predict($x);
                    $pred['nb']['tier'][$i] = $nbTier->predict($x);
                    $pred['tree']['category'][$i] = $dtCat->predict($x);
                    $pred['tree']['tier'][$i] = $dtTier->predict($x);
                    $pred['tree']['ispu'][$i] = $dtIspu->predict($x);
                    $pred['tree']['burden'][$i] = $dtBurden->predict($x);
                    if ($hasPollutant) {
                        $f = round(Ispu::fromPollutants(array_combine($F, $raw[$i]))['ispu']);
                        $pred['formula']['ispu'][$i] = $f;
                        $pred['formula']['category'][$i] = Ispu::category($f);
                    }
                    $this->knnAllK($this->neighbors($Xs, $tr, $x, $kmax), $i, $knn);
                    $bar->advance();
                }

                if ($key === 'semua' && $g === 0) {
                    $perm = ['Xs' => $Xs, 'tr' => $tr, 'te' => array_slice($te, 0, (int) $this->option('perm'))];
                }
            }
            $bar->finish();

            // ---- Pilih k terbaik: rata-rata macro-F1 kategori dan tingkat risiko ----
            $curve = ['k' => [], 'category_acc' => [], 'category_f1' => [], 'tier_acc' => [], 'tier_f1' => [], 'ispu_mae' => [], 'burden_mae' => []];
            $bestK = 1;
            $bestScore = -1;
            for ($k = 1; $k <= $kmax; $k++) {
                $c = Metrics::classification($this->ordered($this->yCat), $this->ordered($knn['category'][$k]), $labels['category']);
                $t = Metrics::classification($this->ordered($this->yTier), $this->ordered($knn['tier'][$k]), $labels['tier']);
                $curve['k'][] = $k;
                $curve['category_acc'][] = round($c['accuracy'], 4);
                $curve['category_f1'][] = round($c['macro_f1'], 4);
                $curve['tier_acc'][] = round($t['accuracy'], 4);
                $curve['tier_f1'][] = round($t['macro_f1'], 4);
                $curve['ispu_mae'][] = round(Metrics::regression($this->ordered($this->yIspu), $this->ordered($knn['ispu'][$k]))['mae'], 3);
                $curve['burden_mae'][] = round(Metrics::regression($this->ordered($this->yBurden), $this->ordered($knn['burden'][$k]))['mae'], 4);
                $score = ($c['macro_f1'] + $t['macro_f1']) / 2;
                if ($score > $bestScore + 1e-9) {
                    $bestScore = $score;
                    $bestK = $k;
                }
            }
            foreach (['category', 'tier', 'ispu', 'burden'] as $target) {
                $pred['knn'][$target] = $knn[$target][$bestK];
            }
            unset($knn);
            $out['best_k'][$key] = $bestK;
            if ($key === 'semua') {
                $out['k_curve'] = $curve;
            }

            // ---- Klasifikasi: rata-rata ± simpangan baku antar fold ----
            foreach (['category', 'tier'] as $target) {
                foreach (array_keys(self::MODELS) as $model) {
                    if (! isset($pred[$model][$target])) {
                        continue;
                    }
                    $acc = $f1 = [];
                    for ($g = 0; $g < $folds; $g++) {
                        $idx = array_keys($fold, $g, true);
                        $m = Metrics::classification(
                            array_map(fn ($i) => $truth[$target][$i], $idx),
                            array_map(fn ($i) => $pred[$model][$target][$i], $idx),
                            $labels[$target],
                        );
                        $acc[] = $m['accuracy'];
                        $f1[] = $m['macro_f1'];
                    }
                    [$am, $as] = Metrics::meanStd($acc);
                    [$fm, $fs] = Metrics::meanStd($f1);
                    $out['classification'][$target][$key][$model] = [
                        'acc' => round($am, 4), 'acc_std' => round($as, 4),
                        'f1' => round($fm, 4), 'f1_std' => round($fs, 4),
                    ];
                }
            }

            // ---- Regresi (gabungan semua fold) ----
            foreach (['ispu', 'burden'] as $target) {
                foreach (array_keys(self::MODELS) as $model) {
                    if (! isset($pred[$model][$target])) {
                        continue;
                    }
                    $m = Metrics::regression($this->ordered($truth[$target]), $this->ordered($pred[$model][$target]));
                    $out['regression'][$target][$key][$model] = [
                        'mae' => round($m['mae'], 4), 'rmse' => round($m['rmse'], 4), 'r2' => round($m['r2'], 4),
                    ];
                }
            }

            // ---- Detail per kelas untuk model kNN dengan semua fitur ----
            if ($key === 'semua') {
                foreach (['category', 'tier'] as $target) {
                    $m = Metrics::classification($this->ordered($truth[$target]), $this->ordered($pred['knn'][$target]), $labels[$target]);
                    $out['detail'][$target] = [
                        'labels' => $labels[$target],
                        'matrix' => $m['matrix'],
                        'per_class' => $m['per_class'],
                        'accuracy' => round($m['accuracy'], 4),
                        'macro_f1' => round($m['macro_f1'], 4),
                    ];
                }
            }
        }

        // ---- Permutation importance (kNN, semua fitur, fold pertama) ----
        $this->info("\nPermutation importance");
        $out['importance'] = $this->importance($perm, $out['best_k']['semua'], $allFeatures);

        $out['meta']['evaluated_at'] = now()->toDateTimeString();
        $out['meta']['duration_s'] = round(microtime(true) - $started, 1);
        file_put_contents(storage_path(self::FILE), json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->newLine(2);
        $this->line('k terbaik (semua fitur): '.$out['best_k']['semua']);
        $rowsOut = [];
        foreach ($out['classification']['tier']['semua'] as $model => $m) {
            $c = $out['classification']['category']['semua'][$model] ?? null;
            $rowsOut[] = [self::MODELS[$model], $c ? $c['acc'] : '-', $c ? $c['f1'] : '-', $m['acc'], $m['f1']];
        }
        $this->table(['Model (semua fitur)', 'Akurasi kategori', 'Macro-F1 kategori', 'Akurasi risiko', 'Macro-F1 risiko'], $rowsOut);
        $this->line("Disimpan ke storage/".self::FILE." ({$out['meta']['duration_s']} detik)");

        return self::SUCCESS;
    }

    /** z-score dengan mean/std dari data latih saja (mencegah kebocoran data uji). */
    private function standardize(array $raw, array $train): array
    {
        $d = count($raw[0]);
        $mean = $std = [];
        for ($j = 0; $j < $d; $j++) {
            $s = $q = 0.0;
            foreach ($train as $i) {
                $s += $raw[$i][$j];
                $q += $raw[$i][$j] ** 2;
            }
            $m = $s / count($train);
            $mean[$j] = $m;
            $std[$j] = sqrt(max(0, $q / count($train) - $m * $m)) ?: 1.0;
        }

        return array_map(function ($x) use ($mean, $std) {
            foreach ($x as $j => $v) {
                $x[$j] = ($v - $mean[$j]) / $std[$j];
            }

            return $x;
        }, $raw);
    }

    /** @return array<int, float> indeks data latih => kuadrat jarak, terurut, sebanyak $k */
    private function neighbors(array $Xs, array $train, array $q, int $k): array
    {
        $d = [];
        foreach ($train as $j) {
            $s = 0.0;
            foreach ($Xs[$j] as $c => $v) {
                $s += ($v - $q[$c]) ** 2;
            }
            $d[$j] = $s;
        }
        asort($d);

        return array_slice($d, 0, $k, true);
    }

    /** Prediksi kNN untuk semua k = 1..kmax sekaligus dari satu daftar tetangga. */
    private function knnAllK(array $nearest, int $i, array &$knn): void
    {
        $k = 0;
        $wSum = $sI = $sB = 0.0;
        $cat = $tier = [];
        foreach ($nearest as $j => $dist) {
            $k++;
            $w = 1 / (sqrt($dist) + 1e-6);
            $wSum += $w;
            $cat[$this->yCat[$j]] = ($cat[$this->yCat[$j]] ?? 0) + $w;
            $tier[$this->yTier[$j]] = ($tier[$this->yTier[$j]] ?? 0) + $w;
            $sI += $w * $this->yIspu[$j];
            $sB += $w * $this->yBurden[$j];
            $knn['category'][$k][$i] = array_search(max($cat), $cat, true);
            $knn['tier'][$k][$i] = array_search(max($tier), $tier, true);
            $knn['ispu'][$k][$i] = round($sI / $wSum);
            $knn['burden'][$k][$i] = $sB / $wSum;
        }
    }

    private function knnPredict(array $nearest): array
    {
        $knn = [];
        $this->knnAllK($nearest, 0, $knn);
        $k = count($nearest);

        return [$knn['category'][$k][0], $knn['tier'][$k][0], $knn['burden'][$k][0]];
    }

    /** Turunnya kinerja kNN saat satu fitur diacak: makin besar, makin penting fiturnya. */
    private function importance(array $perm, int $k, array $features): array
    {
        ['Xs' => $Xs, 'tr' => $tr, 'te' => $te] = $perm;
        $score = function (array $points) use ($Xs, $tr, $k, $te) {
            $c = $t = 0;
            $b = 0.0;
            foreach ($te as $p => $i) {
                [$pc, $pt, $pb] = $this->knnPredict($this->neighbors($Xs, $tr, $points[$p], $k));
                $c += $pc === $this->yCat[$i];
                $t += $pt === $this->yTier[$i];
                $b += abs($pb - $this->yBurden[$i]);
            }

            return [$c / count($te), $t / count($te), $b / count($te)];
        };

        $points = array_map(fn ($i) => $Xs[$i], $te);
        [$baseC, $baseT, $baseB] = $score($points);
        $bar = $this->output->createProgressBar(count($features));
        $result = [];
        foreach ($features as $j => $f) {
            mt_srand(100 + $j);
            $col = array_column($points, $j);
            shuffle($col);
            $shuffled = $points;
            foreach ($shuffled as $p => $x) {
                $shuffled[$p][$j] = $col[$p];
            }
            [$c, $t, $b] = $score($shuffled);
            $result[] = [
                'feature' => KnnPredictor::FEATURES[$f][0],
                'category_drop' => round($baseC - $c, 4),
                'tier_drop' => round($baseT - $t, 4),
                'burden_mae_up' => round($b - $baseB, 4),
            ];
            $bar->advance();
        }
        $bar->finish();
        usort($result, fn ($a, $b) => $b['tier_drop'] <=> $a['tier_drop']);

        return ['samples' => count($te), 'k' => $k, 'base' => ['category' => round($baseC, 4), 'tier' => round($baseT, 4), 'burden_mae' => round($baseB, 4)], 'features' => $result];
    }

    private function mode(array $values): string
    {
        $c = array_count_values($values);
        arsort($c);

        return (string) array_key_first($c);
    }

    /** Ubah array berindeks i (urutan acak hasil fold) menjadi list berurutan 0..n-1. */
    private function ordered(array $byIndex): array
    {
        ksort($byIndex);

        return array_values($byIndex);
    }
}
