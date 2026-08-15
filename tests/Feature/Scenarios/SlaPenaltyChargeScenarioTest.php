<?php

use App\Support\MorphMap;
use App\Models\JournalLine;
use App\Models\SlaPenalty;
use App\Models\FacilityWorkOrder;
use App\Models\SlaPolicy;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Models\VendorContract;
use App\Services\Accounting\FiscalCalendar;
use App\Services\AssessSlaPenaltyService;
use App\Services\ApplySlaPenaltyService;
use App\Services\FacilityWorkOrderService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * Charging an assessed SLA penalty to the vendor's bill (FR-CM-08, money half).
 *
 * `vendor_bills` has no line items and `balance` is DERIVED by VendorBill::recompute() —
 * the single source of truth for AP settlement, mirroring the Invoice AR invariant. So the
 * penalty is a second offset that recompute() folds in, exactly as credit_applied_amount
 * does on the tenant side. Nothing else may write `balance`.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    // The GL assertion needs a real chart + role mapping — the same pair every other
    // ledger test seeds.
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    // Posting needs an open accounting period — the same setup every GL scenario uses.
    app(FiscalCalendar::class)->ensureYear((int) now()->year);
    $this->assess = app(AssessSlaPenaltyService::class);
    $this->apply = app(ApplySlaPenaltyService::class);
    $this->wos = app(FacilityWorkOrderService::class);
    $this->asset = makeAsset(['code' => 'CHG']);
    $this->vendor = Vendor::create(['name' => 'CoolAir', 'category' => 'hvac', 'status' => 'active']);
    SlaPolicy::create(['asset_id' => $this->asset->id, 'priority' => 'urgent', 'resolve_hours' => 1]);

    VendorContract::create([
        'vendor_id' => $this->vendor->id, 'asset_id' => $this->asset->id,
        'name' => 'HVAC SLA', 'status' => 'active',
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'value' => 100000,
        'sla_penalty_basis' => 'flat', 'sla_penalty_rate' => 500,
    ]);
});

/** A closed, late external CM with a final penalty ready to charge. */
function finalPenalty(): SlaPenalty
{
    $order = FacilityWorkOrder::create([
        'asset_id' => test()->asset->id, 'work_order_type' => 'cm', 'execution_type' => 'external',
        'vendor_id' => test()->vendor->id, 'description' => 'Chiller down', 'title' => 'Fix chiller',
        'category' => 'hvac', 'priority' => 'urgent', 'scheduled_for' => '2026-07-01',
    ]);
    app(FacilityWorkOrderService::class)->transition($order, 'in_progress');
    test()->travel(6)->hours();
    app(FacilityWorkOrderService::class)->transition($order->fresh(), 'done');

    return SlaPenalty::firstOrFail();
}

function payableBill(float $subtotal = 5000, string $status = 'approved'): VendorBill
{
    $bill = VendorBill::create([
        'vendor_id' => test()->vendor->id, 'asset_id' => test()->asset->id,
        'category' => 'maintenance', 'status' => $status,
        'bill_date' => '2026-07-05', 'subtotal' => $subtotal, 'vat_amount' => 0,
    ]);
    $bill->recompute();

    return $bill->fresh();
}

/* ---- the deduction ------------------------------------------------------ */

it('reduces what the vendor is owed', function () {
    $bill = payableBill(5000);
    $penalty = finalPenalty();

    $this->apply->toBill($penalty, $bill);
    $bill->refresh();

    expect((float) $bill->penalty_applied_amount)->toBe(500.0);
    expect((float) $bill->balance)->toBe(4500.0);
    expect($penalty->fresh()->status)->toBe(SlaPenalty::STATUS_APPLIED);
    expect($penalty->fresh()->vendor_bill_id)->toBe($bill->id);
});

it('leaves the bill total untouched — the penalty is an offset, not a rewrite', function () {
    // Mutating an approved bill's total would restate what the vendor invoiced.
    $bill = payableBill(5000);
    $this->apply->toBill(finalPenalty(), $bill);

    expect((float) $bill->fresh()->total)->toBe(5000.0);
});

