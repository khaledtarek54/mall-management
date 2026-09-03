<?php

use App\Filament\Admin\Resources\CreditNotes\Pages\EditCreditNote;
use App\Filament\Admin\Resources\Invoices\Pages\EditInvoice;
use App\Filament\Admin\Resources\Leases\Pages\EditLease;
use App\Filament\Admin\Resources\MarketingBudgets\Pages\EditMarketingBudget;
use App\Models\CreditNote;
use App\Models\MarketingBudget;
use App\Models\Payment;
use App\Services\Accounting\FiscalCalendar;
use App\Support\Filament\RecordChanged;
use App\Support\Filament\RefreshesRecordState;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * A record page must show what the DATABASE says after an action, not what it said before.
 *
 * Filament's `refreshFormData()` refills from the page's IN-MEMORY record and never re-reads —
 * so it was a no-op behind every money service, because they all re-read the row under
 * `lockForUpdate()` into a new instance (they must: a guard behind a lock has to be a locking
 * read). Nineteen call sites across ten pages called it and none of them did anything. The form
 * kept the pre-action figures under a success toast, which is the worst way to be wrong: nobody
 * re-checks a number the system just printed for them.
 *
 * These tests assert the FORM STATE, not the row. Asserting `$invoice->fresh()->status` is what
 * every existing test did, and it passed throughout.
 *
 * @see RefreshesRecordState
 * @see RecordChanged
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->asset = makeAsset();
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('shows the voided invoice as cancelled with a zero balance, without a page refresh', function () {
    $invoice = makeInvoice(makeLease(makeUnit($this->asset)), [
        'issue_date' => now()->toDateString(),
        'status' => 'issued',
        'subtotal' => 10000, 'vat_amount' => 0, 'total' => 10000, 'balance' => 10000,
    ]);

    $state = Livewire::test(EditInvoice::class, ['record' => $invoice->getRouteKey()])
        ->callAction('void_invoice', ['reason' => 'entered in error'])
        ->assertHasNoActionErrors()
        ->get('data');

    // The row is right — it always was. The question is what the operator is looking at.
    expect($invoice->fresh()->status)->toBe('cancelled');

    expect($state['status'])->toBe('cancelled')
        ->and((float) $state['balance'])->toBe(0.0);
});

it('shows the written-down balance after a credit is applied, without a page refresh', function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    $lease = makeLease(makeUnit($this->asset));
    $invoice = makeInvoice($lease, [
        'issue_date' => now()->toDateString(), 'status' => 'issued',
        'subtotal' => 10000, 'vat_amount' => 0, 'total' => 10000, 'balance' => 10000,
    ]);
    $note = CreditNote::create([
        'tenant_id' => $lease->tenant_id, 'asset_id' => $this->asset->id,
        'issue_date' => now()->toDateString(), 'status' => 'issued', 'reason' => 'discount',
        'subtotal' => 4000, 'vat_amount' => 0, 'total' => 4000, 'balance' => 4000,
    ]);

    // Applied from the CREDIT NOTE's page — the note's own figures must move on its own form.
    $state = Livewire::test(EditCreditNote::class, ['record' => $note->getRouteKey()])
        ->callAction('apply', ['invoice_id' => $invoice->id, 'amount' => 4000])
        ->assertHasNoActionErrors()
        ->get('data');

    expect((float) $note->fresh()->balance)->toBe(0.0)
        ->and((float) $state['balance'])->toBe(0.0)
        ->and((float) $state['applied_amount'])->toBe(4000.0);
});

it('re-reads the record when another component on the screen announces a change', function () {
    $lease = makeLease(makeUnit($this->asset));
    $invoice = makeInvoice($lease, [
        'issue_date' => now()->toDateString(), 'status' => 'issued',
        'subtotal' => 5000, 'vat_amount' => 0, 'total' => 5000, 'balance' => 5000,
    ]);

    $page = Livewire::test(EditInvoice::class, ['record' => $invoice->getRouteKey()]);
    expect((float) $page->get('data')['balance'])->toBe(5000.0);

    // A payment recorded somewhere else on the screen (its own Livewire component) settles it.
    $payment = Payment::create([
        'reference' => 'P-'.uniqid(), 'tenant_id' => $lease->tenant_id, 'amount' => 5000,
        'method' => 'bank_transfer', 'status' => 'captured', 'payment_date' => now()->toDateString(),
    ]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => 5000]);
    $invoice->recomputeTotals();

    // Without the announcement the page has no way to know — that is the whole defect.
    $state = $page->dispatch(RecordChanged::EVENT)->get('data');

    expect((float) $state['balance'])->toBe(0.0)
        ->and((float) $state['paid_amount'])->toBe(5000.0)
        ->and($state['status'])->toBe('paid');
});

