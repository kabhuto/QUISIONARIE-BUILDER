# Memasang Ruang Kuesioner di Jagoan Hosting (cPanel)

Panduan ini untuk paket Jagoan Hosting yang memakai **cPanel**. Pengambilan kode dari GitHub (*pull*) dan pemasangan (*deploy*) dilakukan lewat menu **Git™ Version Control**. Perintah `php artisan` sekali jalan di awal memakai menu **Terminal** cPanel (atau SSH).

## 1. Siapkan di cPanel

1. **Versi PHP**: *Software → Select PHP Version* (atau *MultiPHP Manager*) → pilih **8.3** atau **8.4** (minimal 8.3). Di daftar *Extensions* pastikan **gd, zip, exif, fileinfo, mbstring, pdo_mysql, intl** tercentang.
2. **Database**: *Databases → MySQL® Database Wizard* → buat database, buat user, beri user **ALL PRIVILEGES**. Nama database dan user otomatis diawali nama akun cPanel, mis. `bpsbkl_kuesioner`. Catat nama database, nama user, dan kata sandinya.
3. **SSL**: *Security → SSL/TLS Status* → jalankan **AutoSSL** untuk domain. HTTPS wajib, karena tanpa https browser HP tidak mau memberi lokasi GPS.

## 2. Ambil kode dari GitHub (clone)

*Files → Git™ Version Control* → **Create**:

- **Clone a Repository**: aktif
- **Clone URL**: `https://github.com/kabhuto/QUISIONARIE-BUILDER.git`
- **Repository Path**: `ruang-kuesioner` (folder baru di luar `public_html`, jadi kode dan `.env` tidak bisa dibuka dari browser)
- **Repository Name**: `Ruang Kuesioner`

Tekan **Create**.

## 3. Arahkan domain ke folder `public`

Hanya folder `ruang-kuesioner/public` yang boleh terbuka di web. Pilih salah satu cara:

- **Subdomain atau addon domain** (mis. `kuesioner.domain-anda.id`): *Domains* → buat/ubah domain → **Document Root** diisi `ruang-kuesioner/public`.
- **Domain utama** (`public_html`): buka **Terminal**, lalu ganti `public_html` menjadi tautan ke folder `public`:

  ```bash
  mv ~/public_html ~/public_html.lama
  ln -s ~/ruang-kuesioner/public ~/public_html
  ```

## 4. Pemasangan pertama (Terminal)

*Advanced → Terminal*:

```bash
cd ~/ruang-kuesioner
cp .env.hostinger.example .env
nano .env                    # isi APP_URL, DB_*, dan ADMIN_PASSWORD (DB_HOST tetap localhost)
bash deploy.sh               # composer install, kunci aplikasi, migrasi, akun admin, cache
php artisan db:seed --class=ReadySurveySeeder --force  # kuesioner Absensi Harian Peserta Magang
```

### Akun admin otomatis (tanpa mengetik perintah)

Akun admin dibuat sendiri setiap deploy dari dua baris di `.env` server (ADMIN_USERNAME boleh email atau username):

```
ADMIN_USERNAME=test@example.com
ADMIN_PASSWORD=password
```

Tanpa Terminal: buka **File Manager** → folder `ruang-kuesioner` → (centang *Show Hidden Files* di *Settings*) → salin `.env.hostinger.example` menjadi `.env` → **Edit**, isi `APP_URL`, `DB_*`, `ADMIN_PASSWORD`. Lalu di **Git™ Version Control** tekan **Deploy HEAD Commit**: akun langsung ada dan bisa dipakai masuk. Mengganti `ADMIN_PASSWORD` lalu deploy lagi = mengganti kata sandi. Kata sandi ini hanya ada di server, tidak ikut GitHub.

Jika `php -v` di Terminal masih menunjukkan versi lama, `deploy.sh` otomatis memakai PHP dari `/opt/alt/php83` atau `php84`. Untuk perintah `artisan` lain, tulis lengkap, mis. `/opt/alt/php83/usr/bin/php artisan ...`.

Buka `https://<domain-anda>/masuk` dan masuk dengan akun admin tadi.

## 5. Cron untuk antrean (salinan ke Google Drive, dsb.)

*Advanced → Cron Jobs* → **Once Per Minute** (`* * * * *`), perintah:

```
/opt/alt/php83/usr/bin/php /home/<akun-cpanel>/ruang-kuesioner/artisan schedule:run >/dev/null 2>&1
```

## 6. Memperbarui setelah ada perubahan di GitHub (pull)

*Files → Git™ Version Control* → **Manage** pada *Ruang Kuesioner* → tab **Pull or Deploy**:

1. **Update from Remote**: menarik commit terbaru dari GitHub.
2. **Deploy HEAD Commit**: menjalankan `.cpanel.yml`, yaitu `bash deploy.sh` (composer, migrasi, cache) secara otomatis.

Lewat Terminal juga bisa: `cd ~/ruang-kuesioner && git pull && bash deploy.sh`.

## Catatan

- **Deploy gagal?** Log ada di `~/.cpanel/logs/` (berkas `vc_..._git_deploy.log`). cPanel menolak deploy bila ada berkas yang diubah langsung di server. Jangan mengedit kode di File Manager, ubah lewat GitHub saja (`.env`, `vendor`, dan unggahan memang tidak ikut Git, jadi aman).
- **Data tidak ikut GitHub.** Database, berkas unggahan, dan `.env` hanya ada di server. Kuesioner dari komputer lokal bisa dipindahkan lewat **Ekspor JSON** lalu **Impor JSON**.
- **Halaman admin wajib login** (`ADMIN_OPEN_ON_LAN=false`). Responden tetap mengisi tanpa login lewat tautan `https://<domain-anda>/f/<kode>`.
- **Lupa kata sandi admin**: jalankan lagi `php artisan admin:buat admin3526`.
- **Galat 500**: lihat `storage/logs/laravel.log`, dan pastikan `storage` dan `bootstrap/cache` bisa ditulis (`chmod -R 775 storage bootstrap/cache`).
- **`Unknown column 'username'` (atau kolom lain tidak ditemukan)**: migrasi database belum dijalankan setelah pull. Jalankan **Deploy HEAD Commit**, atau di Terminal `cd ~/ruang-kuesioner && php artisan migrate --force`.
- **Halaman galat menampilkan detail kode/SQL**: berarti `APP_DEBUG=true`. Di server wajib `APP_ENV=production` dan `APP_DEBUG=false` di `.env`, lalu `php artisan optimize`.
