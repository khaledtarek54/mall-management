<?php

use App\Filament\Admin\Resources\Invoices\Pages\EditInvoice;
use App\Filament\Admin\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Admin\Resources\Tenants\Pages\EditTenant;
use App\Filament\Admin\RelationManagers\TenantInvoicesRelationManager;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\CreditNoteService;
use App\Services\DisputeInvoiceItemService;
use App\Services\VoidInvoiceService;
use App\Services\WriteOffInvoiceService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

/**
 * THE BUTTON'S `visible()` AND THE SERVICE'S GUARD READ ONE PREDICATE.
 *
 * Fourteen buttons in the panel restated a status rule as a literal that the service beside them
 * held as another literal (measured 2026-09-12). On the invoice — the record with the most doors
 * and the one register for the question (`InvoiceSettlement`) — three had drifted or could:
 *
 *   - *Void* carried an ALLOWLIST (`draft | issued | overdue`) beside `VoidInvoiceService`'s
 *     DENYLIST (`cancelled | credited | written_off`), under a comment claiming the two could
 *     not drift. An invoice settled entirely by a credit note — no captured cash — was voidable by
 *     the service and had no button; so was a disputed one.
 *   - *Write off* read `balance > 0` and a three-status list; a write-off leaves `balance`
 *     standing, so an invoice with its forgiven remainder all that was left was still OFFERED.
 *   - The credit-note apply picker offered by raw balance and a three-status allowlist what the
 *     service then refused; the cheque picker omitted `disputed`, which a tenant may pay.
 *
 * `Invoice::voidBlockedBecause()`, `isPayable()`, `canDisputeLines()`, `creditable()` and
 * `stillOwed()` are the predicates. The void, write-off and dispute cases drive the BUTTON and the
 * SERVICE and assert they agree, with the control where both must accept. The two picker cases
 * and the Overdue tab assert the SCOPE the door is built from, not the mounted modal — the door's
 * own wiring to that scope is pinned by `AnInvoiceDoorReadsTheModelsPredicateConformanceTest`,
 * which refuses a literal status list in its place.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    ensureAllPropertiesAsset();

    $this->asset = makeAsset();
    $this->tenant = makeTenant();
    $this->lease = makeLease(makeUnit($this->asset), $this->tenant, ['status' => 'active']);
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
});

function invoiceOwing(array $attrs = []): Invoice
{
    return makeInvoice(test()->lease, array_merge([
        'status' => 'issued', 'subtotal' => 10000, 'vat_amount' => 0, 'total' => 10000,
        'paid_amount' => 0, 'balance' => 10000,
        'issue_date' => CarbonImmutable::now()->subMonth(),
        'due_date' => CarbonImmutable::now()->subWeek(),
    ], $attrs));
}

/** Settle the whole invoice with a credit note — money that REVERSES on cancel, not cash. */
function settledByCredit(Invoice $invoice): Invoice
{
    $note = CreditNote::create([
        'number' => 'CN-'.uniqid(), 'tenant_id' => $invoice->tenant_id, 'lease_id' => $invoice->lease_id,
        'status' => 'issued', 'issue_date' => now(), 'reason' => 'adjustment',
        'subtotal' => 10000, 'vat_amount' => 0, 'total' => 10000,
        'applied_amount' => 0, 'balance' => 10000, 'currency' => 'EGP',
    ]);
    app(CreditNoteService::class)->applyToInvoice($note, $invoice->fresh());

    return $invoice->fresh();
}

/** Settle part of the invoice with captured CASH. */
function partlyPaidInCash(Invoice $invoice, float $amount): Invoice
{
    $payment = Payment::factory()->create(['tenant_id' => $invoice->tenant_id, 'amount' => $amount, 'status' => 'captured']);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => $amount]);
    $invoice->recomputeTotals();

    return $invoice->fresh();
}

function editPage(Invoice $invoice)
{
    return asTenant(test()->asset, fn () => Livewire::test(EditInvoice::class, ['record' => $invoice->getRouteKey()]));
}

it('offers VOID on an invoice settled entirely by credit, and the service voids it', function () {
    $invoice = settledByCredit(invoiceOwing());

    // The premise the old allowlist hid behind: `paid`, and no cash to refund.
    expect($invoice->status)->toBe('paid')
        ->and($invoice->capturedCashPaid())->toBe(0.0)
        ->and($invoice->voidBlockedBecause())->toBeNull();

    editPage($invoice)->assertActionVisible('void_invoice');

    app(VoidInvoiceService::class)->void($invoice->fresh(), 'raised in error');

    expect($invoice->fresh()->status)->toBe('cancelled');
});

it('hides VOID where captured cash stands, and the service refuses for the same reason', function () {
    $invoice = partlyPaidInCash(invoiceOwing(), 4000);

    expect($invoice->voidBlockedBecause())->toBe('has_cash');

    editPage($invoice)->assertActionHidden('void_invoice');

    expect(fn () => app(VoidInvoiceService::class)->void($invoice->fresh(), 'x'))
        ->toThrow(DomainException::class);
});

