<?php

use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The account is a username (e.g. admin3526) or an email address; either is what the admin types to sign in.
Artisan::command('admin:buat {akun} {--name=Admin}', function (string $akun) {
    $isEmail = str_contains($akun, '@');
    if (! $isEmail && preg_match(User::USERNAME_PATTERN, $akun) !== 1) {
        $this->error('Username 3–50 karakter: huruf, angka, titik, garis bawah, atau tanda hubung.');

        return 1;
    }

    $password = (string) $this->secret('Kata sandi (minimal 8 karakter)');
    if (mb_strlen($password) < 8 || $password !== (string) $this->secret('Ulangi kata sandi')) {
        $this->error('Kata sandi kurang dari 8 karakter atau tidak sama.');

        return 1;
    }

    // Users need an email; a username-only admin gets a placeholder that is never mailed.
    $user = $isEmail
        ? User::query()->updateOrCreate(['email' => $akun], ['name' => (string) $this->option('name'), 'password' => $password])
        : User::query()->updateOrCreate(['username' => $akun], ['name' => (string) $this->option('name'), 'email' => User::placeholderEmail($akun), 'password' => $password]);
    $this->info(($user->wasRecentlyCreated ? 'Admin dibuat: ' : 'Kata sandi admin diperbarui: ').$akun);

    return 0;
})->purpose('Buat akun admin (atau ganti kata sandinya) untuk masuk ke Ruang Kuesioner');

// Run by deploy.sh: the .env account is made, and its password follows ADMIN_PASSWORD while that is set.
Artisan::command('admin:sinkron', function () {
    $username = trim((string) config('app.admin_username'));
    $password = (string) config('app.admin_password');
    if ($username === '' || $password === '') {
        $this->line('ADMIN_USERNAME/ADMIN_PASSWORD kosong di .env: akun admin tidak diubah.');

        return 0;
    }
    // ADMIN_USERNAME may also be an email address (e.g. test@example.com). "<kata-sandi-admin>" is the
    // placeholder from .env.hostinger.example, never a real password.
    $isEmail = filter_var($username, FILTER_VALIDATE_EMAIL) !== false;
    if ((! $isEmail && preg_match(User::USERNAME_PATTERN, $username) !== 1) || mb_strlen($password) < 8 || str_starts_with($password, '<')) {
        $this->error('ADMIN_USERNAME harus email atau 3–50 huruf/angka/._-, dan ADMIN_PASSWORD minimal 8 karakter.');

        return 1;
    }

    $user = User::query()->firstOrNew([$isEmail ? 'email' : 'username' => $username]);
    if ($user->exists && Hash::check($password, $user->password)) {
        $this->info('Akun admin sudah ada: '.$username);

        return 0;
    }
    $user->fill(['name' => $user->name ?: 'Admin', 'email' => $user->email ?: User::placeholderEmail($username), 'password' => $password])->save();
    $this->info(($user->wasRecentlyCreated ? 'Admin dibuat: ' : 'Kata sandi admin diperbarui: ').$username);

    return 0;
})->purpose('Buat akun admin dari ADMIN_USERNAME dan ADMIN_PASSWORD di .env');

// On shared hosting there is no long-running worker: cron runs the scheduler every minute and it
// works through the queue (e.g. copies to Google Drive) until it is empty.
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')->everyMinute()->withoutOverlapping();
