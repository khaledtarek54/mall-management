<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third-party integration toggles
    |--------------------------------------------------------------------------
    |
    | When false, the corresponding admin/portal action is hidden so the
    | demo doesn't show stub buttons that flash a notification but don't
    | actually integrate yet. Flip to true once you've wired credentials.
    |
    */

    /*
    | Demo payments — the "mark it paid without a gateway" shortcut.
    |
    | UNSET means "follow the environment": on in local/testing, off everywhere
    | else including staging. Set it explicitly only on a box where fabricating
    | a captured payment is the point. Production refuses it regardless — see
    | App\Support\DemoPayments, which owns the whole decision.
    */
    'demo_payments' => [
        'enabled' => env('DEMO_PAYMENTS_ENABLED'),
    ],

    'paymob' => [
        'enabled' => env('PAYMOB_ENABLED', false),

        // Sandbox is the default Paymob URL; flip to https://accept.paymob.com
        // for production. Live sandbox sits at the same accept.paymob.com host
        // — Paymob distinguishes by your account's environment, not by URL.
        'base_url' => env('PAYMOB_BASE_URL', 'https://accept.paymob.com'),

        // 4 credentials from the Paymob dashboard (see PAYMOB-SETUP.md):
        //   api_key         → Account → Profile → API Key
        //   integration_id  → Developers → Payment Integrations → your card integration
        //   iframe_id       → Developers → Iframes
        //   hmac_secret     → Account → Profile → HMAC
        'api_key' => env('PAYMOB_API_KEY'),
        'integration_id' => env('PAYMOB_INTEGRATION_ID'),
        'iframe_id' => env('PAYMOB_IFRAME_ID'),
        'hmac_secret' => env('PAYMOB_HMAC_SECRET'),

        /*
         * Session creation is serialised per invoice+channel, because opening a
         * Paymob order is check-then-act with a network call in the middle: two
         * simultaneous taps otherwise create two live orders against one debt.
         *
         *   session_lock_seconds       how long the lock is held. Must exceed a slow
         *                              round-trip to Paymob, since it is held across
         *                              that call — but not so long that a wedged
         *                              request blocks the invoice for minutes.
         *   session_lock_wait_seconds  how long the SECOND request waits. It is
         *                              waiting to reuse the first one's session, so
         *                              this should cover a normal round-trip.
         */
        'session_lock_seconds' => (int) env('PAYMOB_SESSION_LOCK_SECONDS', 30),
        'session_lock_wait_seconds' => (int) env('PAYMOB_SESSION_LOCK_WAIT_SECONDS', 10),

        // Currency must match the integration's account currency (EGP for
        // Egyptian Paymob accounts).
        'currency' => env('PAYMOB_CURRENCY', 'EGP'),

        // Apple Pay is a SEPARATE Paymob integration (its own integration_id)
        // and needs a verified domain (see docs/PAYMENT-LINK-APPLEPAY.md). Leave
        // empty to keep the Apple Pay button hidden. Card payments are unaffected.
        'apple_pay_integration_id' => env('PAYMOB_APPLE_PAY_INTEGRATION_ID'),
    ],

    // Push notifications to the tenant mobile app (Firebase Cloud Messaging).
    // FCM itself is free + unlimited; this only fans out the DB notifications we
    // already store to the tenant's registered device tokens. Disabled by
    // default → NullPushSender (no-op); the in-app inbox + email still deliver.
    // To light it up: create a free Firebase project, download the service-
    // account JSON (Project settings → Service accounts → Generate new key),
    // point FCM_CREDENTIALS at it, set PUSH_ENABLED=true.
    'push' => [
        'enabled' => env('PUSH_ENABLED', false),
        'fcm' => [
            'credentials' => env('FCM_CREDENTIALS'), // absolute path to the service-account JSON
            'project_id' => env('FCM_PROJECT_ID'),   // optional; falls back to project_id in the JSON
        ],
    ],

    // Deep link that opens the tenant mobile app (e.g. "atriom://invoices").
    // Surfaced on the public payment status page as an "Open the app" button
    // so a client can confirm a paid invoice in-app. Empty = button hidden.
    'app_deep_link' => env('APP_DEEP_LINK'),

    'whatsapp' => [
        'enabled' => env('WHATSAPP_ENABLED', false),
    ],

];
