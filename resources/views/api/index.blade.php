@extends('layouts.admin')

@section('title', 'Akses API')

@php
    $base = url('/api/v1');
    $surveyId = $exampleSurvey?->id ?? 1;
@endphp

@section('content')
    <div class="page-intro">
        <div>
            <div class="eyebrow"><a class="back-link" href="{{ route('surveys.index') }}">← DASHBOARD</a> <span>/</span> AKSES API</div>
            <h1>Akses API.</h1>
            <p>Hubungkan aplikasi lain — aplikasi CAPI, dasbor, sistem pengolahan — dengan kuesioner di sini: baca dan buat kuesioner, kirim dan ambil respons, periksa jawaban, buat penugasan berpredata, dan terima webhook.</p>
        </div>
    </div>

    @if (session('newToken'))
        <div class="share-bar api-new-token">
            <span class="share-label">TOKEN BARU</span>
            <input class="share-url" type="text" value="{{ session('newToken') }}" readonly id="new-token" aria-label="Token API baru">
            <button class="button button-ghost" type="button" data-copy="#new-token">Salin</button>
        </div>
        <p class="api-warning">Simpan token ini sekarang. Demi keamanan, token tidak bisa ditampilkan lagi.</p>
    @endif

    <section class="panel-section">
        <div class="section-title"><span class="eyebrow">TOKEN</span><h2>Token akses</h2></div>
        <form class="api-token-form" method="POST" action="{{ route('api-access.store') }}">
            @csrf
            <input class="share-url" type="text" name="name" maxlength="120" placeholder="Nama pemakai token, mis. Aplikasi CAPI Kecamatan" required aria-label="Nama token">
            <label class="api-check"><input type="hidden" name="can_write" value="0"><input type="checkbox" name="can_write" value="1"> Boleh mengubah data (kirim respons, buat kuesioner)</label>
            <button class="button button-primary" type="submit">Buat token</button>
        </form>
        @error('name')<p class="validation-alert">{{ $message }}</p>@enderror

        @if ($tokens->isEmpty())
            <p class="muted">Belum ada token.</p>
        @else
            <table class="data-table">
                <thead><tr><th>Nama</th><th>Izin</th><th>Dibuat</th><th>Terakhir dipakai</th><th></th></tr></thead>
                <tbody>
                    @foreach ($tokens as $token)
                        <tr>
                            <td>{{ $token->name }}</td>
                            <td>{{ $token->can_write ? 'Baca & tulis' : 'Baca saja' }}</td>
                            <td>{{ $token->created_at->translatedFormat('d M Y, H:i') }}</td>
                            <td>{{ $token->last_used_at?->diffForHumans() ?? 'Belum pernah' }}</td>
                            <td>
                                <form method="POST" action="{{ route('api-access.destroy', $token) }}" onsubmit="return confirm('Cabut token {{ $token->name }}? Aplikasi yang memakainya tidak bisa mengakses lagi.')">
                                    @csrf @method('DELETE')
                                    <button class="link-danger" type="submit">Cabut</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    <section class="panel-section api-docs">
        <div class="section-title"><span class="eyebrow">DOKUMENTASI</span><h2>Cara memakai API</h2></div>
        <p>Alamat dasar <code>{{ $base }}</code>. Kirim token di setiap permintaan sebagai header <code>Authorization: Bearer &lt;token&gt;</code>. Semua data berformat JSON; jawaban memakai <b>id pertanyaan</b> (lihat <code>numbers</code> pada definisi kuesioner untuk padanan nomor 101, 102, …).</p>

        <table class="data-table api-table">
            <thead><tr><th>Metode & alamat</th><th>Kegunaan</th><th>Izin</th></tr></thead>
            <tbody>
                <tr><td><code>GET /surveys</code></td><td>Daftar kuesioner beserta tautan formulir dan jumlah respons.</td><td>baca</td></tr>
                <tr><td><code>GET /surveys/{id}</code></td><td>Definisi lengkap (blok, pertanyaan, logika, validasi, rumus) + nomor pertanyaan + ringkasan aturan. Tambah <code>?format=formgear</code> untuk template FormGear/FASIH.</td><td>baca</td></tr>
                <tr><td><code>POST /surveys</code></td><td>Buat kuesioner dari JSON — file ekspor Ruang Kuesioner, bagian <code>survey</code>-nya, atau template FormGear (<code>{"template": …, "validation": …}</code>). Tambah <code>"publish": true</code> untuk langsung terbit.</td><td>tulis</td></tr>
                <tr><td><code>PUT /surveys/{id}</code></td><td>Ganti definisi kuesioner.</td><td>tulis</td></tr>
                <tr><td><code>POST / DELETE /surveys/{id}/publish</code></td><td>Terbitkan / tarik kuesioner.</td><td>tulis</td></tr>
                <tr><td><code>POST /surveys/{id}/validate</code></td><td>Periksa jawaban <b>tanpa menyimpan</b>: galat, peringatan yang perlu dikonfirmasi, hasil rumus, baris rincian, dan pertanyaan yang tampil menurut logika.</td><td>baca</td></tr>
                <tr><td><code>GET /surveys/{id}/responses</code></td><td>Respons berhalaman. Filter <code>?since=2026-10-01</code>, <code>?after_id=120</code>, <code>?per_page=200</code>.</td><td>baca</td></tr>
                <tr><td><code>POST /surveys/{id}/responses</code></td><td>Kirim satu respons; diperiksa dengan aturan yang sama seperti formulir. Peringatan dikonfirmasi lewat <code>confirmations</code>.</td><td>tulis</td></tr>
                <tr><td><code>POST /surveys/{id}/responses/batch</code></td><td>Kirim sampai 500 respons sekaligus (mis. sinkronisasi CAPI luring); hasil per respons diterima/ditolak.</td><td>tulis</td></tr>
                <tr><td><code>GET / POST /surveys/{id}/assignments</code></td><td>Penugasan: tautan pribadi dengan jawaban awal (predata), sebagian bisa dikunci, sekali kirim.</td><td>baca / tulis</td></tr>
                <tr><td><code>GET /surveys/{id}/lookup/{pertanyaan}?parent=</code></td><td>Opsi daftar pilihan yang diambil dari data referensi atau API.</td><td>baca</td></tr>
                <tr><td><code>GET / POST / PUT /references</code></td><td>Data referensi (mis. master desa) untuk daftar pilihan berjenjang; kirim <code>columns</code> + <code>rows</code> atau <code>csv</code>.</td><td>baca / tulis</td></tr>
            </tbody>
        </table>

        <h3>Contoh: periksa lalu kirim respons</h3>
