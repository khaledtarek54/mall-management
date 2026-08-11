<?php

use App\Filament\Admin\Resources\Invoices\Pages\CreateInvoice;
use App\Filament\Admin\Resources\Invoices\Pages\EditInvoice;
use App\Models\Invoice;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * An invoice's header (subtotal / vat_amount / total) must equal the sum of its line items.
 *
 * THE BUG (validation sweep — receivables, 2026-08-11). The tie-out lived at LAYER 3 ONLY: the
 * `InvoiceForm` recomputes the header in `afterStateUpdated` and renders the three fields
 * `readOnly()`. `readOnly` is an HTML attribute — Livewire still accepts whatever the client
 * sends for those keys, and they are `dehydrated()`, so the submitted value is what persists.
 * Nothing at the model or service layer re-derived the header from the items.
 *
 * Why it is money and not cosmetics: `InvoiceJournalizer` debits AR with the HEADER total and
 * credits revenue from the ITEM amounts (split by charge type) plus item VAT. Divergence means
 * the journal entry's two sides are computed from two different numbers — AR is overstated or
 * understated against revenue, and `Invoice::recomputeTotals()` derives `balance` from the same
 * header, so the tenant is chased for an amount no line item supports.
 *
 * The fix promotes the rule to LAYER 1 (`Invoice::syncTotalsFromItems()`, called from the item
 * write hooks and from the invoice's own `saving`), so every writer obeys it — form, API, import,
 * console, tinker. The form keeps its live recompute for the inline UX.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);

    $this->asset = makeAsset(['code' => 'TIE1']);
    $this->unit = makeUnit($this->asset);
    $this->lease = makeLease($this->unit);

    $this->actingAs(makeUser('manager', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/** The header the form would have computed, and the header a tampered client submits instead. */
function tiedInvoicePayload(int $leaseId, int $tenantId, array $headerOverride = []): array
{
    return array_merge([
        'lease_id' => $leaseId,
        'tenant_id' => $tenantId,
        'status' => 'draft',
        'issue_date' => '2026-02-01',
        'due_date' => '2026-02-10',
        'period_start' => '2026-02-01',
        'period_end' => '2026-02-28',
        'items' => [
            [
                'type' => 'base_rent',
                'description' => 'February base rent',
                'amount' => 10000,
                'vat_rate' => 0,
                'total' => 10000,
            ],
            [
                'type' => 'service_charge',
                'description' => 'February service charge',
                'amount' => 2000,
                'vat_rate' => 14,
                'total' => 2280,
            ],
        ],
        // What the live form would have set: 12,000 + 280 VAT = 12,280.
        'subtotal' => 12000,
        'vat_amount' => 280,
        'total' => 12280,
        'balance' => 12280,
    ], $headerOverride);
}

it('creating an invoice with a tampered header re-derives the header from the items', function () {
    Livewire::test(CreateInvoice::class)
        ->fillForm(tiedInvoicePayload($this->lease->id, $this->lease->tenant_id, [
            // The exploit: the client keeps the two 12,280-worth of items but submits a header
            // of 1. Before the fix this persisted verbatim.
            'subtotal' => 1,
            'vat_amount' => 0,
            'total' => 1,
            'balance' => 1,
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    $invoice = Invoice::latest('id')->first();

    expect($invoice)->not->toBeNull()
        ->and(round((float) $invoice->subtotal, 2))->toBe(12000.00)
        ->and(round((float) $invoice->vat_amount, 2))->toBe(280.00)
        ->and(round((float) $invoice->total, 2))->toBe(12280.00)
        ->and(round((float) $invoice->balance, 2))->toBe(12280.00);

    // The control: the items really were written, so the assertion above is not passing
    // because nothing was created.
    expect($invoice->items)->toHaveCount(2)
        ->and(round((float) $invoice->items->sum('amount'), 2))->toBe(12000.00);
});

it('the untampered create still stores exactly what the form computed', function () {
    Livewire::test(CreateInvoice::class)
        ->fillForm(tiedInvoicePayload($this->lease->id, $this->lease->tenant_id))
        ->call('create')
        ->assertHasNoFormErrors();

    $invoice = Invoice::latest('id')->first();

    expect(round((float) $invoice->total, 2))->toBe(12280.00)
        ->and(round((float) $invoice->subtotal, 2))->toBe(12000.00)
        ->and(round((float) $invoice->vat_amount, 2))->toBe(280.00);
});

it('editing a DRAFT invoice cannot desync the header from the items', function () {
    $invoice = makeInvoice($this->lease, ['status' => 'draft']);
    $invoice->items()->create([
        'type' => 'base_rent', 'description' => 'Rent', 'amount' => 10000, 'vat_rate' => 0,
    ]);
    $invoice->items()->create([
        'type' => 'service_charge', 'description' => 'Service', 'amount' => 2000, 'vat_rate' => 14,
    ]);

    Livewire::test(EditInvoice::class, ['record' => $invoice->getRouteKey()])
        ->fillForm(['subtotal' => 5, 'vat_amount' => 0, 'total' => 5])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(round((float) $invoice->fresh()->total, 2))->toBe(12280.00);
});

it('a direct model write (API / import / console) is held to the same rule', function () {
    $invoice = makeInvoice($this->lease, ['status' => 'draft', 'subtotal' => 0, 'vat_amount' => 0, 'total' => 0, 'balance' => 0]);
    $invoice->items()->create([
        'type' => 'base_rent', 'description' => 'Rent', 'amount' => 7500, 'vat_rate' => 0,
    ]);

    // The item write alone must carry the header with it — no form involved.
    expect(round((float) $invoice->fresh()->total, 2))->toBe(7500.00);

    // And a later attempt to move the header away from the items is re-derived, not honoured.
    $invoice->fresh()->update(['total' => 999999, 'subtotal' => 999999]);

    expect(round((float) $invoice->fresh()->total, 2))->toBe(7500.00);
});

it('deleting an item pulls the header down with it', function () {
    $invoice = makeInvoice($this->lease, ['status' => 'draft']);
    $invoice->items()->create(['type' => 'base_rent', 'description' => 'Rent', 'amount' => 10000, 'vat_rate' => 0]);
    $extra = $invoice->items()->create(['type' => 'parking', 'description' => 'Parking', 'amount' => 500, 'vat_rate' => 14]);

    expect(round((float) $invoice->fresh()->total, 2))->toBe(10570.00);

    $extra->delete();

    expect(round((float) $invoice->fresh()->total, 2))->toBe(10000.00);
});

it('a header-only invoice with no items keeps its header (legacy / opening-balance data)', function () {
    // The rule is "items must sum to the header", not "an invoice must have items". An invoice
    // with no lines has nothing to derive from — zeroing it would erase legacy AR.
    $invoice = makeInvoice($this->lease); // 10,000 + 1,400 = 11,400, no items

    expect(round((float) $invoice->fresh()->total, 2))->toBe(11400.00);

    $invoice->update(['notes' => 'touched']);

    expect(round((float) $invoice->fresh()->total, 2))->toBe(11400.00);
});
