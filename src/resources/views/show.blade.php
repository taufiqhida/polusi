@extends('layout')
@section('title', $o->station)

@section('content')
<p><a href="{{ url()->previous() }}">← Kembali</a></p>
<div class="card" style="margin-bottom:16px">
    <h2 style="font-size:20px">{{ $o->station }}</h2>
    <p style="color:var(--muted);margin-top:-6px">{{ $o->province }} · {{ $o->region_type }} · {{ $o->observed_at->format('d/m/Y H:i') }} · {{ $o->record_id }}</p>
    <p>
        <span class="badge" style="background:{{ \App\Support\Ispu::color($o->ispu_category) }};font-size:16px;padding:6px 14px">ISPU {{ $o->ispu }} · {{ $o->ispu_category }}</span>
        <span class="badge" style="background:#374151">{{ $o->risk_tier }}</span>
        @if ($o->wildfire_smoke) <span class="badge" style="background:#b45309">🔥 Asap karhutla</span> @endif
    </p>
</div>

<div class="grid half" style="margin-bottom:16px">
    <div class="card">
        <h2>Polutan (µg/m³)</h2>
        <table>
            <tr><td>PM2.5</td><td><b>{{ $o->pm25 }}</b> ({{ $o->who_exceedance }}× batas WHO)</td></tr>
            <tr><td>PM10</td><td>{{ $o->pm10 }}</td></tr>
            <tr><td>SO2</td><td>{{ $o->so2 }}</td></tr>
            <tr><td>NO2</td><td>{{ $o->no2 }}</td></tr>
            <tr><td>CO</td><td>{{ $o->co }}</td></tr>
            <tr><td>O3</td><td>{{ $o->o3 }}</td></tr>
        </table>
    </div>
    <div class="card">
        <h2>Cuaca & indeks</h2>
        <table>
            <tr><td>Suhu</td><td>{{ $o->temperature }} °C</td></tr>
            <tr><td>Kelembapan</td><td>{{ $o->humidity }} %</td></tr>
            <tr><td>Angin</td><td>{{ $o->wind_speed }} km/jam</td></tr>
            <tr><td>Hujan</td><td>{{ $o->rainfall }} mm/jam</td></tr>
            <tr><td>Rasio PM2.5/PM10</td><td>{{ $o->pm_ratio }}</td></tr>
            <tr><td>Indeks stagnasi</td><td>{{ $o->stagnation_index }} / 10</td></tr>
            <tr><td>Beban ISPA (proyeksi)</td><td>{{ $o->ispa_burden }} / 10</td></tr>
        </table>
    </div>
</div>

<div class="card">
    <h2>Analisis (narasi dari dataset)</h2>
    <p style="line-height:1.7">{{ $o->rationale }}</p>
</div>
@endsection
