<?php

use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The account is a username (e.g. admin3526) or an email address; either is what the admin types to sign in.
Artisan::command('admin:buat {akun} {--name=Admin}', function (string $akun) {
    $isEmail = str_contains($akun, '@');
    if (! $isEmail && preg_match('/^[A-Za-z0-9._-]{3,50}$/', $akun) !== 1) {
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
        : User::query()->updateOrCreate(['username' => $akun], ['name' => (string) $this->option('name'), 'email' => $akun.'@admin.ruang-kuesioner.invalid', 'password' => $password]);
    $this->info(($user->wasRecentlyCreated ? 'Admin dibuat: ' : 'Kata sandi admin diperbarui: ').$akun);

    return 0;
})->purpose('Buat akun admin (atau ganti kata sandinya) untuk masuk ke Ruang Kuesioner');

// On shared hosting there is no long-running worker: cron runs the scheduler every minute and it
// works through the queue (e.g. copies to Google Drive) until it is empty.
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')->everyMinute()->withoutOverlapping();
