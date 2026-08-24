<?php

use App\Services\Paymob\PaymobClient;
use App\Support\Health;

/**
 * The Paymob HMAC secret can be rotated without refusing a payment.
 *
 * Paymob signs each callback with whatever secret their dashboard holds AT THAT INSTANT. Change it
 * there and every callback already in flight — plus every retry of one they have not had a 200 for
 * — is still signed with the OLD secret. With a single accepted secret those are refused, and a
 * refused callback is a payment the tenant made that the books never see. That is why the roadmap
 * row said a dual-secret verifier was only worth building alongside a rotation procedure someone
 * would actually run: `docs/integrations/PAYMOB-SETUP.md` §7.
 *
 * Two properties, and the second is the one that matters most:
 *
 *  - During the window, BOTH secrets verify.
 *  - Outside it, only the current one does — and with no secret at all, nothing does. A verifier
 *    that failed OPEN would accept any caller who knew the URL, which is the whole attack this
 *    signature exists to prevent.
 *
 * Every "accepts" case is paired with a "refuses" one on the same payload, because a verifier that
 * returned true unconditionally would satisfy the acceptance tests alone.
 */
function signedCallback(string $secret): array
{
    $callback = [
        'obj' => [
            'amount_cents' => '150000',
            'created_at' => '2026-08-24T10:00:00',
            'currency' => 'EGP',
            'error_occured' => false,
            'has_parent_transaction' => false,
            'id' => '987654',
            'integration_id' => '4242',
            'is_3d_secure' => true,
            'is_auth' => false,
            'is_capture' => false,
            'is_refunded' => false,
            'is_standalone_payment' => true,
            'is_voided' => false,
            'order' => ['id' => '112233'],
            'owner' => '5150',
            'pending' => false,
            'source_data' => ['pan' => '2346', 'sub_type' => 'MasterCard', 'type' => 'card'],
            'success' => true,
        ],
    ];

    $fields = [
        '150000', '2026-08-24T10:00:00', 'EGP', 'false', 'false', '987654', '4242', 'true',
        'false', 'false', 'false', 'true', 'false', '112233', '5150', 'false',
        '2346', 'MasterCard', 'card', 'true',
    ];

    return [$callback, hash_hmac('sha512', implode('', $fields), $secret)];
}

it('accepts the current secret and refuses anything else', function () {
    config(['integrations.paymob.hmac_secret' => 'CURRENT-SECRET']);
    config(['integrations.paymob.hmac_secret_previous' => null]);
    config(['integrations.paymob.hmac_previous_until' => null]);

    [$callback, $signature] = signedCallback('CURRENT-SECRET');
    [, $wrongSignature] = signedCallback('SOMEONE-ELSES-SECRET');

    $client = app(PaymobClient::class);

    expect($client->verifyHmac($callback, $signature))->toBeTrue();
    expect($client->verifyHmac($callback, $wrongSignature))->toBeFalse();
});

it('accepts BOTH secrets while the rotation window is open', function () {
    config(['integrations.paymob.hmac_secret' => 'NEW-SECRET']);
    config(['integrations.paymob.hmac_secret_previous' => 'OLD-SECRET']);
    config(['integrations.paymob.hmac_previous_until' => now()->addHours(6)->toIso8601String()]);

    $client = app(PaymobClient::class);

    [$callback, $newSignature] = signedCallback('NEW-SECRET');
    [, $oldSignature] = signedCallback('OLD-SECRET');
    [, $strangerSignature] = signedCallback('NEITHER');

    expect($client->verifyHmac($callback, $newSignature))->toBeTrue();
    expect($client->verifyHmac($callback, $oldSignature))->toBeTrue();

    // Widening to two is not widening to any — the control that makes the two above mean something.
    expect($client->verifyHmac($callback, $strangerSignature))->toBeFalse();
});

it('stops accepting the old secret the moment the window closes', function () {
    config(['integrations.paymob.hmac_secret' => 'NEW-SECRET']);
    config(['integrations.paymob.hmac_secret_previous' => 'OLD-SECRET']);
    config(['integrations.paymob.hmac_previous_until' => now()->subMinute()->toIso8601String()]);

    $client = app(PaymobClient::class);

    [$callback, $newSignature] = signedCallback('NEW-SECRET');
    [, $oldSignature] = signedCallback('OLD-SECRET');

    expect($client->verifyHmac($callback, $newSignature))->toBeTrue();
    expect($client->verifyHmac($callback, $oldSignature))->toBeFalse();
});

it('treats an unparseable window as CLOSED, never as open-ended', function () {
    // A typo in a date must NARROW what is accepted. The opposite reading — "we could not tell,
    // so allow it" — turns a fat-fingered env var into a permanently valid second secret.
    config(['integrations.paymob.hmac_secret' => 'NEW-SECRET']);
    config(['integrations.paymob.hmac_secret_previous' => 'OLD-SECRET']);
    config(['integrations.paymob.hmac_previous_until' => 'next toosday']);

    [$callback, $oldSignature] = signedCallback('OLD-SECRET');

    expect(PaymobClient::previousHmacWindowIsOpen())->toBeFalse();
    expect(app(PaymobClient::class)->verifyHmac($callback, $oldSignature))->toBeFalse();
});

it('fails CLOSED when no secret is configured at all', function () {
    config(['integrations.paymob.hmac_secret' => null]);
    config(['integrations.paymob.hmac_secret_previous' => null]);
    config(['integrations.paymob.hmac_previous_until' => null]);

    [$callback, $signature] = signedCallback('ANY-SECRET');

    expect(PaymobClient::acceptedHmacSecrets())->toBe([]);
    expect(app(PaymobClient::class)->verifyHmac($callback, $signature))->toBeFalse();
});

it('reports an unclosed rotation window as unhealthy in production', function () {
    config(['integrations.paymob.hmac_secret' => 'NEW-SECRET']);
    config(['integrations.paymob.hmac_secret_previous' => 'OLD-SECRET']);
    config(['integrations.paymob.hmac_previous_until' => now()->subDay()->toIso8601String()]);

    // The CONTROL first: while the window is open this must stay green, or a check that fires
    // during the very procedure it supports gets ignored.
    config(['integrations.paymob.hmac_previous_until' => now()->addHour()->toIso8601String()]);
    inEnvironment('production');
    expect(Health::run()['checks']['paymob_hmac_rotation']['ok'])->toBeTrue();

    // …and once it has closed, the credential has outlived its purpose and is still in `.env`.
    config(['integrations.paymob.hmac_previous_until' => now()->subDay()->toIso8601String()]);
    $check = Health::run()['checks']['paymob_hmac_rotation'];

    expect($check['ok'])->toBeFalse();
    expect($check['detail'])->toContain('PAYMOB_HMAC_SECRET_PREVIOUS');
});

it('says nothing when no rotation is in progress', function () {
    config(['integrations.paymob.hmac_secret' => 'CURRENT']);
    config(['integrations.paymob.hmac_secret_previous' => null]);
    inEnvironment('production');

    expect(Health::run()['checks']['paymob_hmac_rotation']['ok'])->toBeTrue();
});
