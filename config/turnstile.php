<?php

return [

    /*
     * Cloudflare Turnstile on the ADMIN sign-in form.
     *
     * OFF unless BOTH keys are set. That is the whole safety story for this feature: a
     * workstation, the test suite and any box whose operator has not configured it are
     * untouched, so adding a challenge to the one form every automated test submits cannot
     * break them. It also doubles as the emergency escape hatch — clear the keys, re-cache
     * the config, and the panel is loginable again without a deploy.
     */
    'site_key' => env('TURNSTILE_SITE_KEY'),
    'secret_key' => env('TURNSTILE_SECRET_KEY'),

    /*
     * Cloudflare's verification endpoint. A constant in practice; configurable so a test
     * can point it somewhere that is not the internet.
     */
    'verify_url' => env(
        'TURNSTILE_VERIFY_URL',
        'https://challenges.cloudflare.com/turnstile/v0/siteverify'
    ),

    'timeout' => (int) env('TURNSTILE_TIMEOUT', 5),

];
