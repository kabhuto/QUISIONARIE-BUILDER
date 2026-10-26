# Ruang Kuesioner

Aplikasi pembuat kuesioner untuk kegiatan BPS, berbasis Laravel. Kuesioner disusun dengan drag and drop, diisi responden lewat tautan (tampilan bergaya CAWI), lalu hasilnya bisa dicetak, dilihat sebagai listing, diekspor, dan divalidasi.

## Kebutuhan

- PHP 8.3 atau lebih baru, dengan ekstensi `pdo_sqlite`, `sqlite3`, `fileinfo`, dan `gd`
- Composer 2
- Git

Node.js **tidak** diperlukan: CSS dan JavaScript aplikasi sudah tersedia di folder `public/`.

## Memulai setelah clone

```bash
git clone https://github.com/kabhuto/QUISIONARIE-BUILDER.git
cd QUISIONARIE-BUILDER
composer run setup
php artisan serve
```

Buka http://127.0.0.1:8000.

`composer run setup` melakukan semuanya sekaligus: memasang dependensi, membuat `.env` dari `.env.example`, membuat `APP_KEY`, membuat database SQLite, menjalankan migrasi beserta data dummy, dan membuat link penyimpanan logo (`public/storage`).

Data dummy yang ikut dimuat:

- **DUMMY: Survei Kebutuhan Data Konsumen 2026**: 2 blok, 10 pertanyaan, 10 respons
- **DUMMY: Survei Sosial Ekonomi Rumah Tangga 2026**: 8 blok, 40 pertanyaan, 10 respons

## Setelah pull perubahan terbaru

```bash
git pull
composer install
php artisan migrate --seed
```

`migrate --seed` aman dijalankan berulang: data dummy dan user test tidak akan dibuat ganda.

## Menjalankan test

```bash
php artisan test
```

## Catatan

- File berikut **tidak** ada di repositori dan dibuat di masing-masing komputer: `.env`, `database/database.sqlite` (semua kuesioner dan respons), logo yang diunggah (`storage/app/public`), dan `vendor/`.
- Untuk memindahkan kuesioner beserta responsnya antarkomputer, gunakan **Ekspor JSON** di dashboard, lalu **Impor JSON** di komputer tujuan.
- Belum ada login admin. Jangan pasang di server publik sebelum fitur login ditambahkan.

## Fitur utama

- Builder drag and drop: blok, pertanyaan, kolom tabel, kop (logo, teks, label), dan label lembar
- Jenis isian: teks, paragraf, pilihan tunggal/ganda, daftar pilihan, angka, Rupiah, tanggal, email, telepon, isian kode, identitas wilayah, tabel/listing, keterangan
- Logika tampil dan pertanyaan lanjutan (mis. muncul jika menjawab "Ya")
- Formulir responden bergaya CAWI dengan simpan sementara di perangkat
- Cetak formulir kertas (kosong atau terisi), tampilan listing, ekspor CSV
- Ekspor/impor JSON dan validasi data memakai JSON sebagai template aturan
- Pengaturan per pertanyaan (Opsi / Logika / Validasi): wajib, tersembunyi, nonaktif, logika DAN/ATAU, pemeriksaan konsistensi galat/peringatan, pembanding rentang, tag jawaban `@`
- Isian dinamis ala FormGear/FASIH: **Hitungan (rumus)** seperti `{{201}} + {{202}}`, `round()`, `years()`, `sum()`; tag jawaban `{{101}}` di teks pertanyaan; opsi dari jawaban pertanyaan lain; opsi "Lainnya (sebutkan)"; masking (`99.99.999`); jam; lokasi GPS; unggah foto/berkas
- **Rincian berulang (roster)**: blok diulang per jumlah, per pilihan yang dicentang, atau per nama di **Daftar nama**; logika, validasi, dan rumus berjalan per baris; `{{@baris}}` menampilkan nama rincian
- **Data referensi** (CSV) untuk daftar pilihan, termasuk pilihan berjenjang (kecamatan → desa), atau opsi dari API luar
- **Penugasan**: tautan pribadi berpredata (bisa dikunci), sekali kirim; dibuat dari CSV atau lewat API
- **API v1** bertoken (menu *Akses API*, berisi dokumentasi): kuesioner, respons (satu/batch), validasi tanpa simpan, penugasan, data referensi; **webhook** respons baru bertanda tangan HMAC
- **FormGear/FASIH**: impor template (+ validation + preset) dan ekspor ZIP `template.json`, `validation.json`, `preset.json`

## API singkat

Buat token di menu **Akses API**, lalu kirim header `Authorization: Bearer <token>`:

```bash
curl http://127.0.0.1:8000/api/v1/surveys -H "Authorization: Bearer rk_xxx"
curl -X POST http://127.0.0.1:8000/api/v1/surveys/1/validate -H "Authorization: Bearer rk_xxx"      -H "Content-Type: application/json" -d '{"answers": {"q-jk": "Laki-laki", "q-hamil": "Ya"}}'
```

Daftar lengkap endpoint dan contohnya ada di halaman Akses API.
