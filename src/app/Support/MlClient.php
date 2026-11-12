<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Klien untuk service ML Python (folder ml/, FastAPI + scikit-learn).
 * Semua pelatihan, prediksi, dan evaluasi model dijalankan di service tersebut.
 */
class MlClient
{
    /** kolom => [label, satuan, nilai awal di form (rata-rata dataset)] */
    public const FEATURES = [
        'pm25' => ['PM2.5', 'µg/m³', 58.6],
        'pm10' => ['PM10', 'µg/m³', 87.2],
        'so2' => ['SO2', 'µg/m³', 27.6],
        'no2' => ['NO2', 'µg/m³', 42.8],
        'co' => ['CO', 'µg/m³', 1460],
        'o3' => ['O3', 'µg/m³', 46.0],
        'temperature' => ['Suhu', '°C', 28.4],
        'humidity' => ['Kelembapan', '%', 79.7],
        'wind_speed' => ['Kecepatan angin', 'km/jam', 9.5],
        'rainfall' => ['Curah hujan', 'mm/jam', 0],
    ];

    /** k terbaik hasil cross-validation (php artisan ispu:evaluate-models), default 15. */
    public static function bestK(): int
    {
        $file = storage_path('app/model_eval.json');
        $k = is_file($file) ? (json_decode(file_get_contents($file), true)['best_k']['semua'] ?? null) : null;

        return $k ? (int) $k : 15;
    }

    public static function predict(array $features): array
    {
        $body = collect(self::FEATURES)->map(fn ($f, $key) => (float) $features[$key])->all();

        return self::post('/predict', $body);
    }

    public static function train(int $k): array
    {
        return self::post('/train', ['k' => $k], 600);
    }

    public static function evaluate(int $k, int $test): array
    {
        return self::post('/evaluate', ['k' => $k, 'test' => $test], 600);
    }

    public static function evaluateModels(int $folds, int $kmax, int $perm): array
    {
        return self::post('/evaluate-models', ['folds' => $folds, 'kmax' => $kmax, 'perm' => $perm], 3600);
    }

    private static function post(string $path, array $body, ?int $timeout = null): array
    {
        try {
            return Http::baseUrl(config('services.ml.url'))
                ->timeout($timeout ?? config('services.ml.timeout'))
                ->acceptJson()
                ->post($path, $body)
                ->throw()
                ->json();
        } catch (ConnectionException $e) {
            throw new RuntimeException('Service ML tidak bisa dihubungi ('.config('services.ml.url').'). Cek: docker compose ps ml', 0, $e);
        } catch (RequestException $e) {
            $detail = $e->response->json('detail');
            throw new RuntimeException('Service ML gagal: '.(is_string($detail) ? $detail : $e->response->body()), 0, $e);
        }
    }
}
