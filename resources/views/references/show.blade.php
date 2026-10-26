@extends('layouts.admin')

@section('title', $list->name.' · Data referensi')

@section('actions')
    <form method="POST" action="{{ route('references.destroy', $list) }}" onsubmit="return confirm('Hapus data referensi ini? Daftar pilihan yang memakainya akan kosong.')">
        @csrf @method('DELETE')
        <button class="button button-ghost" type="submit">Hapus</button>
    </form>
@endsection

@section('content')
    <div class="page-intro">
        <div>
            <div class="eyebrow"><a class="back-link" href="{{ route('references.index') }}">← DATA REFERENSI</a> <span>/</span> {{ strtoupper($list->slug) }}</div>
            <h1>{{ $list->name }}</h1>
            <p>{{ number_format(count($list->rows), 0, ',', '.') }} baris · kolom {{ implode(', ', $list->columns) }}</p>
        </div>
    </div>

    <section class="panel-section">
        <div class="section-title"><span class="eyebrow">PRATINJAU</span><h2>{{ min(100, count($list->rows)) }} baris pertama</h2></div>
        <div class="detail-roster-wrap">
            <table class="data-table">
                <thead><tr>@foreach ($list->columns as $column)<th>{{ $column }}</th>@endforeach</tr></thead>
                <tbody>
                    @foreach (array_slice($list->rows, 0, 100) as $row)
                        <tr>@foreach ($list->columns as $column)<td>{{ $row[$column] ?? '' }}</td>@endforeach</tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endsection
