@extends('layouts.admin')

@section('title', 'Validasi data')

@section('actions')
    <a class="button button-ghost" href="{{ route('surveys.index') }}">← Dashboard</a>
@endsection

@section('content')
    <div class="page-intro">
        <div>
            <div class="eyebrow"><a class="back-link" href="{{ route('surveys.index') }}">← DASHBOARD</a> <span>/</span> VALIDASI DATA</div>
            <h1>Validasi data.</h1>
            <p>Gunakan file <b>Ekspor JSON</b> sebuah kuesioner sebagai template aturan, lalu periksa data jawaban dengan aturan yang sama seperti saat responden mengisi formulir.</p>
        </div>
    </div>

    <form class="validation-form" method="POST" action="{{ route('validation.store') }}" enctype="multipart/form-data">
        @csrf
        <label class="validation-drop">
            <span class="validation-step">1</span>
            <strong>Template (JSON ekspor)</strong>
            <small>Wajib. Berisi pertanyaan dan aturan validasi.</small>
            <input type="file" name="template" accept=".json,application/json" required>
        </label>
        <label class="validation-drop">
            <span class="validation-step">2</span>
            <strong>Data yang diperiksa (opsional)</strong>
            <small>JSON berisi daftar record. Kosongkan untuk memeriksa respons yang ada di dalam template.</small>
            <input type="file" name="data" accept=".json,application/json">
        </label>
        <button class="button button-primary" type="submit">Periksa data</button>
    </form>

    @if ($errors->any())
        <div class="validation-alert" role="alert">{{ $errors->first() }}</div>
    @endif

    <details class="validation-help">
        <summary>Format file data yang diterima</summary>
        <p>Kunci jawaban boleh memakai <b>id pertanyaan</b> (lihat <code>validation_rules</code> di file ekspor) atau <b>nomor pertanyaan</b> seperti tercetak di formulir.</p>
        <pre>[
  { "101": { "kecamatan": "GEGER", "desa": "DABUNG" }, "102": "Budi", "103": "Laki-laki" },
  { "answers": { "q-nama": "Siti", "q-jk": "Perempuan" } }
]</pre>
        <p>File ekspor JSON lain, atau <code>{"responses": [ ... ]}</code>, juga bisa dipakai langsung.</p>
    </details>

    @if ($result)
        <section class="panel-section">
            <div class="section-title"><span class="eyebrow">HASIL</span><h2>{{ $result['survey']->title }}</h2></div>
            <p class="muted">Memeriksa {{ $result['source'] }} dengan {{ count($result['survey']->questions()) }} aturan pertanyaan.</p>
            <div class="stat-row validation-stats">
                <div class="stat-tile"><span>Total record</span><strong>{{ $result['total'] }}</strong></div>
                <div class="stat-tile is-good"><span>Valid</span><strong>{{ $result['valid'] }}</strong></div>
                <div class="stat-tile is-bad"><span>Bermasalah</span><strong>{{ $result['invalid'] }}</strong></div>
            </div>

            @if ($result['total'] === 0)
                <div class="empty-state"><h2>Tidak ada record untuk diperiksa</h2><p>Template ini belum berisi respons. Unggah file data pada langkah 2.</p></div>
            @else
                <div class="table-wrap">
                    <table class="data-table validation-table">
                        <thead>
                            <tr><th scope="col">Record</th><th scope="col">Status</th><th scope="col">Temuan</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($result['records'] as $record)
                                <tr>
                                    <td class="mono">{{ $record['label'] }}</td>
                                    <td>
                                        <span @class(['status-badge', 'is-published' => $record['errors'] === []])>{{ $record['errors'] === [] ? 'Valid' : count($record['errors']).' kesalahan' }}</span>
                                    </td>
                                    <td>
                                        @foreach ($record['errors'] as $error)
                                            <div class="validation-error"><span class="mono-chip">{{ $error['number'] }}</span> {{ $error['message'] }}</div>
                                        @endforeach
                                        @if ($record['unknown'] !== [])
                                            <div class="validation-warning">Kolom tidak dikenal, diabaikan: {{ implode(', ', $record['unknown']) }}</div>
                                        @endif
                                        @if ($record['errors'] === [] && $record['unknown'] === [])
                                            <span class="muted">Sesuai semua aturan.</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif
@endsection
