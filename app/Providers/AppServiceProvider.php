<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // On a hosting with an https address, links and assets are always https, even when a CDN
        // or proxy in front of the server talks plain http to it (browsers block mixed content).
        // The root is pinned to APP_URL too, so links never carry the "/public" folder that a
        // shared hosting rewrites to.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceRootUrl((string) config('app.url'));
            URL::forceScheme('https');
        }
    }
}
