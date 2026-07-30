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
    | OFF BY DEFAULT — operator's call, 2026-07-30. Enforcement is opt-IN via
    | SECURITY_FORCE_2FA_ROLES (comma-separated; empty or unset = nobody is forced).
    | It previously defaulted ON outside local/testing.
    |
    | Why it is off: switching it on marches every listed role through TOTP enrolment
    | at their next login. That is a rollout to schedule with the operator's staff, not
    | something to have happen to them on a deploy they didn't plan — and pre-go-live it
    | would block the very people doing the data validation. See OPEN-QUESTIONS C4.11.
    |
    | THE RISK THIS ACCEPTS, stated plainly: a production deploy where nobody sets the
    | env var runs with NO second factor on accounts that move money. That is the exact
    | failure this mechanism was built to fix — enforcement sat broken for months because
    | it was "configured" somewhere nobody checked. So the off state is NOT silent:
    | `php artisan atriom:health` FAILS on a production environment with no roles forced.
    | Turn it on by pasting the recommended list (SecurityDefaults::FORCE_2FA_ROLES) into
    | the env var:
    |
    |   SECURITY_FORCE_2FA_ROLES="super_admin,mall_admin,manager,accounting,leasing,operations,coordinator,hr,marketing"
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
        // Default '' — nobody is forced until the operator opts in. Unlike force_https
        // below, this one does NOT self-enable in production: see the note above, and
        // the health check that refuses to call such a deploy healthy.
        explode(',', (string) env('SECURITY_FORCE_2FA_ROLES', ''))
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
