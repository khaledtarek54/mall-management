<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Locale resolution for the mobile API. The web SetLocale reads from the
 * session; the API is stateless, so we resolve from the Accept-Language
 * header instead (the mobile client sends "ar" or "en"). Falls back to the
 * app default. Mirrors SetLocale::SUPPORTED so the two stay in lock-step.
 */
class SetApiLocale
{
    public const SUPPORTED = ['en', 'ar'];

    public function handle(Request $request, Closure $next): Response
    {
        // getPreferredLanguage picks the best match from the header against our
        // supported set, defaulting to the first entry (en) when nothing fits.
        $locale = $request->getPreferredLanguage(self::SUPPORTED) ?? config('app.locale');

        if (in_array($locale, self::SUPPORTED, true)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
