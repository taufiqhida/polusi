<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Dashboard') · ISPU Indonesia 2026</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
    <style>
        :root { --bg:#f4f6f8; --card:#fff; --text:#111827; --muted:#6b7280; --line:#e5e7eb; --brand:#0f766e; }
        * { box-sizing: border-box; }
        body { margin:0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background:var(--bg); color:var(--text); }
        header { background:var(--brand); color:#fff; }
        .wrap { max-width:1200px; margin:0 auto; padding:0 16px; }
        nav { display:flex; align-items:center; gap:24px; height:60px; }
        nav .logo { font-weight:700; font-size:18px; margin-right:auto; }
        nav a { color:#d1fae5; text-decoration:none; } nav a.active, nav a:hover { color:#fff; }
        @media (max-width: 600px) { nav { height:auto; flex-wrap:wrap; gap:6px 16px; padding:12px 0; } nav .logo { width:100%; } }
        main { padding:24px 0 48px; }
        .notice { background:#fef3c7; border:1px solid #fcd34d; color:#78350f; padding:10px 14px; border-radius:8px; font-size:13px; margin-bottom:20px; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:18px; }
        .card h2 { font-size:15px; margin:0 0 14px; }
        .grid { display:grid; gap:16px; } .grid > * { min-width:0; }
        .kpis { grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)); margin-bottom:16px; }
        .kpi .label { color:var(--muted); font-size:13px; } .kpi .value { font-size:28px; font-weight:700; margin-top:4px; }
        .two { grid-template-columns: 2fr 1fr; } .half { grid-template-columns: 1fr 1fr; }
        @media (max-width: 860px) { .two, .half { grid-template-columns:1fr; } }
        form.filters { display:flex; flex-wrap:wrap; gap:10px; margin-bottom:16px; }
        select, input, button { font:inherit; padding:8px 10px; border:1px solid var(--line); border-radius:8px; background:#fff; }
        button { background:var(--brand); color:#fff; border:0; cursor:pointer; }
        a.reset { align-self:center; color:var(--muted); font-size:14px; }
        table { width:100%; border-collapse:collapse; font-size:14px; }
        th, td { text-align:left; padding:9px 8px; border-bottom:1px solid var(--line); }
        th { color:var(--muted); font-weight:600; font-size:12px; text-transform:uppercase; }
        .badge { display:inline-block; padding:2px 8px; border-radius:999px; color:#fff; font-size:12px; white-space:nowrap; }
        .table-wrap { overflow-x:auto; }
        #map { height:420px; border-radius:8px; }
        .pager { display:flex; gap:6px; flex-wrap:wrap; margin-top:14px; }
        .pager a, .pager span { padding:6px 10px; border:1px solid var(--line); border-radius:6px; background:#fff; color:var(--text); text-decoration:none; font-size:14px; }
        .pager .cur { background:var(--brand); color:#fff; }
        footer { color:var(--muted); font-size:12px; text-align:center; padding:20px; }
    </style>
    @stack('head')
</head>
<body>
<header>
    <div class="wrap">
        <nav>
            <span class="logo">🌫️ ISPU Indonesia 2026</span>
            <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}">Dashboard</a>
            <a href="{{ route('records') }}" class="{{ request()->routeIs('records', 'show') ? 'active' : '' }}">Data</a>
            <a href="{{ route('predict') }}" class="{{ request()->routeIs('predict') ? 'active' : '' }}">Prediksi</a>
            <a href="{{ route('evaluation') }}" class="{{ request()->routeIs('evaluation') ? 'active' : '' }}">Evaluasi</a>
        </nav>
    </div>
</header>
<main class="wrap">
    <div class="notice">
        Data demo/riset dari Kaggle ("Indonesia Kualitas Udara Polusi ISPU 2026"). Datanya diperkaya dengan AI dan
        bukan pengukuran resmi real-time KLHK/BMKG. Jangan dipakai sebagai acuan kesehatan.
    </div>
    @yield('content')
</main>
<footer>Sumber: kaggle.com/datasets/luthfimuthathohirin/indonesia-kualitas-udara-polusi-ispu-2026</footer>
@stack('scripts')
</body>
</html>
