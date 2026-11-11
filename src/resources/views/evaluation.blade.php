@extends('layout')
@section('title', 'Evaluasi Model')

@push('head')
<style>
    .muted { color:var(--muted); font-size:13px; }
    .lead { font-size:14px; line-height:1.6; margin:0; }
    .finding { background:#ecfdf5; border:1px solid #a7f3d0; border-radius:12px; padding:16px 18px; margin-bottom:16px; font-size:14px; line-height:1.6; }
    .finding h2 { margin:0 0 8px; font-size:15px; }
    .finding ul, .card ul { margin:6px 0 0; padding-left:20px; }
    td.num, th.num { text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }
    tr.best td { background:#f0fdfa; font-weight:600; }
    .std { color:var(--muted); font-weight:400; font-size:12px; }
    .cm td { text-align:center; font-variant-numeric:tabular-nums; min-width:54px; }
    .cm th { text-align:center; text-transform:none; font-size:12px; }
    .cm th.row { text-align:left; }
    .chart-box { position:relative; height:300px; }
    .section-title { font-size:17px; margin:28px 0 12px; }
    code { background:#f3f4f6; padding:1px 6px; border-radius:4px; font-size:13px; }
    .tag { display:inline-block; padding:1px 8px; border-radius:999px; background:#e0f2fe; color:#075985; font-size:12px; }
</style>
@endpush

@section('content')
@if (! $eval)
    <div class="card">
        <h2>Evaluasi belum dijalankan</h2>
        <p class="lead">Jalankan perintah ini di server (butuh beberapa menit):</p>
        <p><code>docker compose exec app php artisan ispu:evaluate-models</code></p>
    </div>
@else
@php
    $pct = fn ($v) => number_format($v * 100, 1, ',', '.').'%';
    $num = fn ($v, $d = 2) => number_format($v, $d, ',', '.');
    $scen = $eval['scenarios'];
    $models = $eval['models'];
    $k = $eval['best_k']['semua'];
    $modelName = fn ($m, $s = 'semua') => $m === 'knn' ? $models[$m].' (k='.$eval['best_k'][$s].')' : $models[$m];
    $targetsCls = ['category' => 'Kategori ISPU (5 kelas)', 'tier' => 'Tingkat risiko (4 tier)'];
    $targetsReg = ['ispu' => 'Nilai ISPU', 'burden' => 'Beban ISPA'];
    $bestOf = function (array $byModel, string $key, bool $high = true) {
        $ml = collect($byModel)->except('formula')->map(fn ($m) => $m[$key]);
        return $high ? $ml->sortDesc()->keys()->first() : $ml->sort()->keys()->first();
    };
    $c = $eval['classification'];
    $r = $eval['regression'];
    $bestCatSemua = $bestOf($c['category']['semua'], 'f1');
    $bestTierSemua = $bestOf($c['tier']['semua'], 'f1');
    $dom = $eval['formula_check']['dominant'];
    $domTop = array_key_first($dom);
    $domShare = $dom[$domTop] / $eval['meta']['rows'];
@endphp

<div class="card" style="margin-bottom:16px">
    <h2>Metode evaluasi</h2>
    <p class="lead">
        Seluruh {{ number_format($eval['meta']['rows'], 0, ',', '.') }} data diacak (seed {{ $eval['meta']['seed'] }}) lalu dibagi menjadi
        <b>{{ $eval['meta']['folds'] }} fold</b> (<i>k-fold cross-validation</i>). Setiap model dilatih pada {{ $eval['meta']['folds'] - 1 }} fold
        dan diuji pada 1 fold sisanya, diulang sampai semua fold pernah menjadi data uji. Normalisasi z-score dihitung dari data latih saja
        agar tidak ada kebocoran data uji. Untuk klasifikasi dilaporkan <b>akurasi</b> dan <b>macro-F1</b> (rata-rata F1 semua kelas, adil untuk kelas yang sedikit),
        dalam bentuk rata-rata ± simpangan baku antar fold. Untuk regresi dilaporkan MAE, RMSE, dan R².
    </p>
    <p class="muted" style="margin:10px 0 0">
        Dihitung {{ $eval['meta']['evaluated_at'] }} · {{ $eval['meta']['duration_s'] }} detik · nilai k diuji 1–{{ $eval['meta']['kmax'] }} ·
        hitung ulang: <code>php artisan ispu:evaluate-models</code>
    </p>
</div>

<div class="finding">
    <h2>Temuan utama</h2>
    <ul>
        <li>Kolom ISPU di dataset <b>{{ $pct($eval['formula_check']['match_rate']) }}</b> sama dengan hasil rumus resmi ISPU (Permen LHK No. 14/2020),
            dan polutan penentunya hampir selalu <b>{{ $domTop }}</b> ({{ $pct($domShare) }} data). Kategori ISPU dan tingkat risiko juga mengikuti batas nilai ISPU.
            Jadi label di dataset ini dibuat dari aturan, bukan dari pengukuran lapangan yang berbeda-beda.</li>
        <li>Dengan semua fitur, model terbaik adalah <b>{{ $modelName($bestTierSemua) }}</b>
            (macro-F1 tingkat risiko {{ $pct($c['tier']['semua'][$bestTierSemua]['f1']) }},
            kNN {{ $pct($c['tier']['semua']['knn']['f1']) }}).
            @if ($bestTierSemua === 'tree')
                Decision Tree unggul karena bisa mempelajari batas nilai (threshold) secara langsung, sedangkan kNN merata-ratakan tetangga
                sehingga sering salah di dekat batas kategori.
            @endif
        </li>
        <li>Tanpa sensor polutan (cuaca saja), akurasi model terbaik hanya {{ $pct(collect($c['tier']['cuaca'])->except('baseline')->max('acc')) }},
            tidak lebih baik dari baseline yang selalu menebak kelas terbanyak ({{ $pct($c['tier']['cuaca']['baseline']['acc']) }}).
            Artinya cuaca saja tidak cukup untuk menebak kualitas udara di dataset ini.</li>
    </ul>
</div>

<h3 class="section-title">1. Perbandingan model klasifikasi</h3>
<div class="grid half">
    @foreach ($targetsCls as $t => $title)
        <div class="card">
            <h2>{{ $title }} · macro-F1 per skenario</h2>
            <div class="chart-box"><canvas id="cmp-{{ $t }}"></canvas></div>
        </div>
    @endforeach
</div>

@foreach ($targetsCls as $t => $title)
    <div class="card" style="margin-top:16px">
        <h2>{{ $title }}</h2>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Model</th>
                    @foreach ($scen as $s)
                        <th class="num" colspan="2">{{ $s['label'] }}</th>
                    @endforeach
                </tr>
                <tr>
                    <th></th>
                    @foreach ($scen as $s)
                        <th class="num">Akurasi</th><th class="num">Macro-F1</th>
                    @endforeach
                </tr>
                </thead>
                <tbody>
                @foreach ($models as $m => $name)
                    @continue(! isset($c[$t]['semua'][$m]) && ! isset($c[$t]['pm25_cuaca'][$m]))
                    <tr class="{{ $m === $bestOf($c[$t]['semua'], 'f1') ? 'best' : '' }}">
                        <td>{{ $name }} @if ($m === 'formula')<span class="tag">acuan</span>@endif</td>
                        @foreach ($scen as $sk => $s)
                            @php $v = $c[$t][$sk][$m] ?? null; @endphp
                            @if ($v)
                                <td class="num">{{ $pct($v['acc']) }} <span class="std">±{{ $num($v['acc_std'] * 100, 1) }}</span></td>
                                <td class="num">{{ $pct($v['f1']) }} <span class="std">±{{ $num($v['f1_std'] * 100, 1) }}</span></td>
                            @else
                                <td class="num muted">–</td><td class="num muted">–</td>
                            @endif
                        @endforeach
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="muted" style="margin:10px 0 0">Baris tebal = model ML terbaik (macro-F1, semua fitur). k terbaik kNN per skenario:
            @foreach ($eval['best_k'] as $sk => $bk) {{ $scen[$sk]['label'] }} k={{ $bk }}@if (! $loop->last), @endif @endforeach.
            Rumus resmi hanya bisa dipakai bila ada data polutan.</p>
    </div>
@endforeach

<h3 class="section-title">2. Perbandingan model regresi</h3>
<div class="grid half">
    @foreach ($targetsReg as $t => $title)
        <div class="card">
            <h2>{{ $title }}</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Model</th><th>Skenario</th><th class="num">MAE</th><th class="num">RMSE</th><th class="num">R²</th></tr></thead>
                    <tbody>
                    @foreach ($scen as $sk => $s)
                        @php $best = $bestOf($r[$t][$sk], 'mae', false); @endphp
                        @foreach ($r[$t][$sk] as $m => $v)
                            <tr class="{{ $m === $best ? 'best' : '' }}">
                                <td>{{ $m === 'knn' ? 'kNN (k='.$eval['best_k'][$sk].')' : ($m === 'baseline' ? 'Baseline (rata-rata)' : ($m === 'tree' ? 'Decision Tree' : 'Rumus resmi')) }}</td>
                                <td class="muted">{{ $loop->first ? $s['label'] : '' }}</td>
                                <td class="num">{{ $num($v['mae'], $t === 'ispu' ? 2 : 3) }}</td>
                                <td class="num">{{ $num($v['rmse'], $t === 'ispu' ? 2 : 3) }}</td>
                                <td class="num">{{ $num($v['r2'], 3) }}</td>
                            </tr>
                        @endforeach
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endforeach
</div>

<h3 class="section-title">3. Pemilihan nilai k (kNN, semua fitur)</h3>
<div class="grid two">
    <div class="card">
        <h2>Akurasi dan macro-F1 terhadap k</h2>
        <div class="chart-box"><canvas id="kcurve"></canvas></div>
    </div>
    <div class="card">
        <h2>Hasil</h2>
        <p class="lead">
            Nilai k dipilih dari rata-rata macro-F1 kategori ISPU dan tingkat risiko yang paling tinggi.
            Hasilnya <b>k = {{ $k }}</b>, dengan macro-F1 kategori {{ $pct($eval['k_curve']['category_f1'][$k - 1]) }}
            dan tingkat risiko {{ $pct($eval['k_curve']['tier_f1'][$k - 1]) }}.
        </p>
        <p class="lead" style="margin-top:10px">
            k kecil membuat model peka terhadap data yang menyimpang (<i>overfitting</i>), sedangkan k besar mengaburkan batas antar kategori (<i>underfitting</i>).
            Halaman Prediksi otomatis memakai k ini.
        </p>
        <p class="muted" style="margin-top:10px">Catatan: k dipilih dengan cross-validation yang sama dengan pengujian, jadi skor kNN sedikit optimis.</p>
    </div>
</div>

<h3 class="section-title">4. Confusion matrix dan F1 per kelas (kNN k={{ $k }}, semua fitur)</h3>
<div class="grid half">
    @foreach ($targetsCls as $t => $title)
        @php $d = $eval['detail'][$t]; @endphp
        <div class="card">
            <h2>{{ $title }} · akurasi {{ $pct($d['accuracy']) }}, macro-F1 {{ $pct($d['macro_f1']) }}</h2>
            <div class="table-wrap">
                <table class="cm">
                    <thead><tr><th class="row">Aktual ↓ / Prediksi →</th>
                        @foreach ($d['labels'] as $lbl)<th>{{ $t === 'tier' ? \Illuminate\Support\Str::before($lbl, ' -') : $lbl }}</th>@endforeach
                    </tr></thead>
                    <tbody>
                    @foreach ($d['matrix'] as $i => $row)
                        @php $tot = max(1, array_sum($row)); @endphp
                        <tr>
                            <th class="row">{{ $t === 'tier' ? \Illuminate\Support\Str::before($d['labels'][$i], ' -') : $d['labels'][$i] }}</th>
                            @foreach ($row as $j => $v)
                                @php $a = $v / $tot; @endphp
                                <td style="background:{{ $i === $j ? 'rgba(15,118,110,'.(0.12 + 0.6 * $a).')' : ($v ? 'rgba(220,38,38,'.(0.08 + 0.5 * $a).')' : 'transparent') }}">{{ $v }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="table-wrap" style="margin-top:14px">
                <table>
                    <thead><tr><th>Kelas</th><th class="num">Precision</th><th class="num">Recall</th><th class="num">F1</th><th class="num">Jumlah</th></tr></thead>
                    <tbody>
                    @foreach ($d['per_class'] as $lbl => $v)
                        <tr>
                            <td>{{ $lbl }}</td>
                            <td class="num">{{ $pct($v['precision']) }}</td>
                            <td class="num">{{ $pct($v['recall']) }}</td>
                            <td class="num">{{ $pct($v['f1']) }}</td>
                            <td class="num">{{ number_format($v['support'], 0, ',', '.') }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endforeach
</div>
@php
    $catPer = $eval['detail']['category']['per_class'];
    $rare = collect($catPer)->sortBy('support')->keys()->first();
@endphp
<p class="muted">Diagonal hijau = tebakan benar, sel merah = salah. Kelas <b>{{ $rare }}</b> hanya {{ $catPer[$rare]['support'] }} data
    (F1 {{ $pct($catPer[$rare]['f1']) }}), sehingga akurasi saja bisa menipu. Karena itu macro-F1 dipakai sebagai metrik utama.</p>

<h3 class="section-title">5. Pentingnya fitur (permutation importance)</h3>
<div class="grid two">
    <div class="card">
        <h2>Turunnya akurasi saat fitur diacak</h2>
        <div class="chart-box" style="height:360px"><canvas id="imp"></canvas></div>
    </div>
    <div class="card">
        <h2>Cara membaca</h2>
        <p class="lead">
            Nilai satu fitur di data uji diacak, lalu kNN (k={{ $eval['importance']['k'] }}) diuji ulang pada
            {{ $eval['importance']['samples'] }} data. Makin besar akurasinya turun, makin penting fitur itu.
            Akurasi awal: kategori {{ $pct($eval['importance']['base']['category']) }}, tingkat risiko {{ $pct($eval['importance']['base']['tier']) }}.
        </p>
        <ul class="lead" style="margin-top:8px">
            @foreach (array_slice($eval['importance']['features'], 0, 3) as $f)
                <li><b>{{ $f['feature'] }}</b>: akurasi tingkat risiko turun {{ $num($f['tier_drop'] * 100, 1) }} poin</li>
            @endforeach
        </ul>
    </div>
</div>

<h3 class="section-title">6. Kesimpulan dan keterbatasan</h3>
<div class="card">
    <ul class="lead">
        <li>Semua model ML jauh lebih baik dari baseline bila data polutan tersedia. Model terbaik: <b>{{ $modelName($bestCatSemua) }}</b> untuk kategori ISPU
            dan <b>{{ $modelName($bestTierSemua) }}</b> untuk tingkat risiko.</li>
        <li>Untuk nilai dan kategori ISPU, rumus resmi sudah memberi hasil hampir sempurna. Peran ML yang realistis di dataset ini adalah
            meniru aturan pada target yang rumusnya tidak diketahui (tingkat risiko dan beban ISPA), atau bekerja dengan sensor yang terbatas.</li>
        @if ($c['tier']['pm25_cuaca']['knn']['f1'] > $c['tier']['semua']['knn']['f1'])
        <li>kNN dengan PM2.5 + cuaca justru lebih baik daripada kNN dengan semua fitur
            ({{ $pct($c['tier']['pm25_cuaca']['knn']['f1']) }} vs {{ $pct($c['tier']['semua']['knn']['f1']) }} macro-F1 tingkat risiko),
            karena fitur yang tidak relevan ikut dihitung dalam jarak. Ini menunjukkan pemilihan fitur penting untuk kNN.</li>
        @endif
        <li>Datanya sintetis (diperkaya AI), hanya mencakup {{ count(\App\Support\Ispu::PROVINCES) }} provinsi dan periode Januari–Oktober 2026,
            jadi hasilnya belum bisa digeneralisasi ke pengukuran nyata.</li>
    </ul>
</div>
@endif
@endsection

@if ($eval)
@push('scripts')
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
const E = @json($eval);
const scenarioKeys = Object.keys(E.scenarios);
const palette = ['#0f766e', '#2563eb', '#f59e0b'];
const pct = v => (v * 100).toFixed(1) + '%';
Chart.defaults.font.family = 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif';

['category', 'tier'].forEach(t => {
    const models = Object.keys(E.models).filter(m => scenarioKeys.some(s => E.classification[t][s][m]));
    new Chart(document.getElementById('cmp-' + t), {
        type: 'bar',
        data: {
            labels: models.map(m => m === 'knn' ? 'kNN' : E.models[m].replace(/ \(.*\)/, '')),
            datasets: scenarioKeys.map((s, i) => ({
                label: E.scenarios[s].label,
                backgroundColor: palette[i],
                borderRadius: 4,
                data: models.map(m => E.classification[t][s][m] ? E.classification[t][s][m].f1 : null),
            })),
        },
        options: {
            maintainAspectRatio: false,
            scales: { y: { min: 0, max: 1, ticks: { callback: v => (v * 100) + '%' } } },
            plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: c => c.dataset.label + ': ' + pct(c.raw) } } },
        },
    });
});

const kc = E.k_curve;
new Chart(document.getElementById('kcurve'), {
    type: 'line',
    data: {
        labels: kc.k,
        datasets: [
            { label: 'Akurasi kategori', data: kc.category_acc, borderColor: '#0f766e', backgroundColor: '#0f766e', tension: .2, pointRadius: 2 },
            { label: 'Macro-F1 kategori', data: kc.category_f1, borderColor: '#0f766e', backgroundColor: '#0f766e', borderDash: [5, 4], tension: .2, pointRadius: 2 },
            { label: 'Akurasi risiko', data: kc.tier_acc, borderColor: '#f59e0b', backgroundColor: '#f59e0b', tension: .2, pointRadius: 2 },
            { label: 'Macro-F1 risiko', data: kc.tier_f1, borderColor: '#f59e0b', backgroundColor: '#f59e0b', borderDash: [5, 4], tension: .2, pointRadius: 2 },
        ],
    },
    options: {
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        scales: { x: { title: { display: true, text: 'k (jumlah tetangga)' } }, y: { ticks: { callback: v => (v * 100).toFixed(0) + '%' } } },
        plugins: {
            legend: { position: 'bottom' },
            tooltip: { callbacks: { title: i => 'k = ' + i[0].label + (+i[0].label === E.best_k.semua ? ' (terpilih)' : ''), label: c => c.dataset.label + ': ' + pct(c.raw) } },
        },
    },
    plugins: [{
        id: 'bestK',
        afterDatasetsDraw(chart) {
            const x = chart.scales.x.getPixelForValue(E.best_k.semua - 1);
            const { top, bottom } = chart.chartArea;
            const ctx = chart.ctx;
            ctx.save();
            ctx.strokeStyle = '#6b7280'; ctx.setLineDash([3, 3]);
            ctx.beginPath(); ctx.moveTo(x, top); ctx.lineTo(x, bottom); ctx.stroke();
            ctx.fillStyle = '#374151'; ctx.font = '12px system-ui'; ctx.fillText('k=' + E.best_k.semua, x + 4, top + 12);
            ctx.restore();
        },
    }],
});

const imp = E.importance.features;
new Chart(document.getElementById('imp'), {
    type: 'bar',
    data: {
        labels: imp.map(f => f.feature),
        datasets: [
            { label: 'Tingkat risiko', data: imp.map(f => f.tier_drop), backgroundColor: '#f59e0b', borderRadius: 4 },
            { label: 'Kategori ISPU', data: imp.map(f => f.category_drop), backgroundColor: '#0f766e', borderRadius: 4 },
        ],
    },
    options: {
        indexAxis: 'y',
        maintainAspectRatio: false,
        scales: { x: { ticks: { callback: v => (v * 100).toFixed(0) + ' poin' } } },
        plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: c => c.dataset.label + ': turun ' + (c.raw * 100).toFixed(1) + ' poin' } } },
    },
});
</script>
@endpush
@endif
