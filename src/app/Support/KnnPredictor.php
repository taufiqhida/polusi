<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Model k-Nearest Neighbors ringan (murni PHP, jalan di CPU).
 * Fitur distandarisasi (z-score), tetangga dibobot 1/jarak.
 */
class KnnPredictor
{
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

    private array $X = [];
    private array $y = [];
    private array $mean = [];
    private array $std = [];

    public function __construct(private int $k = 15) {}

    /** k terbaik hasil cross-validation (php artisan ispu:evaluate-models), default 15. */
    public static function bestK(): int
    {
        $file = storage_path('app/model_eval.json');
        $k = is_file($file) ? (json_decode(file_get_contents($file), true)['best_k']['semua'] ?? null) : null;

        return $k ? (int) $k : 15;
    }

    /** Muat semua data dari DB (di-cache sebagai array biasa). */
    public static function fromDatabase(?int $k = null): self
    {
        $k ??= self::bestK();
        $rows = Cache::remember('ispu.knn.v1', 86400, fn () => DB::table('observations')
            ->select(array_merge(array_keys(self::FEATURES), ['ispu', 'ispu_category', 'risk_tier', 'ispa_burden']))
            ->get()->map(fn ($r) => (array) $r)->all());

        return (new self($k))->fit($rows);
    }

    public function fit(array $rows): self
    {
        $n = count($rows);
        foreach (array_keys(self::FEATURES) as $f) {
            $vals = array_column($rows, $f);
            $m = array_sum($vals) / $n;
            $var = array_sum(array_map(fn ($v) => ($v - $m) ** 2, $vals)) / $n;
            $this->mean[$f] = $m;
            $this->std[$f] = sqrt($var) ?: 1.0;
        }
        $this->X = array_map(fn ($r) => $this->scale($r), $rows);
        $this->y = array_map(fn ($r) => [
            'ispu' => (float) $r['ispu'],
            'category' => $r['ispu_category'],
            'tier' => $r['risk_tier'],
            'burden' => (float) $r['ispa_burden'],
        ], $rows);

        return $this;
    }

    private function scale(array $r): array
    {
        $out = [];
        foreach (array_keys(self::FEATURES) as $f) {
            $out[] = ((float) $r[$f] - $this->mean[$f]) / $this->std[$f];
        }

        return $out;
    }

    public function predict(array $input): array
    {
        $q = $this->scale($input);
        $d = [];
        foreach ($this->X as $i => $x) {
            $s = 0.0;
            foreach ($x as $j => $v) {
                $s += ($v - $q[$j]) ** 2;
            }
            $d[$i] = $s;
        }
        asort($d);
        $nearest = array_slice($d, 0, $this->k, true);

        $wSum = 0;
        $ispu = $burden = 0;
        $cat = $tier = [];
        foreach ($nearest as $i => $dist) {
            $w = 1 / (sqrt($dist) + 1e-6);
            $wSum += $w;
            $ispu += $w * $this->y[$i]['ispu'];
            $burden += $w * $this->y[$i]['burden'];
            $cat[$this->y[$i]['category']] = ($cat[$this->y[$i]['category']] ?? 0) + $w;
            $tier[$this->y[$i]['tier']] = ($tier[$this->y[$i]['tier']] ?? 0) + $w;
        }
        $norm = fn ($a) => collect($a)->map(fn ($v) => round($v / $wSum, 3))->sortDesc()->all();
        $cat = $norm($cat);
        $tier = $norm($tier);

        return [
            'ispu' => round($ispu / $wSum),
            'category' => array_key_first($cat),
            'category_proba' => $cat,
            'tier' => array_key_first($tier),
            'tier_proba' => $tier,
            'burden' => round($burden / $wSum, 2),
            'neighbors' => count($nearest),
        ];
    }
}
