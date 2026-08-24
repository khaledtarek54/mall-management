<?php

/*
|--------------------------------------------------------------------------
| The control totals must reconcile to the GL on their own face (2026-08-25)
|--------------------------------------------------------------------------
| `billing:reconcile` prints its control totals under the heading "reconcile these against your
| books" — and printed AR GROSS, which does not equal the AR control account whenever a credit note
| is standing unapplied.
|
| Measured on the seeded portfolio: the table offered 1,481,825.54 while the ledger held
| 1,477,325.54. The 4,500 is two unapplied credit notes, which correctly credit AR the moment they
| are ISSUED — Yardi posts a credit memo the same way, and it stands against the tenant until it is
| applied. Both figures were right. Nothing on the page said how they related.
|
| The service had already computed both reconciling figures, with a comment stating their purpose —
| "so the accountant doesn't read AR gross" — and the renderer dropped them. A computed value that
| nothing displays is this codebase's most repeated defect.
*/

use App\Models\CreditNote;

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->tenant = makeTenant();
    $this->unit = makeUnit($this->asset);
    $this->lease = makeLease($this->unit, $this->tenant, ['status' => 'active']);
});

/** An open invoice of `$total`, and a credit note of `$credit` left unapplied against the tenant. */
function standingCredit($ctx, float $total, float $credit): void
{
    // A complete invoice — subtotal + VAT = total, with the line behind it — so the OTHER eight
    // checks pass and the run's exit status is real evidence rather than noise this test ignores.
    $invoice = makeInvoice($ctx->lease, [
        'asset_id' => $ctx->asset->id,
        'status' => 'issued',
        'subtotal' => $total,
        'vat_amount' => 0,
        'total' => $total,
        'paid_amount' => 0,
        'balance' => $total,
    ]);

    $invoice->items()->create([
        'type' => 'base_rent',
        'description' => 'Rent',
        'quantity' => 1,
        'unit_price' => $total,
        'amount' => $total,
        'vat_rate' => 0,
        'vat_amount' => 0,
        'total' => $total,
    ]);

    CreditNote::create([
        'tenant_id' => $ctx->tenant->id,
        'asset_id' => $ctx->asset->id,
        'status' => 'issued',
        'issue_date' => now(),
        'subtotal' => $credit,
        'total' => $credit,
        'applied_amount' => 0,
        'balance' => $credit,
        'reason' => 'adjustment',
    ]);
}

it('shows the unapplied credit as a reconciling item, not just gross AR', function () {
    standingCredit($this, total: 100000, credit: 4500);

    $this->artisan('billing:reconcile')
        ->expectsOutputToContain('4,500.00')   // the reconciling item is on the page
        ->expectsOutputToContain('95,500.00')  // and the figure that equals the ledger
        ->assertSuccessful();
});

it('states plainly which line is the one that should equal the ledger', function () {
    standingCredit($this, total: 100000, credit: 4500);

    // Naming it is the whole fix: two correct numbers with nothing saying how they relate is what
    // costs an accountant the hour, not a wrong number.
    $this->artisan('billing:reconcile')
        ->expectsOutputToContain('Net AR')
        ->expectsOutputToContain('tenant ledger')
        ->assertSuccessful();
});

it('still prints a clean zero when no credit is standing', function () {
    $invoice = makeInvoice($this->lease, [
        'asset_id' => $this->asset->id, 'status' => 'issued',
        'subtotal' => 100000, 'vat_amount' => 0,
        'total' => 100000, 'paid_amount' => 0, 'balance' => 100000,
    ]);

    $invoice->items()->create([
        'type' => 'base_rent', 'description' => 'Rent', 'quantity' => 1,
        'unit_price' => 100000, 'amount' => 100000,
        'vat_rate' => 0, 'vat_amount' => 0, 'total' => 100000,
    ]);

    // Gross and net agree here, and the line must still render — a reconciling item that appears
    // only when it is non-zero teaches the reader it is optional, and its absence then reads as
    // "nothing to reconcile" on exactly the run where they should check.
    $this->artisan('billing:reconcile')
        ->expectsOutputToContain('Net AR')
        ->assertSuccessful();
});
