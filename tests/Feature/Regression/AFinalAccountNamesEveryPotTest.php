<?php

use App\Filament\Admin\Actions\LeaseActions;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PostDatedCheque;
use App\Models\Unit;
use App\Services\DisputeInvoiceItemService;
use App\Services\MoveOutStatementService;
use App\Services\SettleMoveOutService;
use App\Services\WriteOffInvoiceService;
use Tests\Support\MoveOut;

/**
 * **A final account must forecast the act it is a forecast OF** — and it did not.
 *
 * The class docblock promises it: *"The net position it reports is the one the settlement carries
 * out."* But the open-invoice query was a hand-kept `issued|partially_paid|overdue` while
 * `SettleMoveOutService` nets arrears off the deposit through `ApplyDepositToInvoiceService`, whose
 * scope is `->acceptingSettlement()` — the `InvoiceSettlement` register, in which `disputed` is
 * classified LIVE. So on a lease with a 540,000 deposit and a 50,000 disputed invoice the statement
 * said the tenant was owed **540,000** and the Settle button beside it deducted the 50,000 and
 * refunded **490,000**.
 *
 * Note the DIRECTION, because the sweep row that opened this had it backwards: the statement
 * understated the deduction. It was never refunding a deposit in full over the operator's claim —
 * measured at `d1a4ee0e^` — and a first pass at "fixing" that printed *"under dispute (claimed, not
 * deducted)"* beside an amount the next button deducts, on a document the tenant signs. One
 * register, read by both sides, is the fix.
 *
 * **What is being ARGUED about is a separate figure, from the ITEM flag, shown BESIDE the total.**
 * That is the position MF-07 shipped for AR aging in 2026-08-09 — *"the disputed figure sits BESIDE
 * the aged one rather than being netted out of it: deducting it would understate what the mall is
 * owed"* — and reading `invoices.status` instead labels the whole document: a 50,000 invoice with
 * only its 20,000 service line flagged reported all 50,000 as disputed.
 *
 * **And on-account credit is STATED, never netted (SW-032).** The statement omitted it entirely —
 * money the tenant PAID and never used, one of the pots this document promises. But `settle()` does
 * not call `ApplyTenantCreditService`, and these figures are FROZEN onto an immutable lease event:
 * netting it made the signed document fail to add up from its own keys and understate a departing
 * tenant's debt by the credit balance.
 */
beforeEach(function () {
    // `expired`, not `active`: both the `finalAccount` action and `SettleMoveOutService` refuse a
    // running tenancy — "a final account settles a tenancy that has ended" — so an active fixture
    // measures the statement and can never reach the act it is a forecast of, which is the whole
    // disagreement this file exists to pin.
    $this->lease = MoveOut::lease();
    $this->lease->forceFill(['status' => 'expired'])->saveQuietly();
    $this->lease->refresh();
    $this->service = app(MoveOutStatementService::class);
});

/** An invoice on the moving-out lease, in `$status`, with one line for `$amount`. */
function finalAccountInvoice(float $amount, string $status): Invoice
{
    $invoice = makeInvoice(test()->lease, [
        'status' => $status,
        'issue_date' => '2030-11-01', 'due_date' => '2030-11-10',
        'period_start' => '2030-11-01', 'period_end' => '2030-11-30',
        'subtotal' => $amount, 'vat_amount' => 0, 'total' => $amount,
        'paid_amount' => 0, 'balance' => $amount,
    ]);

    InvoiceItem::create([
        'invoice_id' => $invoice->id, 'type' => 'base_rent', 'description' => 'Rent',
        'amount' => $amount, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => $amount,
    ]);

    return $invoice;
}

/** A cleared cheque against `$assetId`, so its receipt becomes on-account credit for that mall. */
function onAccountCheque(int $assetId, string $reference, string $chequeNumber): void
{
    $payment = Payment::create([
        'tenant_id' => test()->lease->tenant_id,
        'amount' => 25000, 'payment_date' => '2030-11-05',
        'method' => 'cheque', 'status' => 'captured', 'reference' => $reference,
    ]);

    PostDatedCheque::create([
        'tenant_id' => test()->lease->tenant_id,
        'asset_id' => $assetId,
        'cheque_number' => $chequeNumber, 'bank_name' => 'CIB', 'amount' => 25000,
        'cheque_date' => '2030-11-05', 'received_date' => '2030-10-01',
        'reference' => 'PDC-'.$reference,
        'status' => PostDatedCheque::STATUS_CLEARED,
        'cleared_payment_id' => $payment->id,
    ]);
}

