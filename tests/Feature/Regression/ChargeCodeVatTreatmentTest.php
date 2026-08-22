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
| Since 2026-08-12 the charge code names a TAX CODE rather than restating that tax's own treatment
| and rate, so the same ruling is made by pointing a supply at `VAT_EXEMPT` instead of typing
| "exempt" onto twelve rows. What the tests below hold down is unchanged by that, and one is new:
|
|   1. A ruling on the row reaches what a SERVICE originates, not just a hand-typed invoice line.
|      Half a catalogue is worse than none: the accountant flips `marketing` to standard-rated, the
|      screen agrees, and the monthly levy keeps billing 0.
|   2. It is ORIGINATION only. An issued invoice keeps the rate it was billed at, or a ruling made
|      in August silently restates every document since January.
|   3. A rate resolves for the DOCUMENT'S DATE, not for today — the capability a settings field
|      could not have, and the reason the rate moved to a dated ladder.
|   4. Each refusal has a control beside it. "0% VAT" passes just as happily when the service under
|      test never ran.
*/

use App\Models\Charge;
use App\Models\ChargeCode;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\TaxCode;
use App\Services\LeaseCreationService;
use App\Services\MarketingLevyService;
use App\Support\Vat;
use Carbon\CarbonImmutable;
use Database\Seeders\ChargeCodeSeeder;
use Database\Seeders\TaxCodeSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\TaxCatalogue;

beforeEach(function () {
    $this->seed(TaxCodeSeeder::class);
    $this->seed(ChargeCodeSeeder::class);
});

/** The accountant's ruling: this supply is billed under that tax. */
function ruleOn(string $code, string $taxCode): void
{
    ChargeCode::where('code', $code)->firstOrFail()->update(['tax_code' => $taxCode]);
    ChargeCode::flushLookupCaches();
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

    // Null, not false: since EG-01 the row states nothing and the catalogue answers. The
    // resolver above is the assertion that matters — the column is only checked to prove the 0.0
    // came from the ruling rather than from a `false` frozen onto the row at creation.
    expect($exempt->resolvedVatRate())->toBe(0.0)
        ->and($exempt->vat_applicable)->toBeNull();

    // The accountant rules that the levy IS consideration for a marketing service. A ruling alone
    // opens no ROW — the schedule no-ops when the amount is unchanged — so what is exercised here
    // is the next row the levy opens, off a rent change.
    //
    // Since EG-01 the ruling also reaches the row that already exists, because taxability is
    // resolved at billing rather than stored; what a new row proves is the SCHEDULE's behaviour,
    // not the ruling's reach. Issued invoices keep their rate either way — pinned by
    // `TaxabilityIsNotFrozenOntoAChargeRowTest`.
    ruleOn('marketing', 'VAT_14');
    $lease->update(['base_rent_monthly' => 20000]);
    app(MarketingLevyService::class)->createLevyCharge($lease, CarbonImmutable::parse('2026-07-01'));
    $taxable = Charge::where('lease_id', $lease->id)->where('type', 'marketing')->latest('id')->first();

    expect($taxable->resolvedVatRate())->toBe(Vat::standardRate())
        ->and($taxable->vat_applicable)->toBeNull()
        ->and($taxable->id)->not->toBe($exempt->id, 'the levy must be a NEW schedule row, not a rewrite of the old one');
});

it('exempts a supply the accountant exempts, on the lease-creation path', function () {
    ruleOn('service_charge', 'VAT_EXEMPT');

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

    // Null since EG-01 — the 0.0 is the ruling being read at billing, not a stored exemption.
    expect($service->resolvedVatRate())->toBe(0.0)
        ->and($service->vat_applicable)->toBeNull();

    // The control: with the ruling reversed, the same path taxes it — so the assertion above is
    // the treatment taking effect and not the service failing to write a rate at all.
    ruleOn('service_charge', 'VAT_14');

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

    expect($second->charges()->where('type', 'service_charge')->first()->resolvedVatRate())
        ->toBe(Vat::standardRate());
});

