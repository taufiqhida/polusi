<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Support\MlClient;

/**
 * Evaluasi lengkap untuk Bab 4, dihitung oleh service ML Python (scikit-learn):
 * k-fold cross-validation, perbandingan model, pemilihan k, confusion matrix,
 * precision/recall/F1 per kelas, dan permutation importance.
 * Hasil disimpan di storage/app/model_eval.json dan ditampilkan di halaman /evaluasi.
 */
class EvaluateModels extends Command
{
    protected $signature = 'ispu:evaluate-models
        {--folds=5 : Jumlah fold cross-validation}
        {--kmax=30 : Nilai k terbesar yang diuji}
        {--perm=500 : Jumlah data uji untuk permutation importance}';

    protected $description = 'Bandingkan kNN, Decision Tree, Naive Bayes, baseline, dan rumus resmi ISPU (k-fold CV, service ML Python)';

    public const FILE = 'app/model_eval.json';

    public function handle(): int
    {
        $this->info('Menjalankan k-fold cross-validation di service ML (lihat progres: docker compose logs -f ml)...');
        $out = MlClient::evaluateModels((int) $this->option('folds'), (int) $this->option('kmax'), (int) $this->option('perm'));
        file_put_contents(storage_path(self::FILE), json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->line('k terbaik (semua fitur): '.$out['best_k']['semua'].' (langsung dipakai halaman Prediksi)');
        $rows = [];
        foreach ($out['classification']['tier']['semua'] as $model => $m) {
            $c = $out['classification']['category']['semua'][$model] ?? null;
            $rows[] = [$out['models'][$model], $c ? $c['acc'] : '-', $c ? $c['f1'] : '-', $m['acc'], $m['f1']];
        }
        $this->table(['Model (semua fitur)', 'Akurasi kategori', 'Macro-F1 kategori', 'Akurasi risiko', 'Macro-F1 risiko'], $rows);
        $this->line('Disimpan ke storage/'.self::FILE." ({$out['meta']['duration_s']} detik, {$out['meta']['engine']})");

        return self::SUCCESS;
    }
}
