<?php

namespace App\Support;

/**
 * A web address the way people actually type one.
 *
 * Reported by the tester: the tenant's Website field accepted `https://zara.com` and refused
 * `www.zara.com` and `zara.com` — which is how a retailer writes their own address on a business
 * card, on a lease, and in the file an operator is copying from. Filament's `->url()` maps to
 * Laravel's `url` rule, which requires a scheme, so the common form was the one that failed.
 *
 * **Normalise, do not refuse.** Every browser, every CRM and every phone keyboard treats a bare
 * domain as an address and supplies `https://` itself; refusing it makes the operator do by hand
 * what the machine can do reliably. `https` rather than `http` because a scheme has to be chosen and
 * the secure one is the modern default — an address that genuinely only answers on http redirects
 * anyway.
 *
 * What is NOT accepted stays not accepted: a value with no dot in it, a bare word, or anything the
 * `url` rule refuses once a scheme is on the front. This widens the INPUT, never the meaning.
 */
class WebAddress
{
    /** Prefix a scheme when the operator did not type one. Blank stays blank. */
    public static function normalise(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return preg_match('~^[a-z][a-z0-9+.\-]*://~i', $value) === 1
            ? $value
            : 'https://'.$value;
    }

    /** Is this a web address once a missing scheme is supplied? */
    public static function isValid(?string $value): bool
    {
        $normalised = self::normalise($value);

        return $normalised !== null && filter_var($normalised, FILTER_VALIDATE_URL) !== false;
    }
}
