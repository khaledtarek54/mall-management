<?php

/*
|--------------------------------------------------------------------------
| A tax rate on a document is picked, not typed
|--------------------------------------------------------------------------
| `invoice_items.vat_rate` was a free 0–100 text input, and the form said so in a comment: "the
| operator can still type a different rate on the line". So `Vat::rateForType()` governed the
| DEFAULT and nothing governed the VALUE — any operator could put any rate on a tax document.
|
| No reference system allows that un-gated. Yardi gates the override on rights, Odoo resolves from
| an `account.tax` record, SAP posts a tax code and the code carries the rate. All of them DO allow
| an override to someone, because a contract that fixed a rate is real — and forbidding it outright
| is worse than gating it, since operators then enter the difference as an invented line item.
|
| What these tests hold down:
|   1. The line records WHICH tax it carried, on every path — including the eight services that
|      raise lines without a form. That is what lets the VAT return tell exempt from zero-rated.
|   2. An operator without `tax_codes.override` cannot land a rate the catalogue did not produce,
|      **whatever they submit** — the form's readOnly is a UI gate and proves nothing on its own.
|   3. One who holds it can, and the departure is recorded.
|   4. An issued document is never re-rated by any of this.
*/

use App\Filament\Admin\Resources\Invoices\Pages\CreateInvoice;
use App\Models\ChargeCode;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\TaxCode;
use App\Support\Vat;
use Database\Seeders\ChargeCodeSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Database\Seeders\TaxCodeSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\TaxCatalogue;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(TaxCodeSeeder::class);
    $this->seed(ChargeCodeSeeder::class);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/** A draft invoice with one line, raised the way a service raises one. */
function invoiceWithLine(array $item = []): Invoice
{
    $lease = makeLease(makeUnit(makeAsset()), makeTenant(), [
        'status' => 'active',
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2026-12-31',
    ]);

    $invoice = Invoice::create([
        'lease_id' => $lease->id,
        'tenant_id' => $lease->tenant_id,
        'status' => 'draft',
        'issue_date' => '2026-03-01',
        'due_date' => '2026-03-08',
        'period_start' => '2026-03-01',
        'period_end' => '2026-03-31',
        'subtotal' => 0, 'vat_amount' => 0, 'total' => 0,
        'paid_amount' => 0, 'balance' => 0, 'currency' => 'EGP',
    ]);

    InvoiceItem::create($item + [
        'invoice_id' => $invoice->id,
        'description' => 'Service charge — March',
        'type' => 'service_charge',
        'amount' => 1000,
        'vat_rate' => Vat::rateForType('service_charge', '2026-03-01'),
    ]);

    return $invoice->fresh('items');
}

it('classifies a line raised by a service, with no form involved', function () {
    // The model-layer default. Eight services raise invoice lines and none of them names a tax
    // code; setting it in each is the shape that produced this codebase's recurring "half a
    // catalogue" bug, where the screen agrees with the accountant and the services quietly do
    // something else.
    $item = invoiceWithLine()->items->sole();

    expect($item->tax_code)->toBe('VAT_14')
        ->and((float) $item->vat_rate)->toBe(14.0);

    // …and an exempt supply is classified as exempt rather than left blank, which is the half that
    // makes the VAT return's exempt/zero-rated split possible at all.
    $rent = InvoiceItem::create([
        'invoice_id' => $item->invoice_id,
        'description' => 'Base rent — March',
        'type' => 'base_rent',
        'amount' => 5000,
        'vat_rate' => Vat::rateForType('base_rent', '2026-03-01'),
    ]);

    expect($rent->tax_code)->toBe('VAT_EXEMPT')
        ->and((float) $rent->vat_rate)->toBe(0.0);
});

it('never overwrites a tax code the caller stated', function () {
    // The default fills a blank; it does not overrule. A caller that knows better — the credit-note
    // form copying the reversed line's code — must win.
    $item = invoiceWithLine(['tax_code' => 'VAT_0']);

    expect($item->items->sole()->tax_code)->toBe('VAT_0');
});