it('hides WRITE OFF once the forgiven remainder is all that stands, and the service refuses', function () {
    $invoice = invoiceOwing();

    app(WriteOffInvoiceService::class)->write($invoice->fresh(), [
        'amount' => 6000, 'reason' => 'settled_short', 'write_off_date' => now()->toDateString(),
    ]);
    $invoice = partlyPaidInCash($invoice->fresh(), 4000);

    // Raw balance 6,000 — what the old button read — and nothing left to forgive.
    expect(round((float) $invoice->balance, 2))->toEqual(6000.0)
        ->and($invoice->isPayable())->toBeFalse();

    editPage($invoice)->assertActionHidden('write_off');

    expect(fn () => app(WriteOffInvoiceService::class)->write($invoice->fresh(), [
        'amount' => 1000, 'reason' => 'settled_short', 'write_off_date' => now()->toDateString(),
    ]))->toThrow(DomainException::class);
});

it('offers WRITE OFF on an open invoice — the control', function () {
    $invoice = invoiceOwing();

    expect($invoice->isPayable())->toBeTrue();

    editPage($invoice)->assertActionVisible('write_off');
});

it('offers and accepts DISPUTE A LINE on the same invoices', function () {
    $open = invoiceOwing();
    $gone = invoiceOwing(['status' => 'written_off']);

    expect($open->canDisputeLines())->toBeTrue()
        ->and($gone->canDisputeLines())->toBeFalse();

    editPage($open)->assertActionVisible('disputeLine');
    editPage($gone)->assertActionHidden('disputeLine');

    // A line is disputed, not the header — build one on the written-off document. Its `saved`
    // hook recomputes the header; `written_off` is in the override list, so the status stands.
    $line = $gone->items()->create(['description' => 'Service charge', 'type' => 'service_charge', 'amount' => 1000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 1000]);
    expect($gone->fresh()->status)->toBe('written_off');

    expect(fn () => app(DisputeInvoiceItemService::class)->dispute($line->fresh(), 'why'))
        ->toThrow(DomainException::class);

    // The control: the same act on the open invoice's line is accepted.
    $openLine = $open->items()->create(['description' => 'Service charge', 'type' => 'service_charge', 'amount' => 1000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 1000]);

    expect(app(DisputeInvoiceItemService::class)->dispute($openLine->fresh(), 'why')->disputed_at)->not->toBeNull();
});

it('scopes the credit-note apply picker to what the service will accept', function () {
    $open = invoiceOwing();
    $disputed = invoiceOwing(['status' => 'disputed']);
    $paidByCredit = settledByCredit(invoiceOwing());

    $forgiven = invoiceOwing();
    app(WriteOffInvoiceService::class)->write($forgiven->fresh(), [
        'amount' => 6000, 'reason' => 'settled_short', 'write_off_date' => now()->toDateString(),
    ]);
    $forgiven = partlyPaidInCash($forgiven->fresh(), 4000);

    $offered = Invoice::query()->where('tenant_id', $this->tenant->id)->creditable()->pluck('id')->all();

    expect($offered)->toContain($open->id)
        ->not->toContain($disputed->id)
        ->not->toContain($paidByCredit->id)
        // Raw balance 6,000 and nothing collectable: the old picker offered it.
        ->not->toContain($forgiven->id);
});

it('lets a cheque be lodged against a disputed invoice — a tenant may pay one', function () {
    $disputed = invoiceOwing(['status' => 'disputed']);

    expect(Invoice::query()->where('tenant_id', $this->tenant->id)->stillOwed()->pluck('id')->all())
        ->toContain($disputed->id);
});

it('lists a part-paid invoice past its due date under the register\'s OVERDUE tab', function () {
    // The register's tabs read the STORED `overdue` stamp — a projection swept nightly that a
    // `partially_paid` invoice can never carry — so the AR clerk's own worklist under-reported
    // what every other collections surface (the tenant tab, the worklist, the app) computes
    // through `scopeOverdue()`. Both tabs read the scopes now; badge and rows share the query.
    $invoice = partlyPaidInCash(invoiceOwing(), 4000);

    expect($invoice->status)->toBe('partially_paid')
        ->and($invoice->isOverdue())->toBeTrue();

    $list = asTenant($this->asset, fn () => Livewire::test(ListInvoices::class)->set('activeTab', 'overdue'));

    $list->assertCanSeeTableRecords([$invoice]);
});

it('captions the tenant tab\'s due date "N days overdue" only while something is still owed', function () {
    // The tab's own spelling — `balance > 0 && due_date->isPast()` — was the ninth copy of
    // `isOverdue()` (the review found it after both registers were converted): a raw balance
    // cannot see a write-off, so the forgiven remainder read as "N days overdue" on the tenant
    // hub while the register beside it, and the chase sweeps, had stopped counting it.
    $forgiven = invoiceOwing();
    app(WriteOffInvoiceService::class)->write($forgiven->fresh(), [
        'amount' => 6000, 'reason' => 'settled_short', 'write_off_date' => now()->toDateString(),
    ]);
    $forgiven = partlyPaidInCash($forgiven->fresh(), 4000);
    $owed = invoiceOwing();

    expect(round((float) $forgiven->balance, 2))->toEqual(6000.0)
        ->and($forgiven->due_date->isPast())->toBeTrue();

    $column = asTenant($this->asset, fn () => Livewire::test(TenantInvoicesRelationManager::class, ['ownerRecord' => $this->tenant, 'pageClass' => EditTenant::class]))
        ->instance()->getTable()->getColumn('due_date');

    expect((string) $column->record($forgiven->fresh())->getDescriptionBelow())->toBe('')
        ->and((string) $column->record($owed->fresh())->getDescriptionBelow())->toContain('7');
});
