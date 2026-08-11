<?php

use App\Models\Tenant;
use App\Providers\AppServiceProvider;
use App\Settings\IntegrationsSettings;
use Illuminate\Support\Facades\Hash;

/**
 * The Settings page wrote IntegrationsSettings::$paymob_enabled and NOTHING read
 * it — every gate read config('integrations.paymob.enabled'), i.e. PAYMOB_ENABLED
 * from .env. An operator who switched Paymob off in the UI saw "Saved", and the
 * mall carried on taking cards. A kill switch that silently does nothing is worse
 * than none: the operator stops looking for another way to stop it.
 *
 * The contract now: env = "credentials are provisioned", the setting = "collect
 * right now", ANDed. The toggle can only ever DISABLE.
 */
beforeEach(function () {
    config(['integrations.paymob.enabled' => true]);
});

/** Re-run just the kill-switch step, the way a fresh request's boot() would. */
function applyKillSwitches(): void
{
    (new AppServiceProvider(app()))->applyIntegrationKillSwitches();
}

it('switches card collection OFF when the operator turns the setting off', function () {
    app(IntegrationsSettings::class)->fill(['paymob_enabled' => false])->save();

    applyKillSwitches();

    expect(config('integrations.paymob.enabled'))->toBeFalse();
});

it('leaves card collection ON when the operator leaves the setting on', function () {
    app(IntegrationsSettings::class)->fill(['paymob_enabled' => true])->save();

    applyKillSwitches();

    expect(config('integrations.paymob.enabled'))->toBeTrue();
});

it('cannot switch payments ON without credentials — the toggle only ever narrows', function () {
    // No credentials provisioned…
    config(['integrations.paymob.enabled' => false]);
    // …and an operator who flips the UI toggle on anyway.
    app(IntegrationsSettings::class)->fill(['paymob_enabled' => true])->save();

    applyKillSwitches();

    expect(config('integrations.paymob.enabled'))->toBeFalse();
});

// REMOVED 2026-08-11 — 'it gates the same way on WhatsApp'.
//
// There is no longer a WhatsApp integration to gate. The action it protected was a stub whose
// entire body was a success notification, so the kill switch was narrowing something that never
// sent anything; the action, the toggle, the setting and the config key were all removed together.
//
// The test is deleted rather than adapted because there is nothing left for it to assert — and a
// kill-switch test for a feature that does not exist is precisely the kind of green tick this
// sweep set out to remove. The Paymob cases above still pin the mechanism itself.

it('survives an unreadable settings table instead of killing boot', function () {
    // Mid `migrate:fresh` the settings table does not exist yet. Boot must not die
    // for a switch that only ever narrows an already-configured integration.
    app()->bind(IntegrationsSettings::class, fn () => throw new RuntimeException('no settings table'));

    applyKillSwitches();

    expect(config('integrations.paymob.enabled'))->toBeTrue();
});

it('actually stops the mobile session endpoint — not just the config value', function () {
    // The whole point: the refusal has to reach the money path, not stop at config.
    ensureAllPropertiesAsset();
    $tenant = Tenant::create([
        'name' => 'Cafe Crema',
        'email' => 'killswitch@t.local',
        'password' => Hash::make('secret-pw'),
        'status' => 'active',
        'type' => 'company',
    ]);
    $lease = makeLease(makeUnit(makeAsset()), $tenant);
    $invoice = makeInvoice($lease, ['total' => 900, 'balance' => 900]);
    $token = $tenant->createToken('test-device', ['tenant:*'])->plainTextToken;

    // Control first: with the switch ON the endpoint is genuinely reachable, so the
    // refusal below can't pass for the wrong reason (a 409 from some other guard).
    app(IntegrationsSettings::class)->fill(['paymob_enabled' => true])->save();
    applyKillSwitches();
    expect(config('integrations.paymob.enabled'))->toBeTrue();

    app(IntegrationsSettings::class)->fill(['paymob_enabled' => false])->save();
    applyKillSwitches();

    $this->postJson(
        "/api/v1/me/invoices/{$invoice->id}/paymob-session",
        [],
        ['Authorization' => "Bearer {$token}"],
    )
        ->assertStatus(409)
        ->assertJsonPath('error', 'paymob_disabled');
});
