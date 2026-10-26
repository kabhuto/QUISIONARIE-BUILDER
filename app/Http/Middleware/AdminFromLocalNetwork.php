<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin pages have no login yet, so they only answer requests from this computer or the
 * local network. Visitors coming through a public https tunnel (whose address is forwarded by
 * the trusted local proxy) can reach the respondent forms only.
 */
class AdminFromLocalNetwork
{
    public function handle(Request $request, Closure $next): Response
    {
        $ip = (string) $request->ip();
        $public = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;

        abort_if($public, 403, 'Halaman admin hanya dapat dibuka dari komputer server atau jaringan lokal.');

        return $next($request);
    }
}
