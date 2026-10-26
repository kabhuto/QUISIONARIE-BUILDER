@extends('layouts.admin')

@section('title', 'Data referensi')

@section('content')
    <div class="page-intro">
        <div>
            <div class="eyebrow"><a class="back-link" href="{{ route('surveys.index') }}">← DASHBOARD</a> <span>/</span> DATA REFERENSI</div>
            <h1>Data referensi.</h1>
            <p>Unggah daftar kode dan nama (mis. kecamatan dan desa) sebagai CSV. Pertanyaan <b>Daftar pilihan</b> dapat mengambil opsinya dari sini, termasuk pilihan berjenjang: desa yang tampil hanya milik kecamatan yang dipilih.</p>
        </div>
    </div>

    <form class="validation-form" method="POST" action="{{ route('references.store') }}" enctype="multipart/form-data">
        @csrf
        <label class="validation-drop">
            <span class="validation-step">1</span>
            <strong>Nama data</strong>
            <small>Mis. "Desa Kabupaten Bangkalan".</small>
            <input class="share-url" type="text" name="name" maxlength="120" value="{{ old('name') }}" required>
        </label>
        <label class="validation-drop">
            <span class="validation-step">2</span>
            <strong>Berkas CSV</strong>
            <small>Baris pertama = judul kolom. Pemisah koma atau titik koma.</small>
            <input type="file" name="file" accept=".csv,text/csv" required>
        </label>
        <button class="button button-primary" type="submit">Unggah</button>
    </form>
    @if ($errors->any())
        <div class="validation-alert" role="alert">{{ $errors->first() }}</div>
    @endif

    <details class="validation-help">
        <summary>Contoh CSV untuk pilihan berjenjang kecamatan → desa</summary>
<pre>kode_kec;nama_kec;kode_desa;nama_desa
010;KAMAL;3526010001;BANYUAJUH
010;KAMAL;3526010002;TAJUNGAN
020;LABANG;3526020001;SUKOLILO BARAT</pre>
        <p>Untuk "Kecamatan": kolom nilai <code>kode_kec</code>, label <code>nama_kec</code>. Untuk "Desa": kolom nilai <code>kode_desa</code>, label <code>nama_desa</code>, bergantung pada pertanyaan Kecamatan dengan kolom penyaring <code>kode_kec</code>.</p>
    </details>

    <section class="panel-section">
        <div class="section-title"><span class="eyebrow">TERSIMPAN</span><h2>{{ $lists->count() }} data referensi</h2></div>
        @if ($lists->isEmpty())
            <div class="empty-state"><h2>Belum ada data referensi</h2><p>Unggah CSV pertama Anda di atas.</p></div>
        @else
            <table class="data-table">
                <thead><tr><th>Nama</th><th>Kode</th><th>Kolom</th><th>Baris</th><th>Diperbarui</th></tr></thead>
                <tbody>
                    @foreach ($lists as $list)
                        <tr>
                            <td><a href="{{ route('references.show', $list) }}">{{ $list->name }}</a></td>
                            <td class="mono">{{ $list->slug }}</td>
                            <td>{{ implode(', ', $list->columns) }}</td>
                            <td>{{ number_format(count($list->rows), 0, ',', '.') }}</td>
                            <td>{{ $list->updated_at->translatedFormat('d M Y, H:i') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
@endsection
