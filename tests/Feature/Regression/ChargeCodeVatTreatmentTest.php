<?php

/*
|--------------------------------------------------------------------------
| Taxability is the accountant's row, not the developer's array
|--------------------------------------------------------------------------
| Which supplies carry VAT lived in `Vat::EXEMPT_TYPES` — PHP — while `charge_codes`, the catalogue
| an accountant maintains without a deploy, had no taxability column at all. So they could add "key
| money" and bill it, but not say whether it was taxable: a new code originated at 14% silently, and
| exempting it needed a developer. Yardi puts the same decision on the charge code (`Tax = Yes`),
| and for the same reason — tax policy is data.
|
| What these tests hold down:
|   1. A ruling on the row reaches what a SERVICE originates, not just a hand-typed invoice line.
|      Half a catalogue is worse than none: the accountant flips `marketing` to standard-rated, the
|      screen agrees, and the monthly levy keeps billing 0.
|   2. It is ORIGINATION only. An issued invoice keeps the rate it was billed at, or a ruling made
|      in August silently restates every document since January.
|   3. Each refusal has a control beside it. "0% VAT" passes just as happily when the service under
|      test never ran.
*/

use App\Models\Charge;
use App\Models\ChargeCode;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\LeaseCreationService;
use App\Services\MarketingLevyService;
use App\Support\Vat;
use Database\Seeders\ChargeCodeSeeder;

beforeEach(function () {
    $this->seed(ChargeCodeSeeder::class);
});

function ruleOn(string $code, string $treatment, ?float $override = null): void
{
    ChargeCode::where('code', $code)->firstOrFail()
        ->update(['vat_treatment' => $treatment, 'vat_rate_override' => $override]);
}

it('bills a levy at the rate its charge code says, not the one the service used to hard-code', function () {
    $lease = makeLease(makeUnit(makeAsset()), makeTenant(), [
        'status' => 'active',
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2026-12-31',
        'base_rent_monthly' => 10000,
        'has_marketing_levy' => true,
    ]);

    // Control first: as shipped, the levy follows rent and is out of scope.
    app(MarketingLevyService::class)->createLevyCharge($lease);
    $exempt = Charge::where('lease_id', $lease->id)->where('type', 'marketing')->latest('id')->first();

    expect((float) $exempt->vat_rate)->toBe(0.0)
        ->and($exempt->vat_applicable)->toBeFalse();

    // The accountant rules that the levy IS consideration for a marketing service. A ruling alone
    // opens no row (the schedule no-ops when the amount is unchanged, which is what stops an issued
    // month being re-rated) — it reaches the NEXT row the levy opens, here a rent change.
    ruleOn('marketing', ChargeCode::VAT_STANDARD);
    $lease->update(['base_rent_monthly' => 20000]);
    app(MarketingLevyService::class)->createLevyCharge($lease, \Carbon\CarbonImmutable::parse('2026-07-01'));
    $taxable = Charge::where('lease_id', $lease->id)->where('type', 'marketing')->latest('id')->first();

    expect((float) $taxable->vat_rate)->toBe(Vat::standardRate())
        ->and($taxable->vat_applicable)->toBeTrue()
        ->and($taxable->id)->not->toBe($exempt->id, 'the levy must be a NEW schedule row, not a rewrite of the old one');
});

