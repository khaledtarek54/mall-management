<?php

use App\Support\OpsLog;
use Sentry\Event;
use Sentry\Severity;

/**
 * Regression — error tracking is wired, inert without a DSN, and never ships secrets.
 *
 * CONTEXT. Atriom shipped with NO exception capture at all: an unhandled 500, an exhausted
 * queue job, or a Paymob outage surfaced only as a customer complaint. OpsLog covers the
 * money paths it was explicitly told about; Sentry covers the failures nobody anticipated.
 *
 * Two properties matter enough to pin:
 *
 *  1. **Inert without a DSN.** The SDK must cost nothing and change nothing until an operator
 *     sets SENTRY_LARAVEL_DSN — otherwise adding it would be a liability in dev and CI. (The
 *     transport itself returns `skipped` with no DSN; this asserts we didn't configure one by
 *     accident, e.g. by copying a real DSN into a committed file.)
 *
 *  2. **before_send scrubs.** `send_default_pii => false` withholds request/cookie/user data,
 *     but `extra` a developer attaches deliberately is still transmitted. This system holds
 *     Egyptian tax IDs, lease terms and payment data — a leak here is a disclosure to a third
 *     party, not just a log-hygiene problem. The scrubber reuses OpsLog's single redaction
 *     list; these tests are what stop the two drifting apart.
 */
it('ships with no DSN — the SDK is inert until an operator sets one', function () {
    expect(config('sentry.dsn'))->toBeEmpty(
        'A DSN must never be committed: it would send dev/CI errors to a real project.'
    );
});

it('never sends PII by default', function () {
    // Turning this on would attach request bodies, cookies, session and user IPs to every
    // event — i.e. tenant tax IDs and payment payloads, shipped to a third party.
    expect(config('sentry.send_default_pii'))->toBeFalse();
});

it('registers a before_send scrubber', function () {
    expect(config('sentry.before_send'))->toBeCallable(
        'Without before_send, anything attached to an event as `extra` leaves unredacted.'
    );
});

it('redacts secrets from an outbound event', function () {
    $event = Event::createEvent();
    $event->setLevel(Severity::error());
    $event->setExtra([
        'invoice_id' => 7,          // kept — this is the whole point of the report
        'api_key' => 'live-key',    // redacted
        'hmac' => 'sig',            // redacted
        'meta' => ['token' => 'x', 'ok' => 'visible'], // nested
    ]);

    $scrubbed = (config('sentry.before_send'))($event);

    expect($scrubbed)->not->toBeNull();
    $extra = $scrubbed->getExtra();

    expect($extra['invoice_id'])->toBe(7)
        ->and($extra['api_key'])->toBe('[redacted]')
        ->and($extra['hmac'])->toBe('[redacted]')
        ->and($extra['meta']['token'])->toBe('[redacted]')
        ->and($extra['meta']['ok'])->toBe('visible');
});

it('uses the same redaction list as the ops log', function () {
    // One policy, two consumers. If someone adds a key to OpsLog::REDACT, Sentry inherits it;
    // if someone forks the list, this is the test that should start looking wrong.
    $context = ['secret' => 'abc', 'card_number' => '4111', 'note' => 'fine'];

    $viaOpsLog = OpsLog::scrub($context);

    $event = Event::createEvent();
    $event->setExtra($context);
    $viaSentry = (config('sentry.before_send'))($event)->getExtra();

    expect($viaSentry)->toBe($viaOpsLog);
});