it('nets off against a payment on the same bill', function () {
    $bill = payableBill(5000);
    $bill->payments()->create(['amount' => 1000, 'payment_date' => '2026-07-10', 'method' => 'bank_transfer']);
    $bill->refresh()->recompute();

    $this->apply->toBill(finalPenalty(), $bill);
    $bill->refresh();

    expect((float) $bill->paid_amount)->toBe(1000.0);
    expect((float) $bill->penalty_applied_amount)->toBe(500.0);
    expect((float) $bill->balance)->toBe(3500.0); // 5000 − 1000 − 500
});

it('settles a bill the penalty covers entirely', function () {
    // The payable is extinguished even though no cash moved.
    $bill = payableBill(500);
    $this->apply->toBill(finalPenalty(), $bill);
    $bill->refresh();

    expect((float) $bill->balance)->toBe(0.0);
    expect($bill->status)->toBe('paid');
});

/* ---- what must never happen --------------------------------------------- */

it('refuses to deduct more than the bill still owes', function () {
    // AP would go negative — a receivable wearing a payable's clothes.
    $bill = payableBill(300);

    expect(fn () => $this->apply->toBill(finalPenalty(), $bill))
        ->toThrow(DomainException::class);

    expect((float) $bill->fresh()->balance)->toBe(300.0);
});

it('refuses another vendor\'s bill', function () {
    $other = Vendor::create(['name' => 'Other', 'category' => 'hvac', 'status' => 'active']);
    $bill = VendorBill::create([
        'vendor_id' => $other->id, 'asset_id' => $this->asset->id, 'category' => 'maintenance',
        'status' => 'approved', 'bill_date' => '2026-07-05', 'subtotal' => 5000, 'vat_amount' => 0,
    ]);
    $bill->recompute();

    expect(fn () => $this->apply->toBill(finalPenalty(), $bill->fresh()))
        ->toThrow(DomainException::class);
});

it('refuses a draft bill', function () {
    // Not on the books yet — the GL entry would adjust a payable that doesn't exist.
    expect(fn () => $this->apply->toBill(finalPenalty(), payableBill(5000, 'draft')))
        ->toThrow(DomainException::class);
});

it('refuses a penalty that is still accruing', function () {
    // Charging it would deduct a figure that is about to change.
    $order = FacilityWorkOrder::create([
        'asset_id' => $this->asset->id, 'work_order_type' => 'cm', 'execution_type' => 'external',
        'vendor_id' => $this->vendor->id, 'description' => 'x', 'title' => 'Fix',
        'category' => 'hvac', 'priority' => 'urgent', 'scheduled_for' => '2026-07-01',
    ]);
    $this->wos->transition($order, 'in_progress');
    $this->travel(6)->hours();
    $pending = $this->assess->assess($order->fresh());

    expect($pending->status)->toBe(SlaPenalty::STATUS_PENDING);
    expect(fn () => $this->apply->toBill($pending, payableBill(5000)))->toThrow(DomainException::class);
});

it('refuses a waived penalty', function () {
    $penalty = finalPenalty();
    $this->assess->waive($penalty, 'goodwill');

    expect(fn () => $this->apply->toBill($penalty->fresh(), payableBill(5000)))
        ->toThrow(DomainException::class);
});

it('refuses to charge the same penalty twice', function () {
    $penalty = finalPenalty();
    $this->apply->toBill($penalty, payableBill(5000));

    expect(fn () => $this->apply->toBill($penalty->fresh(), payableBill(5000)))
        ->toThrow(DomainException::class);
});

/* ---- detaching ---------------------------------------------------------- */

