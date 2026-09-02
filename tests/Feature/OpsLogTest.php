<?php

use App\Support\OpsLog;
use Illuminate\Support\Facades\Log;

it('routes operational events to the ops channel and redacts secrets/PII', function () {
    Log::shouldReceive('channel')->with('ops')->andReturnSelf();
    Log::shouldReceive('error')->once()->withArgs(function (string $event, array $context) {
        expect($event)->toBe('eta.submission_failed');
        expect($context['invoice_id'])->toBe(7);            // kept
        expect($context['note'])->toBe('fine');             // kept
        expect($context['secret'])->toBe('[redacted]');     // redacted
        expect($context['api_key'])->toBe('[redacted]');    // redacted
        expect($context['meta']['hmac'])->toBe('[redacted]'); // nested redaction
        expect($context['meta']['ok'])->toBe('visible');     // nested kept

        return true;
    });

    OpsLog::error('eta.submission_failed', [
        'invoice_id' => 7,
        'note' => 'fine',
        'secret' => 'abc',
        'api_key' => 'live-key',
        'meta' => ['hmac' => 'xyz', 'ok' => 'visible'],
    ]);
});

it('redacts every bearer-credential key, not just the ones spelled `token`', function () {
    // REDACT matches keys EXACTLY — `in_array(strtolower($key), …)`, not a substring search. So
    // `token` being on the list never covered `payment_token`, and a Paymob payment_token
    // AUTHORISES A CHARGE. Found by writing a comment claiming these were already covered and
    // then checking: they were not.
    Log::shouldReceive('channel')->with('ops')->andReturnSelf();
    // Variadic: a `withArgs` closure with FIXED parameters THROWS `ArgumentCountError` rather than
    // not-matching, so any one-argument `Log::warning()` elsewhere in the call — `SealedPeriod`'s
    // fail-open catch is one — turns an unrelated line into a hard failure of this test. Shape-check
    // first, then assert.
    Log::shouldReceive('warning')->once()->withArgs(function (...$args) {
        [$event, $context] = [$args[0] ?? null, $args[1] ?? null];

        if (! is_string($event) || ! is_array($context)) {
            return false;
        }

        foreach (['payment_token', 'payment_key', 'access_token', 'refresh_token', 'bearer'] as $key) {
            expect($context[$key])->toBe('[redacted]', "[{$key}] must be redacted");
        }

        // Still keyed on the exact name — a key that merely CONTAINS a secret word is not
        // assumed dangerous, so operationally useful fields keep their values.
        expect($context['token_type'])->toBe('bearer-ish-but-not-a-secret')
            ->and($context['order_id'])->toBe(4242);

        return true;
    });

    OpsLog::warning('paymob.session_started', [
        'payment_token' => 'pk_live_SECRET',
        'payment_key' => 'pmk_SECRET',
        'access_token' => 'at_SECRET',
        'refresh_token' => 'rt_SECRET',
        'bearer' => 'br_SECRET',
        'token_type' => 'bearer-ish-but-not-a-secret',
        'order_id' => 4242,
    ]);
});
