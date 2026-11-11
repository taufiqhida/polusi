<?php

namespace App\Console\Commands;

use App\Support\KnnPredictor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class EvaluateKnn extends Command
{
    protected $signature = 'ispu:evaluate {--k= : Jumlah tetangga (default: k terbaik hasil ispu:evaluate-models)} {--test=1000 : Jumlah data uji}';

    protected $description = 'Uji akurasi model kNN dengan pembagian data latih/uji (hold-out)';

    public function handle(): int
    {
        $rows = DB::table('observations')
            ->select(array_merge(array_keys(KnnPredictor::FEATURES), ['ispu', 'ispu_category', 'risk_tier', 'ispa_burden']))
            ->get()->map(fn ($r) => (array) $r)->all();

        $k = (int) ($this->option('k') ?: KnnPredictor::bestK());
        mt_srand(42);
        shuffle($rows);
        $test = array_splice($rows, 0, (int) $this->option('test'));
        $model = (new KnnPredictor($k))->fit($rows);

        $catOk = $tierOk = 0;
        $ispuErr = $burdenErr = 0;
        $bar = $this->output->createProgressBar(count($test));
        foreach ($test as $r) {
            $p = $model->predict($r);
            $catOk += $p['category'] === $r['ispu_category'];
            $tierOk += $p['tier'] === $r['risk_tier'];
            $ispuErr += abs($p['ispu'] - $r['ispu']);
            $burdenErr += abs($p['burden'] - $r['ispa_burden']);
            $bar->advance();
        }
        $bar->finish();
        $n = count($test);
        $result = [
            'k' => $k,
            'train' => count($rows),
            'test' => $n,
            'category_accuracy' => round($catOk / $n, 4),
            'tier_accuracy' => round($tierOk / $n, 4),
            'ispu_mae' => round($ispuErr / $n, 2),
            'burden_mae' => round($burdenErr / $n, 3),
            'evaluated_at' => now()->toDateTimeString(),
        ];
        file_put_contents(storage_path('app/knn_metrics.json'), json_encode($result, JSON_PRETTY_PRINT));

        $this->newLine(2);
        $this->table(['Metrik', 'Nilai'], collect($result)->map(fn ($v, $k) => [$k, $v])->values()->all());

        return self::SUCCESS;
    }
}