it('exempts a supply the accountant exempts, on the lease-creation path', function () {
    ruleOn('service_charge', ChargeCode::VAT_EXEMPT);

    $lease = app(LeaseCreationService::class)->create([
        'tenant_mode' => 'existing',
        'tenant_id' => makeTenant()->id,
        'lease' => [
            'unit_id' => makeUnit(makeAsset())->id,
            'commencement_date' => '2026-01-01',
            'term_months' => 12,
            'base_rent_monthly' => 30000,
            'service_charge_monthly' => 5000,
        ],
    ]);

    $service = $lease->charges()->where('type', 'service_charge')->first();

    expect((float) $service->vat_rate)->toBe(0.0)
        ->and($service->vat_applicable)->toBeFalse();

    // The control: with the ruling reversed, the same path taxes it — so the assertion above is
    // the treatment taking effect and not the service failing to write a rate at all.
    ruleOn('service_charge', ChargeCode::VAT_STANDARD);

    $second = app(LeaseCreationService::class)->create([
        'tenant_mode' => 'existing',
        'tenant_id' => makeTenant()->id,
        'lease' => [
            'unit_id' => makeUnit(makeAsset())->id,
            'commencement_date' => '2026-01-01',
            'term_months' => 12,
            'base_rent_monthly' => 30000,
            'service_charge_monthly' => 5000,
        ],
    ]);

    expect((float) $second->charges()->where('type', 'service_charge')->first()->vat_rate)
        ->toBe(Vat::standardRate());
});

it('honours a per-code rate for a supply on its own schedule rate', function () {
    ruleOn('parking', ChargeCode::VAT_STANDARD, 5.0);

    expect(Vat::rateForType('parking'))->toBe(5.0)
        // The standard rate is untouched — an override is one code's answer, not a second setting.
        ->and(Vat::rateForType('service_charge'))->toBe(Vat::standardRate())
        ->and(Vat::standardRate())->not->toBe(5.0);
});

it('treats zero-rated as billing nothing while staying a taxable supply', function () {
    ruleOn('utility', ChargeCode::VAT_ZERO_RATED);

    expect(Vat::rateForType('utility'))->toBe(Vat::EXEMPT)
        // …and the distinction survives where it is needed: on the row, for the return.
        ->and(ChargeCode::vatPolicyFor('utility')['treatment'])->toBe(ChargeCode::VAT_ZERO_RATED);
});

it('never re-rates an invoice that has already been issued', function () {
    $lease = makeLease(makeUnit(makeAsset()), makeTenant(), [
        'status' => 'active',
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2026-12-31',
    ]);

    $invoice = Invoice::create([
        'lease_id' => $lease->id,
        'tenant_id' => $lease->tenant_id,
        'status' => 'issued',
        'issue_date' => '2026-03-01',
        'due_date' => '2026-03-08',
        'period_start' => '2026-03-01',
        'period_end' => '2026-03-31',
        'subtotal' => 1000,
        'vat_amount' => Vat::on(1000),
        'total' => 1000 + Vat::on(1000),
        'paid_amount' => 0,
        'balance' => 1000 + Vat::on(1000),
        'currency' => 'EGP',
    ]);
    InvoiceItem::create([
        'invoice_id' => $invoice->id,
        'description' => 'Service charge — March',
        'type' => 'service_charge',
        'amount' => 1000,
        'vat_rate' => Vat::standardRate(),
        'vat_amount' => Vat::on(1000),
        'total' => 1000 + Vat::on(1000),
    ]);

    $billedRate = (float) $invoice->items()->first()->vat_rate;

    ruleOn('service_charge', ChargeCode::VAT_EXEMPT);
    $invoice->refresh();

    expect((float) $invoice->items()->first()->vat_rate)->toBe($billedRate)
        ->and((float) $invoice->vat_amount)->toBe(Vat::atRate(1000, $billedRate))
        ->and($billedRate)->toBeGreaterThan(0.0);
});

it('falls back to the floor when the catalogue has no answer', function () {
    // The hazard the floor exists for: an empty catalogue must not make rent taxable.
    \Illuminate\Support\Facades\DB::table('charge_codes')->delete();
    ChargeCode::flushLookupCaches();

    expect(Vat::rateForType('base_rent'))->toBe(Vat::EXEMPT)
        ->and(Vat::rateForType('parking'))->toBe(Vat::EXEMPT)
        // …and an unknown code is assumed taxable rather than silently untaxed.
        ->and(Vat::rateForType('key_money'))->toBe(Vat::standardRate());
});
