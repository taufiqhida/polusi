@extends('layout')
@section('title', 'Data observasi')

@section('content')
@include('partials.filters', ['extra' => true])

<div class="card">
    <h2>{{ number_format($records->total(), 0, ',', '.') }} observasi</h2>
    <div class="table-wrap"><table>
        <thead><tr><th>Waktu</th><th>Provinsi</th><th>Stasiun</th><th>PM2.5</th><th>PM10</th><th>ISPU</th><th>Kategori</th><th>Asap</th></tr></thead>
        <tbody>
        @forelse ($records as $o)
            <tr>
                <td style="white-space:nowrap">{{ $o->observed_at->format('d/m/Y H:i') }}</td>
                <td>{{ $o->province }}</td>
                <td><a href="{{ route('show', $o) }}">{{ $o->station }}</a></td>
                <td>{{ $o->pm25 }}</td><td>{{ $o->pm10 }}</td><td><b>{{ $o->ispu }}</b></td>
                <td><span class="badge" style="background:{{ \App\Support\Ispu::color($o->ispu_category) }}">{{ $o->ispu_category }}</span></td>
                <td>{{ $o->wildfire_smoke ? '🔥' : '' }}</td>
            </tr>
        @empty
            <tr><td colspan="8">Tidak ada data.</td></tr>
        @endforelse
        </tbody>
    </table></div>

    @if ($records->hasPages())
        <div class="pager">
            @if ($records->onFirstPage()) <span>‹</span> @else <a href="{{ $records->previousPageUrl() }}">‹ Sebelumnya</a> @endif
            <span class="cur">Hal. {{ $records->currentPage() }} / {{ $records->lastPage() }}</span>
            @if ($records->hasMorePages()) <a href="{{ $records->nextPageUrl() }}">Berikutnya ›</a> @else <span>›</span> @endif
        </div>
    @endif
</div>
@endsection
