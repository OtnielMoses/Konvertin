<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mengalihkan akses HTTP ke HTTPS bila diwajibkan (NFR-SEC-1).
 */
class EnsureHttps
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (config('konvertin.force_https') && ! $request->secure()) {
            return redirect()->secure($request->getRequestUri(), 301);
        }

        $response = $next($request);

        if (config('konvertin.force_https')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
