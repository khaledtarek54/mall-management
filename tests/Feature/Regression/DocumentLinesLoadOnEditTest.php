<?php

use App\Filament\Admin\Resources\CreditNotes\Pages\EditCreditNote;
use App\Filament\Admin\Resources\Invoices\Pages\EditInvoice;
use App\Models\CreditNote;
use App\Models\CreditNoteItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * **The lines of a document must be there when the operator opens it.**
 *
 * WHY THIS EXISTS. The operator reported that the lines tab "sometimes doesn't load at all" on
 * resources that have one. Nothing in the suite asserted that a relationship-backed items repeater
 * actually HYDRATES on edit — every existing test drove actions on the page or asserted a saved
 * result, so a repeater that filled empty would have been green throughout.
 *
 * The branch worth pinning is the ISSUED invoice. `InvoiceForm`'s `$locked` disables the entire
 * items repeater once the invoice leaves draft, and Filament deliberately does not DEHYDRATE
 * disabled fields — so "disabled repeaters also fail to hydrate" is a plausible reading of the
 * framework that would empty the lines on exactly the invoices an operator opens most often, while
 * leaving drafts (the state most tests build) working perfectly. It hydrates; this records that,
 * so an upgrade that changes it is loud.
 *
 * It also guards a UX change made the same day: the totals moved OUT of their own tab and to the
 * foot of the lines tab, on both the invoice and the credit note. That edit reshapes the schema
 * around the repeater, and the failure it could cause is precisely "the lines stopped loading".
 *
 * **What this does NOT cover, stated rather than implied:** anything client-side. If the tab renders
 * blank in a browser while the state below is correct, the cause is Livewire/Alpine rendering and
 * lives beyond Pest — that needs the E2E suite pointed at the page.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->asset = makeAsset();
    $this->actingAs(makeUser('super_admin'));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/** An invoice carrying two lines — one exempt, one standard-rated, as a real one usually is. */
function linesLoadInvoice(string $status, $asset): Invoice
{
    // Header totals agree with the lines below — an invoice whose stored total contradicts its own
    // items would make the totals assertion meaningless (the form recomputes from the repeater).
    $invoice = makeInvoice(makeLease(makeUnit($asset)), [
        'status' => $status,
        'subtotal' => 15000, 'vat_amount' => 700, 'total' => 15700, 'balance' => 15700,
    ]);

    InvoiceItem::create([
        'invoice_id' => $invoice->id, 'type' => 'base_rent', 'description' => 'Line one',
        'amount' => 10000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 10000,
    ]);
    InvoiceItem::create([
        'invoice_id' => $invoice->id, 'type' => 'service_charge', 'description' => 'Line two',
        'amount' => 5000, 'vat_rate' => 14, 'vat_amount' => 700, 'total' => 5700,
    ]);

    return $invoice->fresh();
}

it('loads the lines of a DRAFT invoice, where the repeater is editable', function () {
    $invoice = linesLoadInvoice('draft', $this->asset);

    $state = Livewire::test(EditInvoice::class, ['record' => $invoice->getRouteKey()])
        ->assertOk()
        ->get('data')['items'] ?? null;

    expect($state)->toBeArray()->toHaveCount(2)
        ->and(collect($state)->pluck('description')->all())
        ->toContain('Line one')->toContain('Line two');
});

it('loads the lines of an ISSUED invoice, where the repeater is disabled', function () {
    $invoice = linesLoadInvoice('issued', $this->asset);

    $state = Livewire::test(EditInvoice::class, ['record' => $invoice->getRouteKey()])
        ->assertOk()
        ->get('data')['items'] ?? null;

    expect($state)->toBeArray()->toHaveCount(2)
        ->and(collect($state)->pluck('description')->all())
        ->toContain('Line one')->toContain('Line two');

    // The totals now live at the foot of this same tab, so they must hydrate with it — a total
    // that renders blank beside its lines is the same defect wearing different clothes.
    $data = Livewire::test(EditInvoice::class, ['record' => $invoice->getRouteKey()])->get('data');
    expect((float) $data['total'])->toBe(15700.0);
});

it('loads the lines of a credit note', function () {
    $invoice = linesLoadInvoice('issued', $this->asset);

    $note = CreditNote::create([
        'invoice_id' => $invoice->id, 'tenant_id' => $invoice->tenant_id, 'asset_id' => $this->asset->id,
        'status' => 'draft', 'issue_date' => '2026-02-05', 'reason' => 'goodwill',
        'subtotal' => 1000, 'vat_amount' => 140, 'total' => 1140, 'applied_amount' => 0,
    ]);
    CreditNoteItem::create([
        'credit_note_id' => $note->id, 'description' => 'Credit line', 
        'amount' => 1000, 'vat_rate' => 14, 'vat_amount' => 140, 'total' => 1140,
    ]);

    $state = Livewire::test(EditCreditNote::class, ['record' => $note->fresh()->getRouteKey()])
        ->assertOk()
        ->get('data')['items'] ?? null;

    expect($state)->toBeArray()->toHaveCount(1)
        ->and(collect($state)->pluck('description')->all())->toContain('Credit line');
});
