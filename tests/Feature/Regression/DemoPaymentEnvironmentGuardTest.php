<?php

use App\Models\Asset;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Tenant;
use App\Models\Unit;
use App\Support\DemoPayments;
use App\Support\Health;
use Laravel\Sanctum\Sanctum;

/**
 * The demo-payment shortcut must not be reachable outside a demo box.
 *
 * It writes a real captured Payment through the production capture path, so an authenticated
 * tenant who reaches it can mark their own invoices paid: AR closes, the ledger posts
 * `Dr Bank / Cr AR`, and `billing:reconcile` stays green because every internal relationship is
 * genuinely consistent — the money simply never existed.
 *
 * Before this guard the ONLY condition was `! config('integrations.paymob.enabled')`, which is
 * inverted with respect to safety: Paymob-off is the shipped default AND the documented incident
 * response, so the endpoint was live exactly when the system was most exposed.
 *
 * These tests pin the environment rule itself rather than any one call site, because the rule had
 * been written out three times and all three would have had to be found.
 */
beforeEach(function () {
    config()->set('integrations.paymob.enabled', false);

    $asset = Asset::factory()->create();
    $unit = Unit::factory()->for($asset)->create();
    $this->tenant = Tenant::factory()->create();
    $lease = Lease::factory()->for($unit)->for($this->tenant)->create();

    $this->invoice = Invoice::factory()->for($lease)->create([
        'tenant_id' => $this->tenant->id,
        'status' => 'issued',
    ]);
    $this->invoice->recomputeTotals();
});

it('refuses the demo payment on production even when the flag is set', function () {
    inEnvironment('production');
    config()->set('integrations.demo_payments.enabled', true);

    expect(DemoPayments::enabled())->toBeFalse();
});

it('refuses the demo payment on a staging-shaped environment unless explicitly opted in', function () {
    inEnvironment('staging');
    config()->set('integrations.demo_payments.enabled', null);

    // Unset must NOT mean "on" anywhere that carries real-shaped data.
    expect(DemoPayments::enabled())->toBeFalse();

    config()->set('integrations.demo_payments.enabled', true);
    expect(DemoPayments::enabled())->toBeTrue();
});

it('refuses the demo payment whenever a real gateway is live', function () {
    config()->set('integrations.paymob.enabled', true);

    // Two live payment paths would let a tenant choose the free one.
    expect(DemoPayments::enabled())->toBeFalse();
});

it('allows the demo payment in testing, so the control below is meaningful', function () {
    expect(DemoPayments::enabled())->toBeTrue();
});

it('does not create a payment through the API when the environment refuses it', function () {
    inEnvironment('production');

    Sanctum::actingAs(tenantLogin($this->tenant), ['*'], 'tenant-api');

    $this->postJson("/api/v1/me/invoices/{$this->invoice->id}/pay-demo")
        ->assertStatus(409);

    // The refusal must be a refusal, not a no-op that happened to look like one.
    expect($this->invoice->fresh()->balance)->toEqual($this->invoice->balance)
        ->and($this->tenant->payments()->count())->toBe(0);
});

it('DOES create a payment through the API when the environment allows it — the paired control', function () {
    Sanctum::actingAs(tenantLogin($this->tenant), ['*'], 'tenant-api');

    $this->postJson("/api/v1/me/invoices/{$this->invoice->id}/pay-demo")
        ->assertStatus(201);

    // Without this control the refusal above would pass just as happily against a broken route.
    expect((float) $this->invoice->fresh()->balance)->toBe(0.0)
        ->and($this->tenant->payments()->count())->toBe(1);
});

it('fails the health check when the flag is set on production', function () {
    inEnvironment('production');
    config()->set('integrations.demo_payments.enabled', true);

    $check = Health::run()['checks']['demo_payments'];

    expect($check['ok'])->toBeFalse()
        ->and($check['detail'])->toContain('DEMO_PAYMENTS_ENABLED');
});

it('passes the health check on a production box with the flag unset', function () {
    inEnvironment('production');
    config()->set('integrations.demo_payments.enabled', null);

    expect(Health::run()['checks']['demo_payments']['ok'])->toBeTrue();
});
