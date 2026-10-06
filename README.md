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
