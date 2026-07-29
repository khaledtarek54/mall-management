<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Force two-factor setup for these roles
    |--------------------------------------------------------------------------
    |
    | Admin users holding any of these roles are forced through TOTP two-factor
    | setup before they can use the panel. Defaults to super_admin only (so the
    | demo/test logins keep working); production should add the write-capable
    | roles via SECURITY_FORCE_2FA_ROLES, e.g.:
    |
    |   SECURITY_FORCE_2FA_ROLES="super_admin,manager,accounting,leasing,operations,hr"
    |
    */

    'force_2fa_roles' => array_filter(array_map(
        'trim',
        explode(',', (string) env('SECURITY_FORCE_2FA_ROLES', 'super_admin'))
    )),

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
