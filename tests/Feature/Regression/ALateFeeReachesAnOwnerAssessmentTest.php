<?php

use App\Enums\PartyType;
use App\Enums\UnitOwnershipStatus;
use App\Models\Invoice;
use App\Models\UnitOwnership;
use App\Services\LateFeeService;
use App\Support\PropertySettings;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * The nightly late-fee run must survive an invoice that carries no lease.
 *
 * `invoices.lease_id` stopped being NOT NULL when module 37 introduced the unit owner — a party who
 * pays a صيانة assessment and holds no lease at all. `LateFeeService` still resolved payment terms
 * with `$locked->lease->paymentTermsDays()`, under a comment asserting the column was NOT NULL, so
 * the first overdue owner assessment the sweep reached threw.
 *
 * **A scheduled command that throws is the quietest failure this system has.** Nobody sees an error.
 * The run's own try/catch counts it as `failed` in a log line at 04:00 and moves on, so the owner is
 * never charged a late fee — and every diagnosis of "why was this tenant not charged" starts by
 * looking at the tenant. Measured on the demo books before the fix: **48 invoices carry no lease,
 * and all 48 sit in a status this sweep selects.**
 *
 * The fix is `Invoice::agreement()` — the lease or the ownership, both of which implement the whole
 * `BillableAgreement` contract — so the fee invoice is raised against whichever agreement owed the
 * money and no call site needs a branch.
 *
 * The control matters as much as the refusal here: a sweep that quietly charged nobody would satisfy
 * "it did not throw" perfectly.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);

    $this->asset = makeAsset();
    $this->today = CarbonImmutable::parse('2026-06-30');
});

/** A unit sold and handed over — the party module 37 introduced, who holds no lease. */
function handedOverOwnership(): UnitOwnership
{
    return UnitOwnership::create([
        'asset_id' => test()->asset->id,
        'unit_id' => makeUnit(test()->asset, ['area_sqm' => 250])->id,
        'tenant_id' => makeTenant(['party_type' => PartyType::UnitOwner->value])->id,
        'status' => UnitOwnershipStatus::HandedOver->value,
        'started_at' => '2025-01-01',
    ]);
}

/**
 * An overdue assessment: an invoice whose agreement is an OWNERSHIP, so `lease_id` is null.
 *
 * Totals set directly, no line items — the same shape `makeInvoice()` uses. The late-fee sweep
 * selects on status, balance and due date, and its one item check is for an existing `late_fee`
 * line, so lines would add nothing here but a chance to be wrong about a column.
 */
function overdueAssessment(UnitOwnership $ownership): Invoice
{
    return Invoice::create([
        'asset_id' => test()->asset->id,
        'unit_ownership_id' => $ownership->id,
        'tenant_id' => $ownership->tenant_id,
        'status' => 'overdue',
        'issue_date' => test()->today->subDays(45)->toDateString(),
        'due_date' => test()->today->subDays(45)->toDateString(),
        'period_start' => test()->today->subDays(45)->startOfMonth()->toDateString(),
        'period_end' => test()->today->subDays(45)->endOfMonth()->toDateString(),
        'subtotal' => 5000,
        'vat_amount' => 0,
        'total' => 5000,
        'paid_amount' => 0,
        'balance' => 5000,
        'currency' => 'EGP',
    ]);
}

it('charges a late fee on an overdue owner assessment instead of fatalling', function () {
    $ownership = handedOverOwnership();
    $assessment = overdueAssessment($ownership);

    expect($assessment->lease_id)->toBeNull()                     // the shape that used to throw
        ->and((float) $assessment->balance)->toBeGreaterThan(0.0);

    $applied = app(LateFeeService::class)->applyTo($assessment, $this->today);

    expect($applied)->toBeTrue();

    $fee = Invoice::query()
        ->where('unit_ownership_id', $ownership->id)
        ->whereKeyNot($assessment->id)
        ->latest('id')
        ->first();

    expect($fee)->not->toBeNull('no fee invoice was raised for the owner')
        // Raised against the OWNERSHIP, which is the agreement that owed the money.
        ->and($fee->unit_ownership_id)->toBe($ownership->id)
        ->and($fee->lease_id)->toBeNull()
        ->and((float) $fee->total)->toBeGreaterThan(0.0);
});

it('does not let one lease-less invoice take down the whole run', function () {
    $ownership = handedOverOwnership();
    overdueAssessment($ownership);

    // A lease invoice in the same run. `runForToday()` catches per invoice, so the tenant's fee was
    // charged even before the fix — what was lost was the OWNER's, every night, counted as `failed`
    // in a log line naming an invoice id and no reason.
    $lease = makeLease(makeUnit($this->asset), null, ['status' => 'active']);
    $tenantInvoice = makeInvoice($lease, [
        'due_date' => $this->today->subDays(45)->toDateString(),
        'issue_date' => $this->today->subDays(45)->toDateString(),
    ]);
    $tenantInvoice->update(['status' => 'overdue']);

    $stats = app(LateFeeService::class)->runForToday($this->today);

    // Exactly two, not "at least": `>=` would stay green through a double-charge regression, which
    // is the failure the idempotency guard three lines up exists to prevent.
    expect($stats['failed'])->toBe(0)
        ->and($stats['applied'])->toBe(2);
});

it('prices an owner assessment from ITS OWN mall, not the portfolio default', function () {
    // An ownership states no late-fee clause, so its terms fall back through `PropertySettings` —
    // and that call passed a null asset id, which answers at the PORTFOLIO tier and silently
    // discards the mall's own clause. `grace_days` is the sharp one: it decides WHETHER a fee is
    // charged at all, not merely how much. The same defect `BillBouncedChequeFeeService` records
    // having fixed, one file away.
    PropertySettings::set('billing.late_fee_percent', $this->asset->id, 10);
    PropertySettings::set('billing.late_fee_minimum', $this->asset->id, 0);

    $ownership = handedOverOwnership();
    $assessment = overdueAssessment($ownership);

    expect(app(LateFeeService::class)->applyTo($assessment, $this->today))->toBeTrue();

    $fee = Invoice::query()
        ->where('unit_ownership_id', $ownership->id)
        ->whereKeyNot($assessment->id)
        ->latest('id')
        ->firstOrFail();

    // 10% of the 5,000 balance — this mall's rate. The portfolio default is 2%, so a fee of 100
    // would mean the property tier was skipped.
    expect((float) $fee->subtotal)->toEqual(500.0);
});
