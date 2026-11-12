<?php

namespace App\Console\Commands;

use App\Support\MlClient;
use Illuminate\Console\Command;

class EvaluateKnn extends Command
{
    protected $signature = 'ispu:evaluate {--k= : Jumlah tetangga (default: k terbaik hasil ispu:evaluate-models)} {--test=1000 : Jumlah data uji}';

    protected $description = 'Latih ulang model kNN di service ML Python dan uji akurasinya (hold-out)';

    public function handle(): int
    {
        $k = (int) ($this->option('k') ?: MlClient::bestK());

        $this->info("Menguji kNN (k=$k) dengan hold-out di service ML...");
        $result = MlClient::evaluate($k, (int) $this->option('test'));
        file_put_contents(storage_path('app/knn_metrics.json'), json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->info('Melatih ulang model prediksi dengan seluruh data...');
        $trained = MlClient::train($k);

        $this->table(['Metrik', 'Nilai'], collect($result)->map(fn ($v, $key) => [$key, $v])->values()->all());
        $this->line("Model prediksi aktif: k={$trained['k']}, {$trained['rows']} data");

        return self::SUCCESS;
    }
}
