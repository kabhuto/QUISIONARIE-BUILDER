<?php

use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('admin:buat {email} {--name=Admin}', function (string $email) {
    $password = (string) $this->secret('Kata sandi (minimal 8 karakter)');
    if (mb_strlen($password) < 8 || $password !== (string) $this->secret('Ulangi kata sandi')) {
        $this->error('Kata sandi kurang dari 8 karakter atau tidak sama.');

        return 1;
    }

    $user = User::query()->updateOrCreate(['email' => $email], ['name' => (string) $this->option('name'), 'password' => $password]);
    $this->info(($user->wasRecentlyCreated ? 'Admin dibuat: ' : 'Kata sandi admin diperbarui: ').$email);

    return 0;
})->purpose('Buat akun admin (atau ganti kata sandinya) untuk masuk ke Ruang Kuesioner');

// On shared hosting there is no long-running worker: cron runs the scheduler every minute and it
// works through the queue (e.g. copies to Google Drive) until it is empty.
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')->everyMinute()->withoutOverlapping();
