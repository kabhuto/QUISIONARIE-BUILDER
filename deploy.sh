#!/usr/bin/env bash
# Run on the server after every pull: dependencies, database changes, caches. cPanel (Jagoan Hosting)
# runs it by itself on "Deploy HEAD Commit" (.cpanel.yml); on Hostinger run it over SSH.
# First installation: see DEPLOY-JAGOANHOSTING.md or DEPLOY-HOSTINGER.md.
set -euo pipefail
cd "$(dirname "$0")"

if [ ! -f .env ]; then
    echo "Belum ada .env: salin .env.hostinger.example menjadi .env dan isi datanya dulu." >&2
    exit 1
fi

# The app needs PHP 8.3 or newer. On cPanel/CloudLinux the "php" command can be an older version than the
# one picked for the website, so fall back to an alt-php binary; PHP=/path/to/php overrides.
PHP="${PHP:-php}"
if ! "$PHP" -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);' 2>/dev/null; then
    for candidate in /opt/alt/php84/usr/bin/php /opt/alt/php83/usr/bin/php /opt/cpanel/ea-php84/root/usr/bin/php /opt/cpanel/ea-php83/root/usr/bin/php; do
        [ -x "$candidate" ] && PHP="$candidate" && break
    done
fi
"$PHP" -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);' || { echo "Butuh PHP 8.3 atau lebih baru (sekarang: $("$PHP" -r 'echo PHP_VERSION;'))." >&2; exit 1; }
# Composer's "#!/usr/bin/env php" then picks the same PHP.
export PATH="$(dirname "$("$PHP" -r 'echo PHP_BINARY;')"):$PATH"

COMPOSER="$(command -v composer || true)"
if [ -z "$COMPOSER" ]; then
    COMPOSER="$HOME/composer.phar"
    [ -f "$COMPOSER" ] || "$PHP" -r "copy('https://getcomposer.org/download/latest-stable/composer.phar', '$COMPOSER');"
fi
case "$COMPOSER" in
    *.phar) "$PHP" "$COMPOSER" install --no-dev --optimize-autoloader --no-interaction ;;
    *) "$COMPOSER" install --no-dev --optimize-autoloader --no-interaction ;;
esac

grep -q '^APP_KEY=base64:' .env || "$PHP" artisan key:generate --force
"$PHP" artisan migrate --force
# Uploaded files are still served without the link (routes/web.php) if the hosting forbids symlinks.
[ -e public/storage ] || "$PHP" artisan storage:link || echo "storage:link gagal; berkas tetap disajikan lewat aplikasi."
"$PHP" artisan optimize:clear
"$PHP" artisan optimize

echo "Selesai ($("$PHP" -r 'echo PHP_VERSION;')). Buka $(grep '^APP_URL=' .env | cut -d= -f2-)"
