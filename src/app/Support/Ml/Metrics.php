<?php

namespace App\Support\Ml;

/** Metrik evaluasi klasifikasi dan regresi. */
class Metrics
{
    /**
     * @param  list<string>  $true
     * @param  list<string>  $pred
     * @param  list<string>  $labels  urutan kelas untuk confusion matrix
     */
    public static function classification(array $true, array $pred, array $labels): array
    {
        $pos = array_flip($labels);
        $m = array_fill(0, count($labels), array_fill(0, count($labels), 0));
        $ok = 0;
        foreach ($true as $i => $t) {
            $p = $pred[$i];
            $ok += $t === $p;
            if (isset($pos[$t], $pos[$p])) {
                $m[$pos[$t]][$pos[$p]]++;
            }
        }

        $perClass = [];
        $f1s = [];
        foreach ($labels as $c => $label) {
            $tp = $m[$c][$c];
            $support = array_sum($m[$c]);
            $predicted = array_sum(array_column($m, $c));
            $precision = $predicted ? $tp / $predicted : 0.0;
            $recall = $support ? $tp / $support : 0.0;
            $f1 = $precision + $recall > 0 ? 2 * $precision * $recall / ($precision + $recall) : 0.0;
            $perClass[$label] = [
                'precision' => round($precision, 4),
                'recall' => round($recall, 4),
                'f1' => round($f1, 4),
                'support' => $support,
            ];
            if ($support > 0) {
                $f1s[] = $f1;
            }
        }

        return [
            'accuracy' => count($true) ? $ok / count($true) : 0.0,
            'macro_f1' => $f1s ? array_sum($f1s) / count($f1s) : 0.0,
            'per_class' => $perClass,
            'matrix' => $m,
        ];
    }

    /** @return array{mae: float, rmse: float, r2: float} */
    public static function regression(array $true, array $pred): array
    {
        $n = count($true);
        $mean = array_sum($true) / $n;
        $abs = $sq = $tot = 0.0;
        foreach ($true as $i => $t) {
            $e = $pred[$i] - $t;
            $abs += abs($e);
            $sq += $e * $e;
            $tot += ($t - $mean) ** 2;
        }

        return [
            'mae' => $abs / $n,
            'rmse' => sqrt($sq / $n),
            'r2' => $tot > 0 ? 1 - $sq / $tot : 0.0,
        ];
    }

    /** @return array{0: float, 1: float} rata-rata dan simpangan baku */
    public static function meanStd(array $values): array
    {
        $n = count($values);
        $m = array_sum($values) / $n;
        $var = array_sum(array_map(fn ($v) => ($v - $m) ** 2, $values)) / max(1, $n - 1);

        return [$m, sqrt($var)];
    }
}
