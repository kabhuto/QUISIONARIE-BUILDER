<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin pages need a signed-in admin. On a development PC (ADMIN_OPEN_ON_LAN=true) requests from
 * this computer or the local network are let in without signing in; visitors coming through a
 * public https tunnel (whose address the trusted local proxy forwards) still have to sign in.
 */
class AdminAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() !== null || (config('app.admin_open_on_lan') && self::isLocal($request))) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(401, 'Masuk sebagai admin terlebih dahulu.');
        }

        return redirect()->guest(route('login'));
    }

    public static function isLocal(Request $request): bool
    {
        return filter_var((string) $request->ip(), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
