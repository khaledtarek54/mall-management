<?php

/*
|--------------------------------------------------------------------------
| A deposit already collected is not still outstanding (SW-055)
|--------------------------------------------------------------------------
| The leases list carried two answers to one question on the same page. The `deposit_shortfall`
| COLUMN asked `Lease::depositShortfall()`. The "Deposit outstanding" FILTER re-expressed the pot as
| one `whereRaw`: receipts less refunds and forfeits from `deposit_transactions`, less
| `deposit_applications`. Three of the four terms.
|
| The missing one is `settledDepositBillings()` — the deposit BILLED on an invoice and since paid —
| and it is the ORDINARY path: `BillSecurityDepositService` raises the deposit on its own invoice
| and writes no `deposit_transactions` row at all. So the most common way a deposit is collected was
| exactly the way the filter could not see.
|
| Four leases here, and the two controls matter as much as the refusal: a filter that returned
| nothing would satisfy "the collected one is not listed" on its own, and a fix that forgot the CASH
| receipt term would pass the headline assertion while breaking the half that already worked.
*/

use App\Filament\Admin\Resources\Leases\Pages\ListLeases;
use App\Models\DepositTransaction;
use App\Models\Lease;
use App\Models\Payment;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    $this->seed(RolesPermissionsSeeder::class);

    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));

    $lease = fn () => makeLease(makeUnit($this->asset), null, [
        'status' => 'active',
        'security_deposit' => 60000,
    ]);

    $depositInvoiceFor = function (Lease $for) {
        $invoice = makeInvoice($for, [
            'status' => 'issued', 'subtotal' => 60000, 'vat_amount' => 0,
            'total' => 60000, 'balance' => 60000,
        ]);

        $invoice->items()->create([
            'type' => 'security_deposit', 'description' => 'Security deposit', 'quantity' => 1,
            'unit_price' => 60000, 'amount' => 60000, 'tax_amount' => 0, 'total' => 60000,
        ]);

        $invoice->recomputeTotals();

        return $invoice;
    };

    // (a) BILLED and PAID — the case the raw SQL was blind to, and the one the panel produces.
    $this->collectedOnAnInvoice = $lease();
    $invoice = $depositInvoiceFor($this->collectedOnAnInvoice);

    $payment = Payment::create([
        'tenant_id' => $invoice->tenant_id,
        'payment_date' => now(),
        'amount' => 60000,
        'method' => 'bank_transfer',
        'status' => 'captured',
        'currency' => 'EGP',
    ]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => 60000]);
    $invoice->recomputeTotals();

    // (b) BILLED and NOT paid. An unpaid deposit invoice is a receivable, not money in the bank, so
    //     this lease really is short and must stay on the list.
    $this->billedNotPaid = $lease();
    $depositInvoiceFor($this->billedNotPaid);

    // (c) taken as CASH against the lease — the term the old SQL did handle, kept as the control
    //     for the other direction.
    $this->paidInCash = $lease();
    DepositTransaction::create([
        'lease_id' => $this->paidInCash->id,
        'tenant_id' => $this->paidInCash->tenant_id,
        'asset_id' => $this->asset->id,
        'type' => 'receipt', 'amount' => 60000,
        'transaction_date' => '2026-02-01', 'method' => 'bank', 'status' => 'recorded',
    ]);

    // (d) nothing at all.
    $this->nothingReceived = $lease();
});

it('leaves out a lease whose billed deposit has actually been paid', function () {
    // The column's answer first, so the two are compared rather than only the filter asserted.
    expect(Lease::query()->findOrFail($this->collectedOnAnInvoice->id)->depositShortfall())
        ->toEqual(0.0);

    $listed = Lease::query()
        ->whereIn('status', ['active', 'pending_approval'])
        ->depositOutstanding()
        ->pluck('id')
        ->all();

    expect($listed)->not->toContain($this->collectedOnAnInvoice->id)
        // The control for the OTHER direction: the cash-receipt term the raw SQL did carry must
        // still work, or the fix would have swapped one blindness for another.
        ->and($listed)->not->toContain($this->paidInCash->id)
        // …and the two that really are short are still found, so an empty result cannot pass.
        ->and($listed)->toContain($this->billedNotPaid->id)
        ->and($listed)->toContain($this->nothingReceived->id);
});

it('agrees with the deposit_shortfall column for every lease on the page', function () {
    // The tie-out, asked of all four rows: the missing tooth in the August deposit fix was exactly
    // this — three assertions inside one class, none comparing the aggregate against the model.
    $short = Lease::query()
        ->whereIn('status', ['active', 'pending_approval'])
        ->depositOutstanding()
        ->pluck('id')
        ->all();

    foreach (Lease::query()->whereIn('status', ['active', 'pending_approval'])->get() as $lease) {
        expect(in_array($lease->id, $short, true))->toBe($lease->depositShortfall() > 0);
    }
});

it('narrows the real leases list to the leases that are actually short', function () {
    // Through the screen, because the defect was a filter and not a method: the scope could be
    // right while the table went on carrying its own SQL.
    asTenant($this->asset, function () {
        Livewire::test(ListLeases::class)
            ->assertCanSeeTableRecords([
                $this->collectedOnAnInvoice,
                $this->billedNotPaid,
                $this->paidInCash,
                $this->nothingReceived,
            ])
            ->filterTable('deposit_outstanding', true)
            ->assertCanSeeTableRecords([$this->billedNotPaid, $this->nothingReceived])
            ->assertCanNotSeeTableRecords([$this->collectedOnAnInvoice, $this->paidInCash]);
    });
});
