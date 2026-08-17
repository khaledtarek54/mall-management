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
            $response->headers->set('Content-Security-Policy', $this->payPagePolicy());
        }

        return $response;
    }

    /**
     * The pay page is a form whose whole purpose is to hand off to the gateway,
     * so `form-action` must name the gateway ORIGIN and not just `'self'`.
     *
     * `form-action` is checked against every hop of a form submission's
     * navigation, redirects included — not merely the POST target. `'self'`
     * alone therefore let the POST through and then silently refused the 302 to
     * Paymob: the button did nothing, the server logged a perfectly healthy
     * `paymob.session_started`, and the only trace was a console violation
     * nobody was looking at. The suite could not see it either — Laravel's test
     * client does not enforce CSP, so `assertRedirect()` to the gateway passed
     * throughout.
     *
     * The origin is DERIVED from the configured base URL rather than written
     * out, so switching PAYMOB_BASE_URL (sandbox ⇄ production, or a regional
     * host) cannot leave the policy pointing at the wrong gateway. Apple Pay
     * rides the same host, so it needs no entry of its own.
     */
    protected function payPagePolicy(): string
    {
        $formAction = array_filter([
            "'self'",
            self::originOf((string) config('integrations.paymob.base_url')),
        ]);

        return "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; "
            .'form-action '.implode(' ', $formAction).'; '
            ."frame-ancestors 'none'; base-uri 'none'";
    }

    /**
     * scheme://host[:port] of a URL — a CSP source expression names an origin,
     * never a path. Null when the value is not an absolute URL, which keeps a
     * blank or malformed setting from emitting a policy the browser discards
     * wholesale (a syntax error in ONE directive is survivable, but a stray
     * path would silently narrow what the form may reach).
     */
    protected static function originOf(string $url): ?string
    {
        $parts = parse_url($url);

        if (empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