it('does not discard an operator edit in progress when it refreshes derived fields', function () {
    $invoice = makeInvoice(makeLease(makeUnit($this->asset)), [
        'issue_date' => now()->toDateString(), 'status' => 'draft',
        'subtotal' => 5000, 'vat_amount' => 0, 'total' => 5000, 'balance' => 5000,
        'notes' => 'original',
    ]);

    // Only DERIVED paths are refilled, so a field the operator is typing survives the refresh.
    $state = Livewire::test(EditInvoice::class, ['record' => $invoice->getRouteKey()])
        ->set('data.notes', 'half-typed note')
        ->dispatch(RecordChanged::EVENT)
        ->get('data');

    expect($state['notes'])->toBe('half-typed note');
});

it('refreshes the lease form when a commercial action changes the rent behind its back', function () {
    $lease = makeLease(makeUnit($this->asset), null, ['base_rent_monthly' => 10000, 'status' => 'active']);

    $page = Livewire::test(EditLease::class, ['record' => $lease->getRouteKey()]);
    expect((float) $page->get('data')['base_rent_monthly'])->toBe(10000.0);

    // Exactly what LeaseRentChangeService does: it re-reads the lease under a lock and writes to
    // THAT instance, so the page's copy never learns. This is the shape the whole fix is about.
    $lease->newQuery()->whereKey($lease->id)->update(['base_rent_monthly' => 12500, 'status' => 'terminated']);

    $state = $page->dispatch(RecordChanged::EVENT)->get('data');

    expect((float) $state['base_rent_monthly'])->toBe(12500.0)
        ->and($state['status'])->toBe('terminated');
});

it('announces through the action seam, so a widget or sibling knows to re-read', function () {
    $invoice = makeInvoice(makeLease(makeUnit($this->asset)), [
        'issue_date' => now()->toDateString(), 'status' => 'issued',
        'subtotal' => 5000, 'vat_amount' => 0, 'total' => 5000, 'balance' => 5000,
    ]);

    // Every `Action::make()` resolves to AuthorizedAction, which announces after it runs — that is
    // what reaches the components the acting one cannot see (header widgets, sibling managers).
    Livewire::test(EditInvoice::class, ['record' => $invoice->getRouteKey()])
        ->callAction('void_invoice', ['reason' => 'entered in error'])
        ->assertDispatched(RecordChanged::EVENT);
});

it('stays quiet when the action only handed over a file', function () {
    $invoice = makeInvoice(makeLease(makeUnit($this->asset)), [
        'issue_date' => now()->toDateString(), 'status' => 'issued',
        'subtotal' => 5000, 'vat_amount' => 0, 'total' => 5000, 'balance' => 5000,
    ]);
    $invoice->items()->create(['type' => 'base_rent', 'description' => 'Rent', 'amount' => 5000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 5000]);

    // A download changed nothing on screen. Announcing would cost every listening component a
    // pointless re-render, so the seam tests the RETURN VALUE rather than a list of action names.
    Livewire::test(EditInvoice::class, ['record' => $invoice->getRouteKey()])
        ->callAction('downloadPdf')
        ->assertNotDispatched(RecordChanged::EVENT);
});

it('re-reads a record whose figures are rendered by state closures, not form state', function () {
    // The marketing budget's Fund section is three `TextEntry->state(fn ($record) => …)` — they
    // resolve from the RECORD at render time and are bound to no state path, so refilling form
    // state would do nothing for them and re-reading the record is the entire fix. Every spend is
    // created in the relation manager below, a different Livewire component from this form.
    $budget = MarketingBudget::create([
        'asset_id' => $this->asset->id, 'period_year' => (int) now()->year,
        'accrued_amount' => 100000, 'spent_amount' => 0, 'status' => 'open',
    ]);

    $page = Livewire::test(EditMarketingBudget::class, ['record' => $budget->getRouteKey()]);
    $page->assertSee('100,000.00');

    $budget->newQuery()->whereKey($budget->id)->update(['spent_amount' => 40000]);

    $page->dispatch(RecordChanged::EVENT)->assertSee('40,000.00')->assertSee('60,000.00');
});
