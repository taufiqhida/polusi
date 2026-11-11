<?php

namespace App\Http\Controllers;

use App\Models\Observation;
use App\Support\Ispu;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    private function filtered(Request $r)
    {
        return Observation::query()
            ->when($r->filled('province'), fn ($q) => $q->where('province', $r->province))
            ->when($r->filled('region'), fn ($q) => $q->where('region_type', $r->region))
            ->when($r->filled('month'), fn ($q) => $q->whereMonth('observed_at', (int) $r->month));
    }

    private function options(): array
    {
        return Cache::remember('ispu.options.v3', 3600, fn () => [
            'provinceList' => Observation::distinct()->orderBy('province')->pluck('province')->all(),
            'regionList' => Observation::distinct()->orderBy('region_type')->pluck('region_type')->all(),
        ]);
    }

    public function index(Request $r)
    {
        $filters = $r->only(['province', 'region', 'month']);
        $data = Cache::remember('ispu.dash.v3.'.md5(json_encode($filters)), 3600, function () use ($r) {
            $base = fn () => $this->filtered($r);

            $summary = $base()->toBase()->selectRaw('COUNT(*) n, AVG(ispu) ispu, AVG(pm25) pm25, MAX(ispu) max_ispu,
                SUM(ispu_category IN (\'Tidak Sehat\',\'Sangat Tidak Sehat\',\'Berbahaya\')) unhealthy,
                SUM(wildfire_smoke) smoke')->first();

            $categories = $base()->select('ispu_category', DB::raw('COUNT(*) n'))
                ->groupBy('ispu_category')->pluck('n', 'ispu_category');

            $monthly = $base()->selectRaw('MONTH(observed_at) m, AVG(ispu) ispu, AVG(pm25) pm25, AVG(pm10) pm10')
                ->groupBy('m')->orderBy('m')->get();

            $regions = $base()->selectRaw('region_type, AVG(pm25) pm25, AVG(pm10) pm10, AVG(so2) so2, AVG(no2) no2, AVG(o3) o3, AVG(co)/100 co, AVG(ispu) ispu')
                ->groupBy('region_type')->orderByDesc('ispu')->get();

            $provinces = $base()->selectRaw('province, COUNT(*) n, AVG(ispu) ispu, AVG(pm25) pm25, SUM(wildfire_smoke) smoke')
                ->groupBy('province')->orderByDesc('ispu')->get()
                ->map(function ($p) {
                    $p->category = Ispu::category($p->ispu);
                    $p->color = Ispu::color($p->category);
                    $p->coords = Ispu::PROVINCES[$p->province] ?? null;

                    return $p;
                });

            $tiers = $base()->select('risk_tier', DB::raw('COUNT(*) n'))
                ->groupBy('risk_tier')->orderBy('risk_tier')->pluck('n', 'risk_tier');

            return json_decode(json_encode(compact('summary', 'categories', 'monthly', 'regions', 'provinces', 'tiers')), true);
        });

        $data = json_decode(json_encode($data));
        $data = (array) $data;

        return view('dashboard', $data + $this->options() + ['filters' => $filters]);
    }

    public function records(Request $r)
    {
        $records = $this->filtered($r)
            ->when($r->filled('category'), fn ($q) => $q->where('ispu_category', $r->category))
            ->when($r->filled('q'), fn ($q) => $q->where('station', 'like', '%'.$r->q.'%'))
            ->orderByDesc('observed_at')
            ->paginate(25)->withQueryString();

        return view('records', $this->options() + [
            'records' => $records,
            'filters' => $r->only(['province', 'region', 'month', 'category', 'q']),
        ]);
    }

    public function show(Observation $observation)
    {
        return view('show', ['o' => $observation]);
    }
}
