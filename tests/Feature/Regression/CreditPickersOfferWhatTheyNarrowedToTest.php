<?php

/*
|--------------------------------------------------------------------------
| A picker narrowed to one tenant must SHOW them (2026-08-25)
|--------------------------------------------------------------------------
| Found in the panel. "Apply to Invoice" on a credit note whose own `invoice_id` already named the
| invoice opened a dropdown showing NOTHING — over a tenant with exactly one open invoice, on the
| teaching dataset where there are two invoices in the entire database.
|
| `Invoice` is deliberately absent from `OptionDisplay::PRELOAD`, which is right for a portfolio
| holding thousands. It is wrong at a call site whose query has already narrowed to ONE tenant's
| OPEN invoices — a handful, bounded by the shape of the business, which is exactly the case
| CLAUDE.md says opts in per call site with `->preload()`.
|
| **An empty dropdown reads as "no such record", not as "type to search."** That is why it had never
| been reported: it is indistinguishable from the data being wrong, so the operator goes and checks
| the data instead. `CreditNoteForm` had already reached this conclusion and preloaded; the credit
| note's own apply modal, the payment allocation repeater and the post-dated cheque form had not, so
| the same picker behaved two different ways inside one module.
|
| Enumerated by grep across every `entity(Invoice::class)` call site, never from the one screen that
| was reported — this codebase's most repeated defect is fixing the instance you were shown.
*/

use App\Filament\Admin\Resources\CreditNotes\Pages\EditCreditNote;
use App\Models\CreditNote;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->seed(RolesPermissionsSeeder::class);

    $this->asset = makeAsset();
    $this->tenant = makeTenant();
    $this->lease = makeLease(makeUnit($this->asset), $this->tenant, ['status' => 'active']);

    $this->open = makeInvoice($this->lease, [
        'asset_id' => $this->asset->id, 'status' => 'issued',
        'total' => 5700, 'paid_amount' => 0, 'balance' => 5700,
    ]);

    $this->note = CreditNote::create([
        'tenant_id' => $this->tenant->id,
        'invoice_id' => $this->open->id,
        'asset_id' => $this->asset->id,
        'status' => 'issued',
        'issue_date' => now(),
        'subtotal' => 2000, 'total' => 2000,
        'applied_amount' => 0, 'balance' => 2000,
        'reason' => 'adjustment',
    ]);

    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/** The invoice picker inside the mounted "apply to invoice" modal, as Filament built it. */
function invoicePickerOn($component)
{
    $action = $component->instance()->getMountedAction();
    expect($action)->not->toBeNull('the apply modal did not mount');

    // Filament builds the modal's schema itself — this is the same call its own mount path makes,
    // so what the test reads is what the operator is shown.
    // The PAGE owns the mounted modal's schema, under a name Filament reports — asking the page
    // for it is the same object the modal renders from, so this reads what the operator sees.
    $page = $component->instance();

    return $page->getSchema($page->getMountedActionSchemaName())->getComponent('invoice_id');
}

it('offers the tenant open invoice without anyone typing a search term', function () {
    // The whole defect in one assertion. Building the action proves nothing — the options are
    // resolved when the modal MOUNTS, so this drives the real page.
    $component = Livewire::test(EditCreditNote::class, ['record' => $this->note->getKey()])
        ->mountAction('apply');

    $field = invoicePickerOn($component);

    expect($field->isPreloaded())->toBeTrue()
        ->and($field->getOptions())->toHaveKey($this->open->id);
});

it('opens on the invoice the note already names', function () {
    $component = Livewire::test(EditCreditNote::class, ['record' => $this->note->getKey()])
        ->mountAction('apply');

    // Applying a credit to the invoice it was raised against is overwhelmingly the case, so it is
    // one click. Offered, never forced.
    expect(invoicePickerOn($component)->getState())->toBe($this->open->id);
});

it('opens BLANK when that invoice is no longer applicable', function () {
    // The default is re-checked against the picker's own query. A note pointing at a settled
    // invoice must not pre-fill a value the picker would then refuse at validation — Filament
    // resolves a Select by labelling the submitted value, so an unofferable default is a form that
    // will not submit and does not say why.
    $this->open->update(['status' => 'paid', 'paid_amount' => 5700, 'balance' => 0]);

    $component = Livewire::test(EditCreditNote::class, ['record' => $this->note->fresh()->getKey()])
        ->mountAction('apply');

    expect(invoicePickerOn($component)->getState())->toBeNull();
});

it('never offers another tenant invoice', function () {
    $other = makeTenant();
    $otherLease = makeLease(makeUnit($this->asset), $other, ['status' => 'active']);
    $otherInvoice = makeInvoice($otherLease, [
        'asset_id' => $this->asset->id, 'status' => 'issued',
        'total' => 9000, 'paid_amount' => 0, 'balance' => 9000,
    ]);

    // Preloading widens what is SHOWN and must not widen what is REACHABLE. Paired with the first
    // test deliberately: an assertion that the list is non-empty passes just as happily on a list
    // that offers everyone.
    $component = Livewire::test(EditCreditNote::class, ['record' => $this->note->getKey()])
        ->mountAction('apply');

    $options = invoicePickerOn($component)->getOptions();

    expect($options)->toHaveKey($this->open->id)
        ->and($options)->not->toHaveKey($otherInvoice->id);
});
