<?php

namespace App\Support;

class Ispu
{
    public const CATEGORIES = [
        'Baik' => '#16a34a',
        'Sedang' => '#2563eb',
        'Tidak Sehat' => '#eab308',
        'Sangat Tidak Sehat' => '#dc2626',
        'Berbahaya' => '#1f2937',
    ];

    // Titik tengah kira-kira tiap provinsi [lat, lng]
    public const PROVINCES = [
        'Aceh' => [4.36, 96.9], 'Sumatera Utara' => [2.11, 99.54], 'Sumatera Barat' => [-0.74, 100.8],
        'Riau' => [0.29, 101.7], 'Jambi' => [-1.61, 103.6], 'Sumatera Selatan' => [-3.32, 104.0],
        'Lampung' => [-4.56, 105.4], 'DKI Jakarta' => [-6.2, 106.85], 'Jawa Barat' => [-6.9, 107.6],
        'Banten' => [-6.4, 106.06], 'Jawa Tengah' => [-7.15, 110.14], 'DI Yogyakarta' => [-7.8, 110.37],
        'Jawa Timur' => [-7.54, 112.24], 'Bali' => [-8.4, 115.19], 'Nusa Tenggara Timur' => [-8.66, 121.08],
        'Kalimantan Barat' => [-0.28, 111.48], 'Kalimantan Tengah' => [-1.68, 113.38],
        'Kalimantan Selatan' => [-3.09, 115.28], 'Kalimantan Timur' => [0.54, 116.42],
        'Sulawesi Tengah' => [-1.43, 121.45], 'Sulawesi Selatan' => [-3.67, 119.97],
        'Maluku' => [-3.24, 130.15], 'Papua' => [-4.27, 138.08],
    ];

    /** Batas konsentrasi (µg/m³) untuk ISPU 50/100/200/300/500 (Permen LHK No. 14/2020). */
    public const BREAKPOINTS = [
        'pm25' => [15.5, 55.4, 150.4, 250.4, 500],
        'pm10' => [50, 150, 350, 420, 500],
        'so2' => [52, 180, 400, 800, 1200],
        'co' => [4000, 8000, 15000, 30000, 45000],
        'o3' => [120, 235, 400, 800, 1000],
        'no2' => [80, 200, 1130, 2260, 3000],
    ];

    /**
     * Hitung ISPU dengan rumus resmi: interpolasi linear tiap polutan, lalu ambil nilai terbesar.
     * Polutan yang tidak ada di $r dilewati.
     *
     * @return array{ispu: float, dominant: string}
     */
    public static function fromPollutants(array $r): array
    {
        $levels = [0, 50, 100, 200, 300, 500];
        $max = 0.0;
        $dominant = '';
        foreach (self::BREAKPOINTS as $pollutant => $bounds) {
            if (! isset($r[$pollutant])) {
                continue;
            }
            $x = (float) $r[$pollutant];
            $sub = 500.0;
            $lo = 0.0;
            foreach ($bounds as $i => $hi) {
                if ($x <= $hi) {
                    $sub = $levels[$i] + ($levels[$i + 1] - $levels[$i]) * ($x - $lo) / ($hi - $lo);
                    break;
                }
                $lo = $hi;
            }
            if ($sub > $max) {
                $max = $sub;
                $dominant = $pollutant;
            }
        }

        return ['ispu' => $max, 'dominant' => $dominant];
    }

    public static function category(float $ispu): string
    {
        return match (true) {
            $ispu <= 50 => 'Baik',
            $ispu <= 100 => 'Sedang',
            $ispu <= 200 => 'Tidak Sehat',
            $ispu <= 300 => 'Sangat Tidak Sehat',
            default => 'Berbahaya',
        };
    }

    public static function color(string $category): string
    {
        return self::CATEGORIES[$category] ?? '#6b7280';
    }
}
