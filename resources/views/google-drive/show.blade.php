@extends('layouts.admin')

@section('title', 'Google Drive · '.$survey->title)

@section('actions')
    <a class="button button-ghost" href="{{ route('surveys.responses.index', $survey) }}">← Respons</a>
    <a class="button button-ghost" href="{{ route('surveys.edit', $survey) }}">Builder</a>
@endsection

@section('content')
    <div class="page-intro">
        <div>
            <div class="eyebrow"><a class="back-link" href="{{ route('surveys.responses.index', $survey) }}">← RESPONS</a> <span>/</span> GOOGLE DRIVE</div>
            <h1>Google Drive.</h1>
            <p>Setiap respons <b>{{ $survey->title }}</b> disalin ke folder Google Drive: foto, berkas, dan tanda tangan masuk ke subfolder per tanggal, dan satu baris per respons ditambahkan ke spreadsheet <i>Rekap</i>. Data tetap tersimpan juga di aplikasi ini.</p>
        </div>
        <div class="stat-row">
            <div class="stat-tile {{ $url !== '' ? 'is-good' : '' }}"><span>Status</span><strong>{{ $url !== '' ? 'Tersambung' : 'Belum' }}</strong></div>
            <div class="stat-tile is-good"><span>Tersalin</span><strong>{{ $counts['sent'] ?? 0 }}</strong></div>
            <div class="stat-tile"><span>Menunggu / gagal</span><strong>{{ ($counts['pending'] ?? 0) + ($counts['failed'] ?? 0) }}</strong></div>
        </div>
    </div>

    @if ($errors->any())
        <div class="validation-alert" role="alert">{{ $errors->first() }}</div>
    @endif

    <section class="panel-section api-docs">
        <h3>1. Folder tujuan</h3>
        <form class="api-token-form" method="GET" action="{{ route('google-drive.show', $survey) }}">
            <input class="share-url" type="text" name="folder" value="{{ $folderLink }}" placeholder="https://drive.google.com/drive/folders/…" required aria-label="Tautan folder Google Drive">
            <button class="button button-ghost" type="submit">Buat skrip</button>
        </form>
        @if ($folderLink !== '' && $folderId === null)
            <p class="api-warning">Tautan folder tidak dikenali. Salin dari alamat folder di Google Drive, mis. https://drive.google.com/drive/folders/1AbC…</p>
        @endif

        @if ($script !== null)
            <h3>2. Pasang skrip di akun Google pemilik folder</h3>
            <ol>
                <li>Masuk ke Google dengan akun yang <b>memiliki atau boleh mengedit</b> folder itu, lalu buka <a href="https://script.google.com/home/projects/create" target="_blank" rel="noopener">script.google.com → Proyek baru</a>.</li>
                <li>Hapus isi editor, lalu tempel skrip di bawah ini (tombol <b>Salin</b>). Beri nama proyek, mis. <i>PRISMA Drive</i>, dan simpan (Ctrl+S).</li>
                <li>Klik <b>Terapkan → Deployment baru</b>, pilih jenis <b>Aplikasi web</b>. Isi <b>Jalankan sebagai: Saya</b> dan <b>Yang memiliki akses: Siapa saja</b>, lalu <b>Terapkan</b>.</li>
                <li>Saat diminta, klik <b>Izinkan akses</b>, pilih akun Anda. Bila muncul "Google belum memverifikasi aplikasi ini", klik <b>Lanjutan → Buka PRISMA Drive (tidak aman)</b> → <b>Izinkan</b>. Ini wajar untuk skrip buatan sendiri.</li>
                <li>Salin <b>URL aplikasi web</b> (berakhiran <code>/exec</code>) dan tempel di langkah 3.</li>
            </ol>
            <div class="api-token-form">
                <textarea class="share-url mono-input" id="drive-script" rows="12" readonly aria-label="Skrip Apps Script">{{ $script }}</textarea>
            </div>
            <button class="button button-primary" type="button" data-copy="#drive-script">Salin skrip</button>
            <p class="muted">Skrip memuat kunci khusus aplikasi ini, sehingga hanya PRISMA yang bisa menulis ke folder lewat skrip tersebut.</p>
        @endif

        <h3>3. Alamat Web App</h3>
        <form class="api-token-form" method="POST" action="{{ route('google-drive.update', $survey) }}">
            @csrf @method('PUT')
            <input class="share-url" type="url" name="drive_url" value="{{ old('drive_url', $url) }}" placeholder="https://script.google.com/macros/s/…/exec" aria-label="Alamat Web App Apps Script">
            <button class="button button-primary" type="submit">Simpan &amp; tes</button>
        </form>
        <p class="muted">Kosongkan lalu simpan untuk melepas sambungan.</p>

        @if ($url !== '')
            <h3>4. Respons lama</h3>
            <p>Respons yang masuk sebelum tersambung, atau yang gagal disalin, bisa dikirim sekarang.</p>
            <form method="POST" action="{{ route('google-drive.send-all', $survey) }}">
                @csrf
                <button class="button button-ghost" type="submit">Kirim semua yang belum tersalin</button>
            </form>
        @endif
    </section>
@endsection
