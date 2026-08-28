<?php

namespace App\Support;

use App\Models\CreditNote;
use App\Models\Custody;
use App\Models\CustodyTransaction;
use App\Models\DepositApplication;
use App\Models\DepositTransaction;
use App\Models\DepreciationEntry;
use App\Models\Disbursement;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeAdvanceRepayment;
use App\Models\Expense;
use App\Models\FixedAsset;
use App\Models\FixedAssetDisposal;
use App\Models\Invoice;
use App\Models\InvoiceWriteOff;
use App\Models\MarketingSpend;
use App\Models\OwnerStatementRun;
use App\Models\Payment;
use App\Models\Payroll;
use App\Models\SlaPenalty;
use App\Models\StockMovement;
use App\Models\StraightLineRentAdjustment;
use App\Models\TenantCreditApplication;
use App\Models\VendorBill;
use App\Models\VendorBillPayment;

/**
 * **How each posting source is undone, and by which named act.**
 *
 * Every registry in this project answers a question a new money source must not be able to dodge:
 * does it post (`LedgerPoster`), may its fields change (`ChangeImpact`), is its date guarded
 * (`PostingDateGuards`), may it be deleted (`DeletionPolicy`), is it property-scoped
 * (`PropertyIsolation`). This is the twenty-fifth: **can an operator undo it, and does that act
 * record why?**
 *
 * It exists because the 2026-08-28 sweep found the answer varied without anyone having decided it
 * varied — 13 of 24 sources had a named reversal, 5 of those recorded a reason, and one
 * (`MarketingSpend`) offered a bare `DeleteAction` on a document that posts to the general ledger.
 * None of that was a decision; it was the accumulated result of twenty-four separate afternoons.
 *
 * **The act name is the Filament action's name**, so the registry points at something an operator
 * can actually press rather than at a service method — which is the distinction §12.4 turned on:
 * `WriteOffInvoiceService::reverse()` was built, tested and reachable from nothing.
 */
final class Reversals
{
    /**
     * Source model => the named act that undoes a committed one.
     *
     * Every one of these records a reason through {@see ReversalReason}, which is the property the
     * gate checks — a reversal that records nothing is the audit question nobody can answer later.
     *
     * @var array<class-string, string>
     */
    public const ACTS = [
        Invoice::class => 'void_invoice',
        Payment::class => 'void_payment',
        CreditNote::class => 'void',
        VendorBill::class => 'cancel_bill',
        VendorBillPayment::class => 'void_payment',
        Expense::class => 'cancel_expense',
        Payroll::class => 'cancel_payroll',
        DepositTransaction::class => 'cancel_deposit',
        Disbursement::class => 'cancel',
        CustodyTransaction::class => 'reverse',
        EmployeeAdvanceRepayment::class => 'reverse_repayment',
        TenantCreditApplication::class => 'reverse_credit',
        DepositApplication::class => 'reverse_deposit_application',

        // ── Added 2026-08-28. The first was built and unreachable; the other four had no undo at all.
        InvoiceWriteOff::class => 'reverse_write_off',
        MarketingSpend::class => 'reverse_document',
        FixedAsset::class => 'reverse_document',
        Custody::class => 'reverse_document',
        EmployeeAdvance::class => 'reverse_document',
    ];

    /**
     * Sources with no operator-facing reversal, and why that is right rather than missing.
     *
     * Each is a document the system WRITES rather than one a person raises, and each is undone by
     * reversing the thing that caused it. Offering a second undo beside that would give an operator
     * two ways to unwind one event, which is how a ledger ends up half-reversed.
     *
     * @var array<class-string, string>
     */
    public const NO_REVERSAL = [
        SlaPenalty::class => 'Applied and detached by ApplySlaPenaltyService — un-applying it IS the reversal, and it happens from the vendor bill it was charged to.',
        DepreciationEntry::class => 'Written by the monthly depreciation run. Undone by reversing the ASSET (or disposing it), never one month at a time — a gap in the schedule is worse than a wrong figure in it.',
        FixedAssetDisposal::class => 'The disposal IS the reversal of an asset leaving the books. Reversing the reversal would leave the asset in neither state.',
        OwnerStatementRun::class => 'Superseded by a new version through ReviseOwnerStatementRunService — the owner holds a copy of the old one, so it is corrected forward, never withdrawn.',
        StockMovement::class => 'Undone by an opposing movement (an issue reverses a receipt), which is how a stock ledger has always worked and is what keeps quantity on hand derivable.',
        StraightLineRentAdjustment::class => 'Re-derived forward by PostStraightLineRentService after a lease amendment; a single month reversed by hand would desynchronise the recognition schedule.',
    ];

    /** Every source that must be classified — exactly LedgerPoster::sources(). */
    public static function classified(): array
    {
        return array_merge(array_keys(self::ACTS), array_keys(self::NO_REVERSAL));
    }
}
