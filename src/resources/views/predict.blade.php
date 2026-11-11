@extends('layout')
@section('title', 'Prediksi')

@php($C = \App\Support\Ispu::class)

@section('content')
<div class="grid two">
    <div class="card">
        <h2>Masukkan kondisi udara</h2>
        <form method="get" action="{{ route('predict') }}" class="pred-form">
            @foreach ($features as $key => [$label, $unit])
                <label>
                    <span>{{ $label }} <small>({{ $unit }})</small></span>
                    <input type="number" step="any" name="{{ $key }}" value="{{ $input[$key] ?? '' }}" required>
                    @error($key) <small class="err">{{ $message }}</small> @enderror
                </label>
            @endforeach
            <div class="actions">
                <button type="submit">Prediksi</button>
                <a class="reset" href="{{ route('predict') }}">Reset</a>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>Hasil prediksi</h2>
        @if ($result)
            <div class="big" style="background:{{ $C::color($result['category']) }}">
                <div>ISPU ±{{ $result['ispu'] }}</div>
                <div class="cat">{{ $result['category'] }}</div>
            </div>
            <p><b>Risiko pernapasan:</b> {{ $result['tier'] }}</p>
            <p><b>Proyeksi beban ISPA:</b> {{ $result['burden'] }} / 10</p>

            <h3>Keyakinan kategori</h3>
            @foreach ($result['category_proba'] as $c => $p)
                <div class="bar"><span>{{ $c }}</span><div><i style="width:{{ $p * 100 }}%;background:{{ $C::color($c) }}"></i></div><b>{{ round($p * 100) }}%</b></div>
            @endforeach

            <h3>Keyakinan tingkat risiko</h3>
            @foreach ($result['tier_proba'] as $c => $p)
                <div class="bar"><span>{{ explode(' - ', $c)[0] }}</span><div><i style="width:{{ $p * 100 }}%;background:#0f766e"></i></div><b>{{ round($p * 100) }}%</b></div>
            @endforeach
        @else
            <p style="color:var(--muted)">Isi angka di kiri lalu klik <b>Prediksi</b>. Nilai awalnya adalah rata-rata dataset.</p>
        @endif

        <hr style="border:0;border-top:1px solid var(--line);margin:18px 0">
        <h3>Tentang model</h3>
        <p style="font-size:14px;color:var(--muted);line-height:1.6">
            Model <b>k-Nearest Neighbors</b> (k={{ $metrics['k'] ?? 15 }}) ditulis murni dalam PHP dan berjalan di CPU tanpa GPU.
            Model mencari {{ $metrics['k'] ?? 15 }} observasi paling mirip di dataset, lalu menghitung rata-rata berbobot dari observasi tersebut.
        </p>
        @if ($metrics)
            <table>
                <tr><td>Akurasi kategori ISPU</td><td><b>{{ round($metrics['category_accuracy'] * 100, 1) }}%</b></td></tr>
                <tr><td>Akurasi tingkat risiko</td><td><b>{{ round($metrics['tier_accuracy'] * 100, 1) }}%</b></td></tr>
                <tr><td>Rata-rata selisih ISPU (MAE)</td><td><b>±{{ $metrics['ispu_mae'] }}</b></td></tr>
                <tr><td>Data latih / uji</td><td>{{ $metrics['train'] }} / {{ $metrics['test'] }}</td></tr>
            </table>
        @endif
        <p style="font-size:14px;margin-top:12px"><a href="{{ route('evaluation') }}">Lihat evaluasi lengkap dan perbandingan model →</a></p>
    </div>
</div>
@endsection

@push('head')
<style>
    .pred-form { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
    .pred-form label { display:flex; flex-direction:column; gap:4px; font-size:14px; }
    .pred-form small { color:var(--muted); } .pred-form .err { color:#dc2626; }
    .pred-form .actions { grid-column:1/-1; display:flex; gap:12px; }
    @media (max-width:500px) { .pred-form { grid-template-columns:1fr; } }
    .big { color:#fff; border-radius:10px; padding:18px; text-align:center; margin-bottom:14px; }
    .big div:first-child { font-size:30px; font-weight:700; } .big .cat { font-size:18px; }
    h3 { font-size:14px; margin:16px 0 8px; }
    .bar { display:grid; grid-template-columns:130px 1fr 44px; align-items:center; gap:8px; font-size:13px; margin-bottom:6px; }
    .bar div { background:#eef2f7; border-radius:4px; height:10px; overflow:hidden; } .bar i { display:block; height:100%; }
</style>
@endpush
