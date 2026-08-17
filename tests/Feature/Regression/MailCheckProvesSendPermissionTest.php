<?php

/**
 * Regression: `integrations:check --mail` reported a green "Outbound email" on a
 * box where no email could be sent at all.
 *
 * It probed `/api-quota`, which answers 200 for any valid token whatever its
 * scopes. MailerSend tokens are scoped, and this one had been issued without the
 * Email permission — so the check passed while every notification in the app died
 * with `403 Forbidden`. It surfaced only because a payment receipt failed to send
 * on a box the command had just called healthy.
 *
 * The same shape as the other gates that checked a weaker property than their
 * name claimed: "the key authenticates" is not "the key can send", and the
 * difference is the entire point of the check.
 */

/*
 * These assert against Artisan::output() rather than expectsOutputToContain(),
 * which cannot express what is being checked here: each expected substring is
 * registered as its own Mockery expectation on doWrite, and Mockery hands a call
 * to the FIRST matching one — so two substrings on the SAME line can never both
 * be satisfied, and the second fails however correct the output is. The whole
 * point of these cases is that one line carries both the verdict and what to do
 * about it.
 */

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('mail.default', 'mailersend');
    config()->set('mail.from.address', 'noreply@tri-tech.net');
    config()->set('mailersend-driver.api_key', 'mlsn.test-key');
});

/** A token that authenticates; $sendStatus is what the send probe gets back. */
function fakeMailerSend(int $sendStatus, array $sendBody = []): void
{
    Http::fake([
        '*/v1/api-quota' => Http::response([
            'quota' => 100, 'remaining' => 88, 'reset' => '2026-08-18T00:00:00Z',
        ]),
        '*/v1/email' => Http::response($sendBody, $sendStatus),
    ]);
}

it('fails when the token authenticates but may not send', function () {
    // The exact pair the real account returned: quota 200, send 403.
    fakeMailerSend(403, ['message' => 'This action is unauthorized.']);

    $exit = Artisan::call('integrations:check', ['--mail' => true]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())
        ->toContain('Key authenticated')          // the weaker property still holds…
        ->toContain('NOT permitted to send')      // …and is no longer mistaken for the check
        ->toContain('Email: Full access');        // and the operator is told what to change
});

it('passes when the token may send — validation refuses the empty probe, not authorization', function () {
    // 422 is the *expected* refusal: the payload is deliberately empty, so a
    // token that may send gets stopped by validation instead of by permission.
    fakeMailerSend(422, ['message' => 'The given data was invalid.']);

    $exit = Artisan::call('integrations:check', ['--mail' => true]);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->not->toContain('NOT permitted to send');
});

it('delivers no mail while probing', function () {
    fakeMailerSend(422);

    expect(Artisan::call('integrations:check', ['--mail' => true]))->toBe(0);

    // One quota read, one probe — and the probe carries no recipient, so there is
    // nothing MailerSend could deliver even if the token were fully privileged.
    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => ! str_contains($request->url(), '/v1/email')
        || ! str_contains((string) $request->body(), '@'));
});

it('does not claim either answer when the probe returns something it cannot read', function () {
    fakeMailerSend(500, ['message' => 'MailerSend is having a bad day.']);

    // Inconclusive is reported as inconclusive: no failure (the token may well be
    // fine), no silent pass either — the operator is told to confirm by sending.
    $exit = Artisan::call('integrations:check', ['--mail' => true]);

    expect($exit)->toBe(0)
        ->and(Artisan::output())
        ->toContain('inconclusive')
        ->toContain('mail:test');
});

it('still fails ahead of the probe when the key itself is rejected', function () {
    Http::fake(['*/v1/api-quota' => Http::response(['message' => 'Unauthenticated.'], 401)]);

    $exit = Artisan::call('integrations:check', ['--mail' => true]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('API key rejected');

    // The send probe is pointless once the key is refused — and firing it anyway
    // would spend a second request from a 100/day allowance.
    Http::assertSentCount(1);
});
