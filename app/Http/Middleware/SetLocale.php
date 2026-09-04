<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public const SUPPORTED = ['en', 'ar'];

    /**
     * The SESSION guards a person can be signed in on — one per Filament panel.
     *
     * Written out here rather than resolved from `Filament::getPanels()`, because this middleware
     * runs on every web request and booting the panels to read three strings is work the request
     * does not need. `AContractorsChosenLanguageOutlivesTheSessionTest` derives the same set FROM
     * the panels and fails when they disagree, so the list cannot silently fall behind a new one.
     *
     * It was `['web', 'portal']` — here and, separately, in the `/locale/{locale}` switcher — for
     * the whole life of the vendor panel (2026-08-28 → 2026-09-04). The contractor portal therefore
     * had no durable language at all: the switcher renders in it (the hook is registered panel-wide
     * in `AppServiceProvider`) and wrote only the session, so a contractor who chose Arabic was back
     * in English at the next sign-in and no scheduled notification could ever know.
     *
     * `tenant-api` is deliberately absent: it is a token guard with no session, and the mobile API
     * resolves `Accept-Language` in `SetApiLocale` because there the caller IS the recipient.
     */
    public const GUARDS = ['web', 'portal', 'vendor'];

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
     * The signed-in reader's stored language, from whichever panel they are in. Checked across every
     * guard in {@see self::GUARDS} because two of the three panels do not use the default one, and a
     * retailer and a contractor are the readers least likely to want English.
     */
    private function preferenceOf(Request $request): ?string
    {
        foreach (self::GUARDS as $guard) {
            $locale = $request->user($guard)?->getAttribute('locale');

            if (filled($locale)) {
                return $locale;
            }
        }

        return null;
    }
}
