# Memasang Ruang Kuesioner di Hostinger

Panduan ini untuk paket Hostinger yang punya **akses SSH** (Premium, Business, atau Cloud). Paket tanpa SSH tidak bisa menjalankan `composer` dan `php artisan`.

## 1. Siapkan di hPanel

1. **Versi PHP**: *Advanced → PHP Configuration* → pilih **PHP 8.3** atau **8.4** (minimal 8.3). Di tab *PHP Extensions* pastikan **gd, zip, exif, fileinfo, mbstring, pdo_mysql** tercentang.
2. **Database**: *Databases → MySQL Databases* → buat database dan user baru. Catat nama database, nama user, dan kata sandinya.
3. **SSH**: *Advanced → SSH Access* → aktifkan, catat perintah login (mis. `ssh -p 65002 u123456789@1.2.3.4`).
4. **SSL**: *Security → SSL* → pastikan SSL domain aktif. HTTPS wajib, karena tanpa https browser HP tidak mau memberi lokasi GPS.

## 2. Ambil kode dari GitHub

Cara termudah: *Advanced → Git* di hPanel.

- **Repository**: `https://github.com/kabhuto/QUISIONARIE-BUILDER.git` (bila repo privat: tambahkan *SSH key* yang ditampilkan hPanel ke GitHub → repo → *Settings → Deploy keys*, lalu pakai alamat `git@github.com:kabhuto/QUISIONARIE-BUILDER.git`).
- **Branch**: `main`
- **Directory**: kosongkan (= `public_html`). Folder `public_html` harus kosong dulu; hapus `default.php` bawaan Hostinger.

Berkas `.htaccess` di folder utama repo sudah mengarahkan semua pengunjung ke folder `public/`, jadi `.env`, kode, dan database tidak bisa dibuka dari browser.

## 3. Pemasangan pertama (lewat SSH)

```bash
cd ~/domains/<domain-anda>/public_html
cp .env.hostinger.example .env
nano .env                      # isi APP_URL, DB_DATABASE, DB_USERNAME, DB_PASSWORD
php artisan key:generate
bash deploy.sh                 # composer install, migrasi, storage link, cache
php artisan admin:buat admin3526 --name="Humas BPS"    # buat akun admin (username atau email)
```

Pasang kuesioner siap pakai (**Absensi Harian Peserta Magang**): `php artisan db:seed --class=ReadySurveySeeder --force`.
Opsional, contoh kuesioner dummy: `php artisan db:seed --class=DummySurveySeeder --force`.

Buka `https://<domain-anda>/masuk` dan masuk dengan akun admin tadi.

## 4. Cron untuk antrean (salinan ke Google Drive, dsb.)

*Advanced → Cron Jobs* → **Custom**, setiap menit (`* * * * *`):

```
/usr/bin/php /home/<u123456789>/domains/<domain-anda>/public_html/artisan schedule:run
```

## 5. Memperbarui setelah ada perubahan di GitHub

hPanel → *Advanced → Git* → **Deploy** (atau aktifkan *Auto Deployment* lewat webhook GitHub), lalu lewat SSH:

```bash
cd ~/domains/<domain-anda>/public_html && bash deploy.sh
```

## Catatan

- **Data tidak ikut GitHub.** Database, berkas unggahan, dan `.env` hanya ada di server. Kuesioner dari komputer lokal bisa dipindahkan lewat **Ekspor JSON** lalu **Impor JSON** di dashboard Hostinger.
- **Halaman admin wajib login** (`ADMIN_OPEN_ON_LAN=false`). Responden tetap mengisi tanpa login lewat tautan `https://<domain-anda>/f/<kode>`.
- **Lupa kata sandi admin**: jalankan lagi `php artisan admin:buat admin3526` (atau email akunnya) untuk mengganti kata sandinya.
- **Galat 500**: lihat `storage/logs/laravel.log`, dan pastikan folder `storage` dan `bootstrap/cache` bisa ditulis (`chmod -R 775 storage bootstrap/cache`).
