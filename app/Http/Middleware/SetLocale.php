<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public const SUPPORTED = ['en', 'ar'];

    public function handle(Request $request, Closure $next): Response
    {
        // Session first, stored preference second.
        //
        // The session is THIS browser's current choice and must win — it is what a click on the
        // switcher just wrote, and what a shared machine needs so one person's language does not
        // follow the next one in. The stored preference is the durable default underneath: it is
        // what a fresh session starts in, and — far more importantly — it is the only answer
        // available to a scheduled command or a queue worker, which have no session at all and
        // would otherwise render every alert in the app default for every reader.
        $locale = $request->session()->get('locale')
            ?? $this->preferenceOf($request)
            ?? config('app.locale');

        if (in_array($locale, self::SUPPORTED, true)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }

    /**
     * The signed-in reader's stored language, from whichever panel they are in. Checked across both
     * guards because the portal does not use the default one, and a tenant is the reader least
     * likely to want English.
     */
    private function preferenceOf(Request $request): ?string
    {
        foreach (['web', 'portal'] as $guard) {
            $locale = $request->user($guard)?->getAttribute('locale');

            if (filled($locale)) {
                return $locale;
            }
        }

        return null;
    }
}