it('refuses a typed rate from an operator without the override right, whatever they submit', function () {
    // The gate that matters. The form renders `vat_rate` readOnly for this operator — and readOnly
    // is hydrated and dehydrated like any other field, so a crafted Livewire payload sets it
    // exactly as if the box had been editable. This drives the SAVE PATH with the forged value.
    $lease = makeLease(makeUnit(makeAsset()), makeTenant(), [
        'status' => 'active', 'commencement_date' => '2026-01-01', 'expiry_date' => '2026-12-31',
    ]);

    $this->actingAs(makeUser('manager'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($lease->unit->asset);

    expect(auth()->user()->can('tax_codes.override'))->toBeFalse();

    Livewire::test(CreateInvoice::class)
        ->fillForm([
            'lease_id' => $lease->id,
            'tenant_id' => $lease->tenant_id,
            'status' => 'draft',
            'issue_date' => '2026-03-01',
            'due_date' => '2026-03-08',
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
            'items' => [[
                'type' => 'service_charge',
                'tax_code' => 'VAT_14',
                'description' => 'Service charge',
                'amount' => 1000,
                'vat_rate' => 3,           // ← forged: the catalogue says 14
            ]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $item = Invoice::latest('id')->first()->items->sole();

    expect((float) $item->vat_rate)->toBe(14.0, 'the catalogue rate must win over a forged one')
        ->and((float) $item->vat_amount)->toBe(140.0)
        ->and($item->tax_override_reason)->toBeNull();
});

it('lets an operator who holds the right depart from the catalogue, and records why', function () {
    // The control for the refusal above — without it, that test would pass just as happily if the
    // save path ignored the submitted rate for everyone, which would break a legitimate override.
    $lease = makeLease(makeUnit(makeAsset()), makeTenant(), [
        'status' => 'active', 'commencement_date' => '2026-01-01', 'expiry_date' => '2026-12-31',
    ]);

    $this->actingAs(makeUser('accounting'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($lease->unit->asset);

    expect(auth()->user()->can('tax_codes.override'))->toBeTrue();

    Livewire::test(CreateInvoice::class)
        ->fillForm([
            'lease_id' => $lease->id,
            'tenant_id' => $lease->tenant_id,
            'status' => 'draft',
            'issue_date' => '2026-03-01',
            'due_date' => '2026-03-08',
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
            'items' => [[
                'type' => 'service_charge',
                'tax_code' => 'VAT_14',
                'description' => 'Service charge',
                'amount' => 1000,
                'vat_rate' => 10,
                'tax_override_reason' => 'Rate fixed by the 2024 fit-out agreement',
            ]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $item = Invoice::latest('id')->first()->items->sole();

    expect((float) $item->vat_rate)->toBe(10.0)
        ->and($item->tax_override_reason)->toBe('Rate fixed by the 2024 fit-out agreement');
});

it('clears a reason left behind when the rate is put back', function () {
    // A stale reason would read as an override that is no longer there — a claim about a decision
    // nobody made, on a document an auditor reads.
    $lease = makeLease(makeUnit(makeAsset()), makeTenant(), [
        'status' => 'active', 'commencement_date' => '2026-01-01', 'expiry_date' => '2026-12-31',
    ]);

    $this->actingAs(makeUser('accounting'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($lease->unit->asset);

    Livewire::test(CreateInvoice::class)
        ->fillForm([
            'lease_id' => $lease->id,
            'tenant_id' => $lease->tenant_id,
            'status' => 'draft',
            'issue_date' => '2026-03-01',
            'due_date' => '2026-03-08',
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
            'items' => [[
                'type' => 'service_charge',
                'tax_code' => 'VAT_14',
                'description' => 'Service charge',
                'amount' => 1000,
                'vat_rate' => 14,
                'tax_override_reason' => 'left over from an earlier edit',
            ]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Invoice::latest('id')->first()->items->sole()->tax_override_reason)->toBeNull();
});

it('resolves the line rate for the INVOICE date, not today', function () {
    // A back-dated invoice bills the regime that was in force when it was raised. Proved through
    // the save path, because that is where the rate is re-derived.
    TaxCatalogue::setStandardRate(14.0, '2017-07-01');
    TaxCatalogue::setRate(Vat::STANDARD_TAX_CODE, 25.0, '2026-06-01');

    $lease = makeLease(makeUnit(makeAsset()), makeTenant(), [
        'status' => 'active', 'commencement_date' => '2026-01-01', 'expiry_date' => '2026-12-31',
    ]);

    $this->actingAs(makeUser('manager'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($lease->unit->asset);

    Livewire::test(CreateInvoice::class)
        ->fillForm([
            'lease_id' => $lease->id,
            'tenant_id' => $lease->tenant_id,
            'status' => 'draft',
            'issue_date' => '2026-03-01',        // before the rise
            'due_date' => '2026-03-08',
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
            'items' => [[
                'type' => 'service_charge',
                'tax_code' => 'VAT_14',
                'description' => 'Service charge',
                'amount' => 1000,
                'vat_rate' => 25,                 // today's rate, submitted for a March document
            ]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect((float) Invoice::latest('id')->first()->items->sole()->vat_rate)->toBe(14.0);
});

it('leaves an unclassified line to the floor rather than inventing a rate for it', function () {
    // A charge code nobody has ruled on has no tax code, so there is no catalogue figure to correct
    // towards. `Vat`'s floor already decides what it bills; the save path must not overwrite that
    // with a zero just because the classification is absent.
    ChargeCode::create([
        'code' => 'key_money',
        'name_en' => 'Key money', 'name_ar' => 'خلو رجل',
        'posting_role' => 'misc_income',
    ]);
    ChargeCode::flushLookupCaches();

    $item = invoiceWithLine([
        'type' => 'key_money',
        'vat_rate' => Vat::rateForType('key_money', '2026-03-01'),
    ])->items->sole();

    expect($item->tax_code)->toBeNull()
        // …and the floor assumed it taxable rather than silently untaxed.
        ->and((float) $item->vat_rate)->toBe(Vat::standardRate('2026-03-01'));
});

it('keeps an issued invoice at the rate it was billed at when the catalogue moves', function () {
    $invoice = invoiceWithLine();
    $invoice->update(['status' => 'issued']);

    $billed = (float) $invoice->items->sole()->vat_rate;

    TaxCatalogue::setStandardRate(30.0);
    ChargeCode::where('code', 'service_charge')->update(['tax_code' => 'VAT_EXEMPT']);
    ChargeCode::flushLookupCaches();

    $item = $invoice->fresh('items')->items->sole();

    expect((float) $item->vat_rate)->toBe($billed)
        ->and($billed)->toBeGreaterThan(0.0)
        // …and the CLASSIFICATION is frozen too. Re-deriving it would relabel a filed document's
        // supply as exempt because a ruling changed afterwards.
        ->and($item->tax_code)->toBe('VAT_14')
        ->and(TaxCode::rateOn('VAT_14'))->toBe(30.0);
});