<pre>curl -X POST {{ $base }}/surveys/{{ $surveyId }}/validate \
  -H "Authorization: Bearer rk_xxx" -H "Content-Type: application/json" \
  -d '{"answers": {"q-jk": "Laki-laki", "q-hamil": "Ya"}}'

# Jawaban:
{
  "valid": false,
  "errors": {"answers.q-hamil": ["Responden laki-laki tidak mungkin hamil."]},
  "warnings": [],
  "computed": {"q-total": "2200000"},
  "rows": {"b-art": ["Budi", "Ani"]},
  "visible_questions": ["q-jk", "q-hamil", "…"]
}

curl -X POST {{ $base }}/surveys/{{ $surveyId }}/responses \
  -H "Authorization: Bearer rk_xxx" -H "Content-Type: application/json" \
  -d '{"answers": {…}, "confirmations": {"k-jam": 1}, "submitted_at": "2026-10-26T09:30:00+07:00"}'</pre>
        <p>Pertanyaan di <b>blok berulang</b> dijawab sebagai daftar per baris, mis. <code>"q-umur": ["40", "8"]</code>; konfirmasi peringatannya per baris: <code>"confirmations": {"k-anak": {"1": 1}}</code>. Berkas diunggah dengan <code>multipart/form-data</code> (<code>answers[q-foto]=@foto.jpg</code>).</p>

        <h3>Contoh: penugasan berpredata</h3>
<pre>curl -X POST {{ $base }}/surveys/{{ $surveyId }}/assignments \
  -H "Authorization: Bearer rk_xxx" -H "Content-Type: application/json" \
  -d '{"assignments": [
        {"label": "Ruta 001 - Budi", "predata": {"q-krt": "Budi", "q-nks": "1201"}, "locked": ["q-nks"]},
        {"label": "Ruta 002 - Siti", "predata": {"q-krt": "Siti", "q-nks": "1208"}, "locked": ["q-nks"], "expires_at": "2026-12-31"}
      ]}'

# Setiap penugasan berisi "url" pribadi untuk responden/petugas, dan "status": open | submitted | expired.</pre>

        <h3>Webhook</h3>
        <p>Isi <b>Webhook respons baru</b> di Properti formulir. Setiap respons baru dikirim sebagai POST JSON <code>{"event": "response.created", "survey": {…}, "response": {…}}</code> dengan header <code>X-Ruang-Signature: sha256=&lt;HMAC-SHA256 badan permintaan&gt;</code>. Kunci verifikasi:</p>
        <div class="share-bar">
            <span class="share-label">KUNCI WEBHOOK</span>
            <input class="share-url" type="text" value="{{ $webhookSecret }}" readonly id="webhook-secret" aria-label="Kunci webhook">
            <button class="button button-ghost" type="button" data-copy="#webhook-secret">Salin</button>
        </div>
    </section>
@endsection
