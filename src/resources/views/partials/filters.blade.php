@php($months = [1=>'Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'])
<form class="filters" method="get">
    <select name="province">
        <option value="">Semua provinsi</option>
        @foreach ($provinceList as $p)
            <option value="{{ $p }}" @selected(($filters['province'] ?? '') === $p)>{{ $p }}</option>
        @endforeach
    </select>
    <select name="region">
        <option value="">Semua tipe wilayah</option>
        @foreach ($regionList as $p)
            <option value="{{ $p }}" @selected(($filters['region'] ?? '') === $p)>{{ $p }}</option>
        @endforeach
    </select>
    <select name="month">
        <option value="">Semua bulan</option>
        @foreach ($months as $i => $m)
            <option value="{{ $i }}" @selected((int) ($filters['month'] ?? 0) === $i)>{{ $m }}</option>
        @endforeach
    </select>
    @if (! empty($extra))
        <select name="category">
            <option value="">Semua kategori</option>
            @foreach (array_keys(\App\Support\Ispu::CATEGORIES) as $c)
                <option value="{{ $c }}" @selected(($filters['category'] ?? '') === $c)>{{ $c }}</option>
            @endforeach
        </select>
        <input type="search" name="q" placeholder="Cari stasiun…" value="{{ $filters['q'] ?? '' }}">
    @endif
    <button type="submit">Terapkan</button>
    <a class="reset" href="{{ url()->current() }}">Reset</a>
</form>
