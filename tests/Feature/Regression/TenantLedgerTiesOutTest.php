<?php

/*
|--------------------------------------------------------------------------
| The tenant ledger, and the one property that makes it trustworthy (2026-08-18)
|--------------------------------------------------------------------------
| "What does this tenant owe, and how did it get there?" had exactly one complete answer and it was
| a PDF you had to download. On screen the halves sat in separate tabs — invoices in one, payments
| in another — with nothing netting them and no order between them, so an operator on a collections
| call held both in their head and did the subtraction themselves. Yardi answers it with a ledger.
|
| Nothing is stored. Every row is derived from the documents, and the CLOSING BALANCE must equal the
| sum of open invoice balances — the same figure the statement, the AR report and `billing:reconcile`
| produce. That equality is the whole test: a ledger that lists movements but lands on a different
| number is worse than no ledger, because it looks authoritative.
*/

use App\Filament\Admin\RelationManagers\TenantLedgerRelationManager;
use App\Filament\Admin\Resources\Tenants\Pages\EditTenant;
use App\Models\CreditNote;
use App\Models\DepositApplication;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\TenantLedger;
use Database\Seeders\RolesPermissionsSeeder;

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->tenant = makeTenant();
    $this->lease = makeLease(makeUnit($this->asset), $this->tenant, ['status' => 'active']);
});

function ledgerInvoice($ctx, float $total, string $issued): Invoice
{
    return makeInvoice($ctx->lease, [
        'asset_id' => $ctx->asset->id, 'status' => 'issued',
        'issue_date' => $issued, 'total' => $total, 'balance' => $total, 'paid_amount' => 0,
    ]);
}

function ledgerPayment($ctx, Invoice $invoice, float $amount, string $on): Payment
{
    $payment = Payment::create([
        'tenant_id' => $ctx->tenant->id, 'amount' => $amount, 'method' => 'cash',
        'status' => 'captured', 'payment_date' => $on, 'currency' => 'EGP',
    ]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => $amount]);
    $payment->recomputeAllocatedInvoices();

    return $payment;
}

it('closes on exactly what the invoices say is outstanding', function () {
    $a = ledgerInvoice($this, 100000, '2026-01-01');
    ledgerInvoice($this, 60000, '2026-02-01');
    ledgerPayment($this, $a, 40000, '2026-01-20');

    // THE assertion. A ledger that lands on a different number than the invoices is worse than none.
    expect(TenantLedger::closingBalance($this->tenant))
        ->toBe(round((float) Invoice::where('tenant_id', $this->tenant->id)->sum('balance'), 2))
        ->toBe(120000.0);
});

it('nets all four settlement channels, not just cash', function () {
    $invoice = ledgerInvoice($this, 200000, '2026-01-01');

    ledgerPayment($this, $invoice, 50000, '2026-01-10');

    CreditNote::create([
        'tenant_id' => $this->tenant->id, 'invoice_id' => $invoice->id, 'asset_id' => $this->asset->id,
        'status' => 'applied', 'issue_date' => '2026-01-15', 'subtotal' => 30000, 'total' => 30000,
        'applied_amount' => 30000, 'balance' => 0, 'reason' => 'adjustment',
    ]);
    // Persisted the way `CreditNoteService` does — quietly. `update()` routes through
    // `Invoice::saving`, which now reverts a dirty `credit_applied_amount` as a client payload.
    $invoice->credit_applied_amount = 30000;
    $invoice->saveQuietly();

    DepositApplication::create([
        'lease_id' => $this->lease->id, 'tenant_id' => $this->tenant->id, 'invoice_id' => $invoice->id,
        'amount' => 20000, 'entry_date' => '2026-01-20',
    ]);
    $invoice->refresh()->recomputeTotals();

    $types = TenantLedger::for($this->tenant)->pluck('type');

    // A ledger that omitted a non-cash channel would still list movements and stop tying out.
    expect($types)->toContain('invoice', 'payment', 'credit_note', 'deposit')
        ->and(TenantLedger::closingBalance($this->tenant))
        ->toBe(round((float) $invoice->fresh()->balance, 2));
});

it('runs oldest first, and shows a charge before a settlement made the same day', function () {
    $invoice = ledgerInvoice($this, 50000, '2026-03-01');
    ledgerPayment($this, $invoice, 50000, '2026-03-01');

    $rows = TenantLedger::for($this->tenant);

    // Same-day tie broken on the charge: the other order dips the balance negative on its way to
    // the same answer, which reads as an error to anyone scanning the column.
    expect($rows->first()['type'])->toBe('invoice')
        ->and($rows->first()['balance'])->toBe(50000.0)
        ->and($rows->last()['balance'])->toBe(0.0);
});

it('leaves out documents that claim nothing', function () {
    ledgerInvoice($this, 100000, '2026-01-01');

    // A draft is not a document the tenant has ever seen; a cancelled one claims nothing. Listing
    // either would show a debt this tenant does not have.
    makeInvoice($this->lease, ['asset_id' => $this->asset->id, 'status' => 'draft', 'total' => 999, 'balance' => 999]);
    makeInvoice($this->lease, ['asset_id' => $this->asset->id, 'status' => 'cancelled', 'total' => 888, 'balance' => 0]);

    expect(TenantLedger::for($this->tenant))->toHaveCount(1)
        ->and(TenantLedger::closingBalance($this->tenant))->toBe(100000.0);
});

it('counts only the part of a payment that landed on THIS tenant', function () {
    $mine = ledgerInvoice($this, 100000, '2026-01-01');

    $otherTenant = makeTenant();
    $otherLease = makeLease(makeUnit($this->asset), $otherTenant, ['status' => 'active']);
    $theirs = makeInvoice($otherLease, ['asset_id' => $this->asset->id, 'status' => 'issued',
        'total' => 70000, 'balance' => 70000, 'paid_amount' => 0]);

    // One payment settling two tenants' invoices. Only my share may reduce my balance.
    $payment = Payment::create([
        'tenant_id' => $this->tenant->id, 'amount' => 90000, 'method' => 'bank_transfer',
        'status' => 'captured', 'payment_date' => '2026-01-10', 'currency' => 'EGP',
    ]);
    $payment->invoices()->attach($mine->id, ['allocated_amount' => 40000]);
    $payment->invoices()->attach($theirs->id, ['allocated_amount' => 50000]);
    $payment->recomputeAllocatedInvoices();

    expect(TenantLedger::closingBalance($this->tenant))->toBe(60000.0);
});

it('mounts the ledger tab', function () {
    // The rows are ARRAYS, not models — a relation manager wires `recordAction`/`recordUrl` typed
    // `Model $record`, so leaving either set is a fatal at render and never a failing unit test.
    $this->seed(RolesPermissionsSeeder::class);
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('admin'));
    Filament\Facades\Filament::setTenant($this->asset);

    ledgerInvoice($this, 100000, '2026-01-01');

    Livewire\Livewire::test(TenantLedgerRelationManager::class, [
        'ownerRecord' => $this->tenant,
        'pageClass' => EditTenant::class,
    ])->assertOk();

    Filament\Facades\Filament::setTenant(null, isQuiet: true);
});
