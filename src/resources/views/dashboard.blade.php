@extends('layout')
@section('title', 'Dashboard')

@section('content')
@include('partials.filters')

<div class="grid kpis">
    <div class="card kpi"><div class="label">Jumlah observasi</div><div class="value">{{ number_format($summary->n, 0, ',', '.') }}</div></div>
    <div class="card kpi"><div class="label">Rata-rata ISPU</div><div class="value" style="color:{{ \App\Support\Ispu::color(\App\Support\Ispu::category($summary->ispu ?? 0)) }}">{{ number_format($summary->ispu ?? 0, 1, ',', '.') }}</div></div>
    <div class="card kpi"><div class="label">Rata-rata PM2.5 (µg/m³)</div><div class="value">{{ number_format($summary->pm25 ?? 0, 1, ',', '.') }}</div></div>
    <div class="card kpi"><div class="label">Observasi tidak sehat ke atas</div><div class="value">{{ $summary->n ? round($summary->unhealthy / $summary->n * 100) : 0 }}%</div></div>
    <div class="card kpi"><div class="label">Terpapar asap karhutla</div><div class="value">{{ number_format($summary->smoke ?? 0, 0, ',', '.') }}</div></div>
    <div class="card kpi"><div class="label">ISPU tertinggi</div><div class="value">{{ $summary->max_ispu ?? 0 }}</div></div>
</div>

<div class="grid two" style="margin-bottom:16px">
    <div class="card"><h2>Peta rata-rata ISPU per provinsi</h2><div id="map"></div></div>
    <div class="card"><h2>Sebaran kategori ISPU</h2><canvas id="catChart" height="300"></canvas></div>
</div>

<div class="grid half" style="margin-bottom:16px">
    <div class="card"><h2>Tren bulanan</h2><canvas id="monthChart" height="240"></canvas></div>
    <div class="card"><h2>Polutan rata-rata per tipe wilayah (µg/m³, CO ÷ 100)</h2><canvas id="regionChart" height="240"></canvas></div>
</div>

<div class="grid two">
    <div class="card">
        <h2>Peringkat provinsi</h2>
        <div class="table-wrap"><table>
            <thead><tr><th>#</th><th>Provinsi</th><th>Obs.</th><th>ISPU</th><th>PM2.5</th><th>Asap</th><th>Kategori</th></tr></thead>
            <tbody>
            @foreach ($provinces as $i => $p)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td><a href="{{ route('records', ['province' => $p->province]) }}">{{ $p->province }}</a></td>
                    <td>{{ $p->n }}</td><td>{{ number_format($p->ispu, 1) }}</td><td>{{ number_format($p->pm25, 1) }}</td><td>{{ $p->smoke }}</td>
                    <td><span class="badge" style="background:{{ $p->color }}">{{ $p->category }}</span></td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    </div>
    <div class="card"><h2>Tingkat risiko pernapasan</h2><canvas id="tierChart" height="300"></canvas></div>
</div>
@endsection

@push('scripts')
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
const COLORS = @json(\App\Support\Ispu::CATEGORIES);
const provinces = @json($provinces);
const categories = @json($categories);
const monthly = @json($monthly);
const regions = @json($regions);
const tiers = @json($tiers);
const MONTHS = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];

const map = L.map('map', { scrollWheelZoom: false }).setView([-2.5, 118], 5);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 10, attribution: '&copy; OpenStreetMap' }).addTo(map);
provinces.filter(p => p.coords).forEach(p => {
    L.circleMarker(p.coords, { radius: 6 + Math.sqrt(p.n) / 3, color: '#fff', weight: 1.5, fillColor: p.color, fillOpacity: .85 })
        .bindPopup(`<b>${p.province}</b><br>ISPU rata-rata: ${(+p.ispu).toFixed(1)} (${p.category})<br>PM2.5: ${(+p.pm25).toFixed(1)} µg/m³<br>Observasi: ${p.n}`)
        .addTo(map);
});

const catLabels = Object.keys(COLORS).filter(k => categories[k]);
new Chart(catChart, { type: 'doughnut', data: { labels: catLabels, datasets: [{ data: catLabels.map(k => categories[k]), backgroundColor: catLabels.map(k => COLORS[k]) }] },
    options: { plugins: { legend: { position: 'bottom' } } } });

new Chart(monthChart, { type: 'line', data: { labels: monthly.map(m => MONTHS[m.m - 1]), datasets: [
    { label: 'ISPU', data: monthly.map(m => m.ispu), borderColor: '#0f766e', tension: .3 },
    { label: 'PM2.5', data: monthly.map(m => m.pm25), borderColor: '#dc2626', tension: .3 },
    { label: 'PM10', data: monthly.map(m => m.pm10), borderColor: '#eab308', tension: .3 },
] }, options: { interaction: { mode: 'index', intersect: false } } });

const POL = { pm25: ['PM2.5', '#dc2626'], pm10: ['PM10', '#f59e0b'], so2: ['SO2', '#7c3aed'], no2: ['NO2', '#2563eb'], o3: ['O3', '#0891b2'], co: ['CO/100', '#6b7280'] };
new Chart(regionChart, { type: 'bar', data: { labels: regions.map(r => r.region_type.replace('Kawasan ', '')),
    datasets: Object.entries(POL).map(([k, [l, c]]) => ({ label: l, data: regions.map(r => r[k]), backgroundColor: c })) },
    options: { indexAxis: 'y', plugins: { legend: { position: 'bottom' } } } });

const tierColors = ['#16a34a', '#2563eb', '#eab308', '#dc2626'];
new Chart(tierChart, { type: 'bar', data: { labels: Object.keys(tiers).map(t => t.split(' - ')[0]),
    datasets: [{ label: 'Observasi', data: Object.values(tiers), backgroundColor: tierColors }] },
    options: { plugins: { legend: { display: false }, tooltip: { callbacks: { title: i => Object.keys(tiers)[i[0].dataIndex] } } } } });
</script>
@endpush
