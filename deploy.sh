#!/usr/bin/env bash
# Run on the server (Hostinger SSH) after every pull: dependencies, database changes, caches.
# First installation: see DEPLOY-HOSTINGER.md (copy .env, generate the key, make an admin).
set -euo pipefail
cd "$(dirname "$0")"

composer install --no-dev --optimize-autoloader --no-interaction
php artisan migrate --force
# Uploaded files are still served without the link (routes/web.php) if the hosting forbids symlinks.
[ -e public/storage ] || php artisan storage:link || echo "storage:link gagal; berkas tetap disajikan lewat aplikasi."
php artisan optimize:clear
php artisan optimize

echo "Selesai. Buka $(grep '^APP_URL=' .env | cut -d= -f2-)"