it('restores the bill balance when the deduction is detached', function () {
    $bill = payableBill(5000);
    $penalty = finalPenalty();
    $this->apply->toBill($penalty, $bill);

    $this->apply->detach($penalty->fresh());
    $bill->refresh();

    expect((float) $bill->penalty_applied_amount)->toBe(0.0);
    expect((float) $bill->balance)->toBe(5000.0);
    // Still owed — it returns to chargeable rather than disappearing.
    expect($penalty->fresh()->status)->toBe(SlaPenalty::STATUS_FINAL);
    expect($penalty->fresh()->vendor_bill_id)->toBeNull();
});

it('releases an applied penalty back to final when the bill is cancelled (never silently dropped)', function () {
    // A penalty-settled bill with no cash is cancellable (paid_amount is 0). Cancelling must return
    // the penalty to the pool — otherwise it stays `applied` on a cancelled bill: still owed by the
    // vendor, but no longer chargeable or collectable.
    $bill = payableBill(5000);
    $penalty = finalPenalty();
    $this->apply->toBill($penalty, $bill);
    expect($penalty->fresh()->status)->toBe(SlaPenalty::STATUS_APPLIED);

    app(\App\Services\VendorBillService::class)->cancel($bill->fresh());

    expect($penalty->fresh()->status)->toBe(SlaPenalty::STATUS_FINAL)
        ->and($penalty->fresh()->vendor_bill_id)->toBeNull()
        ->and($bill->fresh()->status)->toBe('cancelled');
});

/* ---- the GL: a cost reduction, not income ------------------------------- */

it('posts the penalty as Dr Accounts Payable / Cr the expense the bill charged', function () {
    // Money from a supplier adjusts the price paid to them; it is not new revenue. So it
    // credits the SAME expense role VendorBillJournalizer debited — the penalty follows
    // the cost.
    $bill = payableBill(5000);
    $this->apply->toBill(finalPenalty(), $bill);

    $entry = SlaPenalty::first()->refresh();
    app(\App\Services\Accounting\LedgerPoster::class)->post($entry);

    $lines = JournalLine::whereHas('entry', fn ($q) => $q->where('source_type', MorphMap::alias(SlaPenalty::class)))
        ->with('account')->get();

    expect($lines)->toHaveCount(2);

    $debit = $lines->firstWhere(fn ($l) => (float) $l->debit > 0);
    $credit = $lines->firstWhere(fn ($l) => (float) $l->credit > 0);

    expect((float) $debit->debit)->toBe(500.0);
    expect((float) $credit->credit)->toBe(500.0);
    expect($debit->account->code)->toBe('21101001');  // Accounts Payable
    expect($credit->account->code)->toBe('51102001'); // Maintenance Expense — NOT income
});

it('keeps the AP tie-out balanced — the ledger and the bills agree', function () {
    // The check that matters for a money change: the reconciliation harness independently
    // re-derives AP as the sum of bill balances. The penalty reduces BOTH the stored
    // balance and the GL's payable, so the two must still meet. If they didn't, every
    // monthly close would report a phantom discrepancy.
    $bill = payableBill(5000);
    $poster = app(\App\Services\Accounting\LedgerPoster::class);
    $poster->post($bill->fresh());

    $this->apply->toBill(finalPenalty(), $bill);
    $poster->post(SlaPenalty::first()->refresh());

    $tie = app(\App\Services\Reconciliation\BooksReconciliationService::class)->glTieOut();

    expect($tie['ap']['expected'])->toBe(4500.0);   // 5000 − 500 penalty
    expect($tie['ap']['delta'])->toBe(0.0);         // and the GL agrees
});

it('posts nothing for a penalty that is only assessed, never charged', function () {
    // `final` is an estimate of what is owed; an estimate has no place in the ledger.
    $penalty = finalPenalty();

    expect(app(\App\Services\Accounting\Journalizers\SlaPenaltyJournalizer::class)
        ->payload($penalty))->toBeNull();
});

it('posts nothing for a waived penalty', function () {
    $penalty = finalPenalty();
    $this->assess->waive($penalty, 'goodwill');

    expect(app(\App\Services\Accounting\Journalizers\SlaPenaltyJournalizer::class)
        ->payload($penalty->fresh()))->toBeNull();
});
