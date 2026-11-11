<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportIspu extends Command
{
    protected $signature = 'ispu:import {path=/data/indonesia_kualitas_udara_ispu_enriched.csv} {--fresh : Kosongkan tabel dulu}';

    protected $description = 'Import CSV ISPU Kaggle ke tabel observations';

    public function handle(): int
    {
        $path = $this->argument('path');
        if (! is_readable($path)) {
            $this->error("File tidak ditemukan: $path");

            return self::FAILURE;
        }
        if ($this->option('fresh')) {
            DB::table('observations')->truncate();
        }

        $fh = fopen($path, 'r');
        $head = fgetcsv($fh, 0, ',', '"', '');
        $batch = [];
        $count = 0;

        while (($row = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            if (count($row) !== count($head)) {
                continue;
            }
            $batch[] = $this->map(array_combine($head, $row));
            if (count($batch) === 500) {
                DB::table('observations')->upsert($batch, ['record_id']);
                $count += count($batch);
                $batch = [];
            }
        }
        if ($batch) {
            DB::table('observations')->upsert($batch, ['record_id']);
            $count += count($batch);
        }
        fclose($fh);
        cache()->flush();
        $this->info("Selesai: $count baris diimport.");

        return self::SUCCESS;
    }

    private function map(array $r): array
    {
        return [
            'record_id' => $r['station_record_id'],
            'observed_at' => $r['observation_datetime'],
            'province' => $r['province'],
            'region_type' => $r['region_type'],
            'station' => $r['monitoring_station_name'],
            'pm25' => (float) $r['pm25_concentration_ugm3'],
            'pm10' => (float) $r['pm10_concentration_ugm3'],
            'so2' => (float) $r['sulfur_dioxide_so2_ugm3'],
            'no2' => (float) $r['nitrogen_dioxide_no2_ugm3'],
            'co' => (float) $r['carbon_monoxide_co_ugm3'],
            'o3' => (float) $r['ozone_o3_ugm3'],
            'temperature' => (float) $r['ambient_temperature_celsius'],
            'humidity' => (float) $r['relative_humidity_pct'],
            'wind_speed' => (float) $r['wind_speed_kmh'],
            'rainfall' => (float) $r['rainfall_intensity_mm'],
            'ispu' => (int) round((float) $r['ispu_calculated_index']),
            'ispu_category' => $r['ispu_category'],
            'who_exceedance' => (float) $r['who_aqg_exceedance_factor'],
            'pm_ratio' => (float) $r['particulate_ratio_pm25_to_pm10'],
            'combustion_index' => (float) $r['combustion_signature_index'],
            'stagnation_index' => (float) $r['atmospheric_stagnation_index'],
            'wildfire_smoke' => strtolower($r['wildfire_smoke_plume_flag']) === 'true',
            'risk_tier' => $r['respiratory_health_risk_tier'],
            'ispa_burden' => (float) $r['projected_ispa_clinic_burden_score'],
            'rationale' => $r['cot_atmospheric_health_rationale'],
            'confidence' => (float) $r['confidence_score'],
        ];
    }
}