it('honours a schedule rate for a supply on a tax of its own', function () {
    // A supply on its own rate is a DIFFERENT TAX, not a rate typed against the standard one —
    // which is how Odoo and SAP model it, and what makes the schedule taxes expressible at all.
    // The operator's catalogue carries schedule tax at seven rates, seeded with those rates but
    // switched OFF — the GL account for the schedule-tax family is not wired yet, and `TaxCode`
    // refuses to activate a code that would collect into nowhere. So commissioning one is: name the
    // account, switch it on. The model refuses any shorter route.
    TaxCode::where('code', 'SCHD_5')->firstOrFail()->update(['posting_role' => 'vat_payable']);
    TaxCatalogue::activate('SCHD_5');
    ruleOn('parking', 'SCHD_5');

    expect(Vat::rateForType('parking'))->toBe(5.0)
        // The standard rate is untouched — another tax's rate is not a second opinion on this one.
        ->and(Vat::rateForType('service_charge'))->toBe(Vat::standardRate())
        ->and(Vat::standardRate())->not->toBe(5.0);
});

it('treats zero-rated as billing nothing while staying a taxable supply', function () {
    ruleOn('utility', 'VAT_0');

    expect(Vat::rateForType('utility'))->toBe(Vat::EXEMPT)
        // …and the distinction survives where it is needed: on the tax, for the return.
        ->and(TaxCode::treatmentOf(ChargeCode::taxCodeFor('utility')))->toBe(TaxCode::ZERO_RATED);
});

it('resolves the rate that was in force on the document\'s date, not today\'s', function () {
    // The capability a settings field could not have. The accountant announces a rise in advance;
    // a document raised before it must still bill the old rate, and one raised after must not need
    // anybody to remember to change anything.
    TaxCatalogue::setStandardRate(14.0, '2017-07-01');
    TaxCatalogue::setRate(Vat::STANDARD_TAX_CODE, 17.0, '2027-01-01');

    expect(Vat::rateForType('service_charge', '2026-12-31'))->toBe(14.0)
        ->and(Vat::rateForType('service_charge', '2027-01-01'))->toBe(17.0)
        ->and(Vat::rateForType('service_charge', '2027-06-30'))->toBe(17.0)
        // Exempt stays exempt across a rate change — the property that has to survive every one.
        ->and(Vat::rateForType('base_rent', '2027-06-30'))->toBe(Vat::EXEMPT);
});

it('applies the earliest rung to a document older than the ladder', function () {
    // A ladder that starts in 2017 says "14% since 2017", not "no VAT existed before 2017".
    // Returning nothing would silently under-collect on a back-dated import.
    TaxCatalogue::setStandardRate(14.0, '2017-07-01');

    expect(Vat::rateForType('service_charge', '2015-01-01'))->toBe(14.0);
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

    ruleOn('service_charge', 'VAT_EXEMPT');
    // …and the harder version of the same rule: the RATE moves too, not just the treatment.
    TaxCatalogue::setStandardRate(25.0);
    $invoice->refresh();

    expect((float) $invoice->items()->first()->vat_rate)->toBe($billedRate)
        ->and((float) $invoice->vat_amount)->toBe(Vat::atRate(1000, $billedRate))
        ->and($billedRate)->toBeGreaterThan(0.0);
});

it('falls back to the floor when the catalogue has no answer', function () {
    // The hazard the floor exists for: an empty catalogue must not make rent taxable.
    DB::table('charge_codes')->delete();
    DB::table('tax_codes')->delete();
    ChargeCode::flushLookupCaches();
    TaxCode::flushLookupCaches();

    expect(Vat::rateForType('base_rent'))->toBe(Vat::EXEMPT)
        ->and(Vat::rateForType('parking'))->toBe(Vat::EXEMPT)
        // …an unknown code is assumed taxable rather than silently untaxed…
        ->and(Vat::rateForType('key_money'))->toBe(Vat::standardRate())
        // …and with no tax catalogue at all, the standard rate is still the one this system bills,
        // not zero. A fresh deployment before its seeders run must not issue VAT-free invoices.
        ->and(Vat::standardRate())->toBe(Vat::DEFAULT_STANDARD_RATE);
});

it('keeps an exempt supply exempt when its tax code is deactivated out from under it', function () {
    // A charge code pointing at a tax the catalogue no longer holds must land on the floor, not on
    // the standard rate — otherwise deleting a tax code would start taxing rent.
    ruleOn('base_rent', 'VAT_GONE');

    expect(Vat::rateForType('base_rent'))->toBe(Vat::EXEMPT)
        // The control: a supply the floor does NOT exempt still assumes taxable.
        ->and(Vat::rateForType('service_charge'))->toBe(Vat::standardRate());
});
