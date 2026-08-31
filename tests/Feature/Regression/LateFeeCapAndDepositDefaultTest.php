<?php

use App\Filament\Admin\Resources\Leases\Pages\CreateLease;
use App\Models\Invoice;
use App\Models\Lease;
use App\Services\LateFeeService;
use App\Settings\BillingSettings;
use App\Support\PropertySettings;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * EG-35, the two halves that were settings-shaped (findings M-8 cap and M-11 deposit).
 *
 * **The cap.** `late_fee_minimum` existed and its opposite did not, so *"2% per month, minimum
 * EGP 50, capped at EGP 5,000"* was two thirds expressible. That asymmetry matters more than it
 * reads: a percentage of an arrears has no upper bound, so a tenant six months behind on a large
 * invoice drew a penalty proportional to the size of the debt rather than to the breach — the
 * figure a tenant disputes and an operator waives by hand.
 *
 * **The deposit.** The house policy was the literal `3` in `LeaseCreationService`'s `$rent * 3`, so
 * *"three months from Q1"* was a deploy and *"two months at the outlet mall"* was unsayable.
 *
 * Both ship at today's behaviour — 0 = no cap, 3 months — so no figure moves on deploy.
 *
 * **Not built, and each for a stated reason:** late-fee RECURRENCE (M-8's other half) turns
 * `invoices.late_fee_invoice_id` from a `belongsTo` into a one-to-many on a money link, which is a
 * schema change deserving its own tests; the ROUNDING mode (M-10) is 540 money sites and nobody has
 * asked for banker's rounding; and a QUARTERLY CAM true-up (M-12) is not a schedule change at all —
 * `cam_expense_pools` is `unique(asset_id, period_year)`, so the pool's own period has to change
 * first.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

beforeEach(function () {
    $s = app(BillingSettings::class);
    $s->late_fee_percent = 2;
    $s->late_fee_grace_days = 7;
    $s->late_fee_minimum = 50;
    $s->late_fee_maximum = 0;
    $s->default_security_deposit_months = 3;
});

function cappedOverdueInvoice(Lease $lease, float $balance): Invoice
{
    return makeInvoice($lease, ['due_date' => '2028-01-01', 'status' => 'overdue', 'balance' => $balance]);
}

it('charges the uncapped percentage while no cap is set', function () {
    // The control, and the deploy safety case: 0 means no ceiling, exactly as before this existed.
    CarbonImmutable::setTestNow('2028-02-01');

    $lease = makeLease(makeUnit(makeAsset()), null, ['late_fee_percent' => 2]);
    $invoice = cappedOverdueInvoice($lease, 900_000);

    app(LateFeeService::class)->applyTo($invoice);

    expect((float) $invoice->fresh()->lateFeeInvoice->items()->where('type', 'late_fee')->sole()->amount)->toBe(18000.0);
});

it('honours a cap the lease clause states', function () {
    CarbonImmutable::setTestNow('2028-02-01');

    $lease = makeLease(makeUnit(makeAsset()), null, ['late_fee_percent' => 2, 'late_fee_maximum' => 5000]);
    $invoice = cappedOverdueInvoice($lease, 900_000);

    app(LateFeeService::class)->applyTo($invoice);

    // 2% of 900,000 is 18,000; the clause caps it at 5,000.
    expect((float) $invoice->fresh()->lateFeeInvoice->items()->where('type', 'late_fee')->sole()->amount)->toBe(5000.0);
});

it('reaches a real invoice through lateFeeTerms, not only the detached fallback', function () {
    // The bug this test exists for. `invoices.lease_id` is NOT NULL, so `LateFeeService`'s
    // no-lease branch never runs in practice — a cap defined only there would be read as an
    // undefined key on every fee the sweep actually charges, and the ceiling would silently never
    // apply. Set at the PORTFOLIO tier with no lease override, so only the lease's own resolution
    // chain can deliver it.
    CarbonImmutable::setTestNow('2028-02-01');
    app(BillingSettings::class)->late_fee_maximum = 750;

    $lease = makeLease(makeUnit(makeAsset()), null, ['late_fee_percent' => 2]);

    expect($lease->lateFeeTerms())->toHaveKey('maximum')
        ->and($lease->lateFeeTerms()['maximum'])->toBe(750.0);

    $invoice = cappedOverdueInvoice($lease, 900_000);
    app(LateFeeService::class)->applyTo($invoice);

    expect((float) $invoice->fresh()->lateFeeInvoice->items()->where('type', 'late_fee')->sole()->amount)->toBe(750.0);
});

it('lets the cap win over the minimum when a clause sets it lower', function () {
    // Deliberate ordering. A ceiling the operator typed is a statement about the most they will
    // charge; a floor only rounds small ones up. Applying `max()` last would bill above a cap the
    // clause names, which is the one outcome a cap exists to prevent.
    CarbonImmutable::setTestNow('2028-02-01');

    $lease = makeLease(makeUnit(makeAsset()), null, [
        'late_fee_percent' => 2, 'late_fee_minimum' => 500, 'late_fee_maximum' => 100,
    ]);
    $invoice = cappedOverdueInvoice($lease, 1000);

    app(LateFeeService::class)->applyTo($invoice);

    // 2% of 1,000 = 20, floored to 500, then capped to 100.
    expect((float) $invoice->fresh()->lateFeeInvoice->items()->where('type', 'late_fee')->sole()->amount)->toBe(100.0);
});

it('takes the deposit months from the setting rather than a literal three', function () {
    expect((float) PropertySettings::get('billing.default_security_deposit_months', null))->toBe(3.0);

    app(BillingSettings::class)->default_security_deposit_months = 2;

    expect((float) PropertySettings::get('billing.default_security_deposit_months', null))->toBe(2.0);
});

it('lets one mall set its own deposit policy', function () {
    // The whole point of M-11: three months at the flagship, two at the outlet.
    $outlet = makeAsset(['code' => 'OUTLET']);

    PropertySettings::set('billing.default_security_deposit_months', $outlet->id, 2);

    expect((float) PropertySettings::get('billing.default_security_deposit_months', $outlet->id))->toBe(2.0)
        ->and((float) PropertySettings::get('billing.default_security_deposit_months', null))->toBe(3.0);
});

it('offers the deposit policy on the lease FORM, not only through the wizard', function () {
    // Found in review, after the commit. `LeaseCreationService` reads the setting, so the WIZARD
    // honoured it — and a lease created through the ordinary Filament form was typed from scratch.
    // "Three months from Q1" would have changed one of the two create paths and looked done, which
    // is exactly the shape of a policy that reaches nothing.
    //
    // Asserted by mounting the real create page and reading the field's state, not by inspecting
    // the schema: a default declared in a closure that never runs is the thing being guarded
    // against, and only mounting runs it.
    $this->seed(RolesPermissionsSeeder::class);

    $asset = makeAsset(['code' => 'MALL-FORM']);
    app(BillingSettings::class)->default_security_deposit_months = 2;

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(makeUser('super_admin', [$asset->id]));
    Filament::setTenant($asset);

    try {
        Livewire::test(CreateLease::class)
            ->assertOk()
            ->assertSchemaStateSet(['security_deposit_months' => 2.0]);
    } finally {
        Filament::setTenant(null, isQuiet: true);
    }
});
