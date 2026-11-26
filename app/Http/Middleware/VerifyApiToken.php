<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Simple shared-secret protection for the RPC endpoints.
 *
 * If WINZ_API_TOKEN is empty, the middleware allows the request (useful for
 * local development). Production .env must always set a real token.
 */
class VerifyApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('app.api_token', '');

        if ($expected !== '') {
            $provided = (string) $request->header('X-API-Key', '');
            if (! hash_equals($expected, $provided)) {
                return response()->json([
                    'ok' => false,
                    'error' => 'UNAUTHORIZED',
                ], 401);
            }
        }

        return $next($request);
    }
}
