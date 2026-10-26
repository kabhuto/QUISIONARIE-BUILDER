<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets API requests in with a token from the "Akses API" page, sent as "Authorization: Bearer <token>"
 * (or "X-Api-Key: <token>"). Writing needs a token that may write.
 */
class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next, string $ability = 'read'): Response
    {
        $token = ApiToken::findByKey((string) ($request->bearerToken() ?? $request->header('X-Api-Key', '')));

        if (! $token) {
            return response()->json(['message' => 'Token API tidak valid. Kirim header "Authorization: Bearer <token>" dari menu Akses API.'], 401);
        }

        if ($ability === 'write' && ! $token->can_write) {
            return response()->json(['message' => 'Token ini hanya boleh membaca data.'], 403);
        }

        if (! $token->last_used_at || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $request->attributes->set('api_token', $token);

        return $next($request);
    }
}