it('counts a disputed invoice in the arrears the settlement will actually deduct', function () {
    finalAccountInvoice(50000, 'disputed');

    $s = $this->service->for($this->lease);

    expect($s['open_ar'])->toBe(50000.0)
        ->and($s['net_to_tenant'])->toBe(490000.0);
});

it('forecasts the refund the settlement writes, to the piaster', function () {
    // The assertion that makes the one above mean something: the forecast and the act, on the same
    // fixture. A statement that disagrees with its own Settle button is the whole defect.
    finalAccountInvoice(50000, 'disputed');

    $forecast = $this->service->for($this->lease)['net_to_tenant'];

    app(SettleMoveOutService::class)->settle($this->lease->fresh(), [
        'settlement_date' => '2030-12-01',
        'deductions' => [],
    ]);

    $refunded = (float) $this->lease->deposits()
        ->where('type', 'refund')->sum('amount');

    expect($refunded)->toBe($forecast)
        ->and($refunded)->toBe(490000.0);
});

it('reports what is under dispute from the LINE, beside the total and never out of it', function () {
    // 50,000 owed, of which only the 20,000 line is contested. Reading the header status labels all
    // 50,000, putting 30,000 of undisputed rent under that heading.
    $invoice = finalAccountInvoice(30000, 'disputed');

    $line = InvoiceItem::create([
        'invoice_id' => $invoice->id, 'type' => 'service_charge', 'description' => 'Service charge',
        'amount' => 20000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 20000,
    ]);
    $invoice->refresh();

    app(DisputeInvoiceItemService::class)->dispute($line, 'Cleaning was not provided.');

    $s = $this->service->for($this->lease->fresh());

    expect($s['disputed_ar'])->toBe(20000.0)
        // BESIDE: the full amount is still claimed and still deducted.
        ->and($s['open_ar'])->toBe(50000.0);
});

it('nets a partial write-off out of the disputed figure too', function () {
    // `whereCollectable()` on the query the disputed sum is taken from. A partial write-off moves no
    // status, so without it the operator's own forgiveness is reported as still argued about.
    $invoice = finalAccountInvoice(50000, 'disputed');
    $line = $invoice->items()->first();

    app(DisputeInvoiceItemService::class)->dispute($line, 'Contested in full.');

    app(WriteOffInvoiceService::class)->write($invoice->fresh(), [
        'amount' => 20000,
        'reason' => 'settled_short',
        'write_off_date' => '2030-11-20',
    ]);

    expect($this->service->for($this->lease->fresh())['open_ar'])->toBe(30000.0);
});

it('leaves a FULLY written-off invoice out, not merely netted to zero', function () {
    // `whereCollectable()` on the query, as distinct from `collectableBalance()` inside the sum: the
    // sum nets a PARTIAL write-off on its own, so only a full one proves the clause is there. A
    // full write-off does move the status, and the row must not come back as a deduction from the
    // departing tenant's deposit.
    $invoice = finalAccountInvoice(50000, 'issued');

    app(WriteOffInvoiceService::class)->write($invoice->fresh(), [
        'amount' => 50000,
        'reason' => 'legally_unrecoverable',
        'write_off_date' => '2030-11-20',
    ]);

    expect($this->service->for($this->lease->fresh())['open_ar'])->toBe(0.0)
        ->and($this->service->for($this->lease->fresh())['net_to_tenant'])->toBe(540000.0);
});

it('still deducts an ordinary unpaid invoice', function () {
    // The control: `acceptingSettlement()` must not have widened to everything.
    finalAccountInvoice(50000, 'issued');

    $s = $this->service->for($this->lease);

    expect($s['open_ar'])->toBe(50000.0)
        ->and($s['disputed_ar'])->toBe(0.0)
        ->and($s['net_to_tenant'])->toBe(490000.0);
});

