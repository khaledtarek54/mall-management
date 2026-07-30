<?php

use App\Support\SecurityDefaults;

return [

    /*
    |--------------------------------------------------------------------------
    | Force two-factor setup for these roles
    |--------------------------------------------------------------------------
    |
    | Admin users holding any of these roles are redirected to TOTP setup before
    | they can use the panel. It forces SETUP, not a lockout — the user completes
    | it themselves — but they cannot reach an invoice, a payment or a lease until
    | a second factor exists.
    |
    | Defaults to the write-capable operator roles OUTSIDE local/testing, so a
    | production deploy is protected without anyone remembering an env var, and to
    | NOBODY locally so the demo logins and the Playwright suite keep working. That
    | mirrors force_https below. SECURITY_FORCE_2FA_ROLES overrides either way
    | (comma-separated; an empty string disables it entirely).
    |
    | Deliberately excluded from the default: `viewer`, `owner`, `technician`,
    | `customer_service`, `vendor`. They hold few or no write permissions — but they
    | DO read tenant data and, for viewer and owner, the whole AR book, so a leaked
    | password there is still a disclosure. Left out because forcing an authenticator
    | app on an external owner or an occasional technician is an operator's call to
    | make, not a default to impose. Add them to the env var to close that gap:
    |
    |   SECURITY_FORCE_2FA_ROLES="super_admin,mall_admin,manager,accounting,leasing,operations,coordinator,hr,marketing,viewer,owner"
    |
    | NOTE: this list is only consulted by App\Http\Middleware\ForceTwoFactorForRoles.
    | It must not be read from a Filament panel-builder argument — those are evaluated
    | once at boot, when nobody is authenticated, which is exactly how enforcement came
    | to be silently off for every role (see that middleware's docblock).
    |
    */

    'force_2fa_roles' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'SECURITY_FORCE_2FA_ROLES',
            in_array(env('APP_ENV', 'production'), ['local', 'testing'], true)
                ? ''
                : implode(',', SecurityDefaults::FORCE_2FA_ROLES),
        ))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Generate https:// URLs
    |--------------------------------------------------------------------------
    |
    | TLS terminates at the proxy, so PHP sees a plain http request and Laravel
    | builds every absolute URL with http://. That is not cosmetic — these are
    | the URLs that leave the building:
    |
    |   - the tenant payment link (Invoice::paymentLinkUrl)
    |   - password-reset links for /admin, /portal and the mobile API
    |   - the Paymob return URL
    |   - every "Open Atriom" button in an emailed alert
    |
    | An http:// payment link is an invoice total travelling in clear text, and
    | browsers increasingly refuse or warn on the downgrade. HSTS is already sent
    | (SecurityHeaders), but HSTS only protects a browser that has ALREADY made
    | one successful https visit — it does nothing for the first click on a link
    | in an email.
    |
    | Defaults to on outside local/testing so a production deploy is secure
    | without remembering an env var, and off locally so Herd's plain http and
    | the test suite keep working. FORCE_HTTPS overrides either way.
    |
    */

    'force_https' => (bool) env('FORCE_HTTPS', ! in_array(env('APP_ENV', 'production'), ['local', 'testing'], true)),

];
