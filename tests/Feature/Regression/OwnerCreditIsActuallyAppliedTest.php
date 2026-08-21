<?php

/*
|--------------------------------------------------------------------------
| A unit owner's on-account credit reaches their assessment
|--------------------------------------------------------------------------
| `ApplyTenantCreditService` resolved the invoice's property by walking `lease → unit → asset`.
| `invoices.lease_id` is NULL for a unit-owner assessment BY CONSTRUCTION —
| `UnitOwnership::invoiceLinkAttributes()` returns `lease_id => null` and
| `Invoice::assertBelongsToExactlyOneAgreement()` enforces it — so the chain answered null and the
| service refused every owner assessment.
|
| **The refusal was invisible.** It throws `DomainException`, and `Invoice::saved()` catches exactly
| that with a comment calling it "the ORDINARY case — most invoices have none" and deliberately does
| not log. `BillingSettings::$auto_apply_tenant_credit` ships TRUE. So the monthly
| `billing:run-assessments` re-billed every owner in full, their credit sat on account for ever, and
| nothing anywhere recorded that a draw-down had been refused. The manual path was worse than
| silent: `EditInvoice::apply_credit` renders (its predicate reads `Tenant::creditBalance()`, which
| HAD been fixed to scope on `invoices.asset_id`), shows "Credit available: EGP 2,000", pre-fills the
| amount, and answers "This tenant has no credit balance to apply to this invoice."
|
| Both cases below are the SAME shape and differ only in the agreement. That pairing is the point:
| the leased control passed before the fix and after it, so a test that only covered the owner could
| have been satisfied by a change that broke everyone.
*/

use App\Enums\UnitOwnershipStatus;
use App\Models\Invoice;
use App\Models\UnitOwnership;
use App\Settings\BillingSettings;

beforeEach(function () {
    $this->asset = makeAsset(['name' => 'Atriom Walk']);

    // The shipped default, stated rather than assumed: this whole failure is unattended.
    tap(app(BillingSettings::class), fn (BillingSettings $s) => $s->auto_apply_tenant_credit = true);
});

it('applies a unit owner\'s credit to their next assessment', function () {
    $unit = makeUnit($this->asset);
    $owner = makeTenant(['asset_id' => $this->asset->id, 'name' => 'Mona Fahmy']);

    $ownership = UnitOwnership::create([
        'asset_id' => $this->asset->id,
        'unit_id' => $unit->id,
        'tenant_id' => $owner->id,
        'status' => UnitOwnershipStatus::HandedOver->value,
        'started_at' => '2026-01-01',
    ]);

    // March: billed 1,000, paid 3,000 — 2,000 sits on account.
    $march = assessmentFor($ownership, $owner->id, 1000);
    overpay($march, 3000);

    expect(round($owner->fresh()->creditBalance([$this->asset->id]), 2))->toBe(2000.0);

    // April: the auto-apply hook fires on `saved`. Before the fix it threw and was swallowed.
    $april = assessmentFor($ownership, $owner->id, 1000, '2026-04-01');

    expect(round((float) $april->fresh()->balance, 2))->toBe(0.0,
        'The owner holds 2,000 on account and April was 1,000 — it should be settled from credit.')
        ->and(round($owner->fresh()->creditBalance([$this->asset->id]), 2))->toBe(1000.0,
            'The draw-down must reduce the remaining credit.');
});

it('applies a leasing tenant\'s credit exactly the same way — the control', function () {
    // Identical to the case above except the agreement. This one passed BEFORE the fix too: it is
    // here so that "the owner case works" cannot be satisfied by a change that settles everything,
    // or by one that breaks the path that was already right.
    $unit = makeUnit($this->asset);
    $tenant = makeTenant(['asset_id' => $this->asset->id, 'name' => 'Retailer']);
    $lease = makeLease($unit, $tenant, ['status' => 'active']);

    $march = assessmentFor($lease, $tenant->id, 1000);
    overpay($march, 3000);

    expect(round($tenant->fresh()->creditBalance([$this->asset->id]), 2))->toBe(2000.0);

    $april = assessmentFor($lease, $tenant->id, 1000, '2026-04-01');

    expect(round((float) $april->fresh()->balance, 2))->toBe(0.0)
        ->and(round($tenant->fresh()->creditBalance([$this->asset->id]), 2))->toBe(1000.0);
});
