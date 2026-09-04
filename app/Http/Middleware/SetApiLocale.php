<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Locale resolution for the mobile API. The web SetLocale reads from the
 * session; the API is stateless, so we resolve from the Accept-Language
 * header instead (the mobile client sends "ar" or "en"). Falls back to the
 * app default.
 *
 * The languages themselves are {@see SetLocale::SUPPORTED} — the ONE list. This class carried a
 * `SUPPORTED` const of its own under a docblock promising the two would "stay in lock-step", which
 * is a promise nothing kept and nothing could check. Measured 2026-09-04: five files under `app/`
 * stated the pair beside the one list — this const, both branches of
 * `PaymentLinkController::locale()`, `ChargeCode::flushLookupCaches()`, `Health::checkTranslations()`
 * and an `IsCodeCatalogue` read of a config key that does not exist. All six agreed, so nothing was
 * wrong; what was wrong is that a THIRD language would have reached `ValueSets`, `DocumentLocale`,
 * `NotificationLocale` and the web switcher and stopped HERE — silently, because `__()` falls
 * through an unknown locale into the fallback, so the tenant's column looks set and every document
 * arrives in English.
 */
class SetApiLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        // getPreferredLanguage picks the best match from the header against our
        // supported set, defaulting to the first entry (en) when nothing fits.
        $locale = $request->getPreferredLanguage(SetLocale::SUPPORTED) ?? config('app.locale');

        if (in_array($locale, SetLocale::SUPPORTED, true)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
