<?php

use App\Enums\PartyType;
use App\Models\Charge;
use App\Models\CreditNote;
use App\Models\TaxCode;
use App\Models\TaxRate;
use App\Models\UnitOwnership;
use App\Services\BillUnitOwnershipsService;
use App\Services\CreditUnearnedBillingService;
use App\Support\ProrationMethod;
use App\Support\Vat;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChargeCodeSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\TaxCodeSeeder;

/**
 * AN ASSESSMENT MUST RECORD THE DAYS IT BILLED, NOT THE MONTH IT FELL IN.
 *
 * The lease run learned this as MF-02 and says so beside the field it fixed: *"a final invoice that
 * claims to cover a whole month it prorated is the document an auditor queries."*
 * `BillUnitOwnershipsService` was written afterwards, prorates by exactly the same rule — it calls
 * `MonthlyBillingService::monthsCovered()` rather than copying it — and then stamped the raw
 * calendar month on the invoice anyway. An owner who took handover on 16 August was billed 16 days
 * and handed a document saying 1–31 August.
 *
 * **It is not a presentation fault.** `CreditUnearnedBillingService` re-derives the split from
 * `period_start`/`period_end` through that same `monthsCovered()`, so a wrong window is a wrong
 * CREDIT. Resell on 21 August and the credit is computed as though a whole month had been billed:
 * the seller gets 0.355 of the line where 0.6875 was owed, and the mall keeps the difference. There
 * is no screen on which those two numbers appear together, so nobody re-derives it by hand.
 *
 * The `alreadyBilled()` guard is an OVERLAP test, so a narrower stamped window still stops the run
 * re-billing the month — that is asserted here, because a fix that made a part-month owner billable
 * twice would be worse than the fault it corrects.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);

    // The figures below are `actual`-proration figures — days over the month's own length. It is
    // the shipped default, and pinning it is what stops a property-tier override in some future
    // fixture turning these into 1,653.33 or 3,100.00 with nothing to say why.
    expect(ProrationMethod::DEFAULT)->toBe(ProrationMethod::ACTUAL);

    $this->asset = makeAsset();
    $this->unit = makeUnit($this->asset, ['code' => 'OWN-1']);
    $this->owner = makeTenant(['party_type' => PartyType::UnitOwner->value]);
});

function ownershipFrom(string $startedAt, ?string $endedAt = null): UnitOwnership
{
    $ownership = UnitOwnership::create([
        'asset_id' => test()->asset->id,
        'unit_id' => test()->unit->id,
        'tenant_id' => test()->owner->id,
        'tenure_type' => 'freehold',
        'status' => 'handed_over',
        'assessment_basis' => 'area',
        'ownership_share_pct' => 100,
        'started_at' => $startedAt,
        'handover_date' => $startedAt,
        'ended_at' => $endedAt,
        'payment_terms_days' => 15,
        'currency' => 'EGP',
    ]);

    Charge::create([
        'unit_ownership_id' => $ownership->id,
        'name' => 'Service charge',
        'type' => 'service_charge',
        'amount' => 3100,
        'currency' => 'EGP',
        'frequency' => 'monthly',
        'vat_applicable' => false,
        'vat_rate' => 0,
        'start_date' => $startedAt,
        'is_active' => true,
    ]);

    return $ownership->fresh();
}

it('stamps the days it billed when the owner takes handover mid-month', function () {
    $ownership = ownershipFrom('2026-08-16');

    $period = CarbonImmutable::parse('2026-08-01');
    $invoice = app(BillUnitOwnershipsService::class)
        ->billOne($ownership, $period, $period->endOfMonth());

    expect($invoice)->not->toBeNull()
        // 16 of August's 31 days.
        ->and(round((float) $invoice->subtotal, 2))->toEqual(1600.0)
        ->and($invoice->period_start->toDateString())->toBe('2026-08-16')
        ->and($invoice->period_end->toDateString())->toBe('2026-08-31');
});

it('credits the seller for the days they did not own, on the days they were billed', function () {
    $ownership = ownershipFrom('2026-08-16');

    $period = CarbonImmutable::parse('2026-08-01');
    $invoice = app(BillUnitOwnershipsService::class)
        ->billOne($ownership, $period, $period->endOfMonth());

    // Resold on the 21st, so the seller's last owned day is the 20th: 16–20 August, five of the
    // sixteen days billed.
    app(CreditUnearnedBillingService::class)
        ->forOwnershipTransfer($ownership->fresh(), CarbonImmutable::parse('2026-08-21'));

    $credited = (float) CreditNote::query()->where('invoice_id', $invoice->id)->sum('total');

    // 1,600 billed for 16 days; 5 days earned. Unearned = 11/16 = 1,100.
    expect(round($credited, 2))->toEqual(1100.0);
});

it('still refuses to bill the same month twice', function () {
    // The overlap guard reads `period_start`/`period_end`, so narrowing them must not open a second
    // billing of the same month. A fix that did would be worse than the fault it corrects.
    $ownership = ownershipFrom('2026-08-16');

    $period = CarbonImmutable::parse('2026-08-01');
    $first = app(BillUnitOwnershipsService::class)->billOne($ownership, $period, $period->endOfMonth());

    // The control the refusal needs: a fixture that quietly stopped billing at all — no charges,
    // wrong status, zero total — would satisfy the assertion below for the wrong reason.
    expect($first)->not->toBeNull();

    expect(app(BillUnitOwnershipsService::class)
        ->billOne($ownership->fresh(), $period, $period->endOfMonth()))->toBeNull();
});

it('leaves a whole month alone', function () {
    // The control. An ownership that ran the full month must still record the full month — a fix
    // that narrowed every window would satisfy the assertions above and misstate every ordinary
    // assessment.
    $ownership = ownershipFrom('2026-01-01');

    $period = CarbonImmutable::parse('2026-08-01');
    $invoice = app(BillUnitOwnershipsService::class)
        ->billOne($ownership, $period, $period->endOfMonth());

    expect(round((float) $invoice->subtotal, 2))->toEqual(3100.0)
        ->and($invoice->period_start->toDateString())->toBe('2026-08-01')
        ->and($invoice->period_end->toDateString())->toBe('2026-08-31');
});

it('stamps the days it billed when the ownership ENDS mid-month', function () {
    // The trailing edge, which the first pass left entirely unproven — every case set only
    // `started_at`, so `periodEnd: $windowEnd` was covered by nothing. It is reachable outside a
    // transfer: `ended_at` is a plain editable date on the ownership form with no tie to `status`.
    $ownership = ownershipFrom('2026-01-01', '2026-08-20');

    $period = CarbonImmutable::parse('2026-08-01');
    $invoice = app(BillUnitOwnershipsService::class)
        ->billOne($ownership, $period, $period->endOfMonth());

    expect($invoice)->not->toBeNull()
        // 20 of August's 31 days.
        ->and(round((float) $invoice->subtotal, 2))->toEqual(2000.0)
        ->and($invoice->period_start->toDateString())->toBe('2026-08-01')
        ->and($invoice->period_end->toDateString())->toBe('2026-08-20');
});

it('issues the assessment on the day the period starts, not the day the month does', function () {
    // The document has to agree with itself. Stamping the real window while leaving `issue_date` at
    // the calendar 1st printed *Issue date 01/08 · Due 16/08 · Period 16/08–31/08* — issued a
    // fortnight before the owner owned anything, and falling due earlier than the identical lease
    // invoice, which the overdue scan and the late-fee run then act on.
    $ownership = ownershipFrom('2026-08-16');

    $period = CarbonImmutable::parse('2026-08-01');
    $invoice = app(BillUnitOwnershipsService::class)
        ->billOne($ownership, $period, $period->endOfMonth());

    expect($invoice->issue_date->toDateString())->toBe('2026-08-16')
        ->and($invoice->issue_date->toDateString())->toBe($invoice->period_start->toDateString())
        // …and the GL period is unmoved, which is what made this safe to change: both dates are
        // inside the same calendar month by construction.
        ->and($invoice->issue_date->format('Y-m'))->toBe('2026-08');
});

it('rates the assessment for the date the invoice will carry', function () {
    // A rate rung effective mid-month — Law 157/2025 is the live case. The run resolved the rate for
    // the calendar 1st while the invoice stamped the real window, so the two disagreed the moment
    // the window stopped being the month. `InvoiceForm` re-derives a human's reading of this same
    // document as `period_start ?: issue_date`, so the operator and the run would have read
    // different rates off the same line.
    test()->seed(TaxCodeSeeder::class);
    test()->seed(ChargeCodeSeeder::class);

    TaxRate::create([
        'tax_code_id' => TaxCode::where('code', Vat::STANDARD_TAX_CODE)->value('id'),
        'rate' => 20,
        'effective_from' => '2026-08-10',
    ]);

    $ownership = ownershipFrom('2026-08-16');
    // A taxable row: the fixture's own charge is deliberately VAT-free everywhere else here.
    $ownership->charges()->update(['vat_applicable' => null, 'vat_rate' => null]);

    $period = CarbonImmutable::parse('2026-08-01');
    $invoice = app(BillUnitOwnershipsService::class)
        ->billOne($ownership->fresh(), $period, $period->endOfMonth());

    // 16 August is after the rise, so the line bears 20 — never the 14 the 1st would have resolved.
    expect((float) $invoice->items()->first()->vat_rate)->toEqual(20.0);
});
