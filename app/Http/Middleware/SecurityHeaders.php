<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers on every response, plus a tight Content-Security-Policy
 * scoped to the PUBLIC payment pages (/pay/*) — the no-login, real-money surface.
 * Filament panels need a looser policy (inline Livewire scripts), so the strict CSP
 * is deliberately not applied app-wide.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');

        // HSTS only over real HTTPS in production — never pin it on a dev host.
        if ($request->secure() && app()->environment('production')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // The public pay pages have no scripts and only inline styles — lock them down.
        if ($request->is('pay') || $request->is('pay/*')) {
            $response->headers->set(
                'Content-Security-Policy',
                "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; "
                ."form-action 'self'; frame-ancestors 'none'; base-uri 'none'"
            );
        }

        return $response;
    }
}
