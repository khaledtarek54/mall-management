<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS)
    |--------------------------------------------------------------------------
    |
    | This file did not exist until 2026-08-19, and its absence was not neutral.
    | `HandleCors` is in Laravel's global middleware stack, and with no `cors`
    | config to read it falls back to the framework's own defaults — which are
    | `allowed_origins: ['*']` over `api/*` and `sanctum/csrf-cookie`. Verified,
    | not assumed: `config('cors.allowed_origins')` returned `['*']` on this
    | codebase before this file landed.
    |
    | WHAT THAT DID AND DID NOT EXPOSE, stated plainly, because the honest scope
    | is what makes the fix reviewable:
    |
    |   - It did NOT hand anyone tenant data. `/api/v1` authenticates with a
    |     Sanctum BEARER token, and `supports_credentials` is false, so a browser
    |     on evil.example never attaches a token or a cookie it does not already
    |     hold. A cross-origin read of an authenticated endpoint gets a 401.
    |   - It DID mean any origin on the internet could invoke the public surface
    |     and read the response — `/api/v1/public/*`, the unauthenticated shopper
    |     feed (module 36) — and could reach `sanctum/csrf-cookie`.
    |   - And it meant the policy was an accident rather than a decision. Nobody
    |     chose `*`; it was what happened when the file was deleted.
    |
    | So the fix is not a panic. It is: say what we allow, and let a deploy that
    | needs more say so out loud. `atriom:health` fails a DEPLOYED environment
    | whose allow-list is `*`, the same way it refuses a production box with no
    | 2FA roles forced — an unset security decision must not be a silent one.
    |
    | THE NATIVE APP IS UNAFFECTED. CORS is a browser mechanism: it is enforced
    | by the browser against a page's origin. A native Android/iOS client sends
    | no `Origin` header, so nothing here can break the mobile app. If tightening
    | this ever appears to break it, the diagnosis is wrong.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    /*
    | Comma-separated in CORS_ALLOWED_ORIGINS. Empty (the default) means "this
    | application's own origin only", derived from APP_URL — the answer that is
    | correct for the panel, the portal and the native app, which is every
    | consumer that exists today.
    |
    | A web front-end for the shopper feed is the case that will need more. Add
    | ITS origin; do not reintroduce `*`. A wildcard cannot be narrowed later
    | without someone noticing what broke, which is the whole reason the previous
    | state persisted.
    */
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', (string) env('APP_URL', ''))),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    /*
    | `X-Request-Id` is echoed back on every API response for support tracing;
    | a browser client cannot read it unless it is exposed here.
    */
    'exposed_headers' => ['X-Request-Id'],

    'max_age' => 3600,

    /*
    | FALSE, and it should stay false. The API is Bearer-token authenticated, so
    | it has no need of cross-origin cookies — and turning this on while
    | `allowed_origins` is broad is the combination that actually leaks a
    | session. Credentials and a wide allow-list are only dangerous together.
    */
    'supports_credentials' => false,

];