it('leaves a cancelled invoice out of the arrears entirely', function () {
    // The other control, and the one `acceptingSettlement()` exists for: a document that left the
    // books is not a deduction.
    finalAccountInvoice(50000, 'cancelled');

    expect($this->service->for($this->lease)['open_ar'])->toBe(0.0);
});

it('states on-account credit the tenant paid and never used', function () {
    // A cleared SERIES cheque naming no invoice — the ordinary Egyptian case, and the one
    // `Tenant::creditBalance()` was taught about in 2026-08-24: the money is in the bank and in
    // unearned revenue, and the property comes off the cheque because the allocations cannot say.
    onAccountCheque($this->lease->unit->asset_id, 'ON-ACCOUNT-1', 'CHQ-900001');

    $s = $this->service->for($this->lease);

    expect($s['on_account_credit'])->toBe(25000.0)
        // …and it is NOT netted: `settle()` never applies it, so promising it here would put a
        // number on the signed lease event that nothing performs.
        ->and($s['net_to_tenant'])->toBe(540000.0);
});

it('does not hand over another mall’s on-account credit', function () {
    onAccountCheque(makeAsset(['code' => 'OTH'])->id, 'OTHER-MALL-1', 'CHQ-900002');

    expect($this->service->for($this->lease)['on_account_credit'])->toBe(0.0);
});

it('claims nothing for a receipt that names no property at all', function () {
    // `payments` carries NO `asset_id` column: a receipt's property comes from its allocations or
    // from the cheque it cleared, and an unallocated bank transfer has neither. The honest answer is
    // that it belongs to no mall, so this document — which settles ONE mall's ledger — must not
    // hand it over.
    Payment::create([
        'tenant_id' => $this->lease->tenant_id,
        'amount' => 25000, 'payment_date' => '2030-11-05',
        'method' => 'bank_transfer', 'status' => 'captured', 'reference' => 'UNATTRIBUTED-1',
    ]);

    expect($this->service->for($this->lease)['on_account_credit'])->toBe(0.0)
        // …the control: the money is not lost, it is merely not attributable to this mall.
        ->and($this->lease->tenant->creditBalance())->toBe(25000.0);
});

it('claims nothing when the lease’s unit has been soft-deleted', function () {
    // `$lease->unit?->asset_id` is null then (the relation has no `withTrashed()`), and an
    // unscoped `creditBalance()` there would put EVERY mall's on-account credit on this one
    // mall's final account.
    onAccountCheque(makeAsset(['code' => 'OTH2'])->id, 'ELSEWHERE-1', 'CHQ-900003');
    Unit::whereKey($this->lease->unit_id)->delete();

    expect($this->service->for($this->lease->fresh())['on_account_credit'])->toBe(0.0);
});

it('renders both figures in the final-account modal', function () {
    // The modal is the ONLY surface these two figures are ever read on — there is no final-account
    // PDF — so without this the whole operator-facing half of the change is unproven: a mutation
    // that deleted both rows from the modal body left the suite fully green.
    $invoice = finalAccountInvoice(50000, 'disputed');
    app(DisputeInvoiceItemService::class)->dispute($invoice->items()->first(), 'Contested.');
    onAccountCheque($this->lease->unit->asset_id, 'ON-ACCOUNT-2', 'CHQ-900004');

    $body = strip_tags((string) LeaseActions::finalAccountSummary($this->lease->fresh()));

    expect($body)->toContain(__('admin.move_out.disputed_ar'))
        ->toContain('50,000.00')                                   // claimed, and deducted
        ->toContain(__('admin.move_out.on_account_credit'))
        ->toContain('25,000.00')
        // …and the net is the forecast of what Settle writes, not one inflated by the credit.
        ->toContain('490,000.00');
});

it('says nothing about pots that are empty', function () {
    // The other half of "shown only when there is one": a row of zeroes reads as settled questions
    // and costs the attention the two real figures need.
    $body = strip_tags((string) LeaseActions::finalAccountSummary($this->lease->fresh()));

    expect($body)->not->toContain(__('admin.move_out.disputed_ar'))
        ->not->toContain(__('admin.move_out.on_account_credit'));
});
