<?php

namespace App\Support;

use App\Models\Concerns\GuardsPostingDate;
use App\Models\CreditNote;
use App\Models\Custody;
use App\Models\CustodyTransaction;
use App\Models\DepositTransaction;
use App\Models\DepreciationEntry;
use App\Models\Disbursement;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeAdvanceRepayment;
use App\Models\Expense;
use App\Models\FixedAsset;
use App\Models\FixedAssetDisposal;
use App\Models\Invoice;
use App\Models\SlaPenalty;
use App\Models\MarketingSpend;
use App\Models\OwnerStatementRun;
use App\Models\Payment;
use App\Models\Payroll;
use App\Models\StockMovement;
use App\Models\DepositApplication;
use App\Models\StraightLineRentAdjustment;
use App\Models\TenantCreditApplication;
use App\Models\VendorBill;
use App\Models\VendorBillPayment;
use App\Services\ApplyDepositToInvoiceService;
use App\Services\CreditNoteService;
use App\Services\DisposeFixedAssetService;
use App\Services\GrantCustodyService;
use App\Services\GrantEmployeeAdvanceService;
use App\Services\OwnerAccounting\DisbursementService;
use App\Services\OwnerAccounting\FinaliseOwnerStatementRunService;
use App\Services\PayrollService;
use App\Services\RecordAdvanceRepaymentService;
use App\Services\SettleCustodyService;
use App\Services\StockMovementService;
use App\Services\VendorBillService;

/**
 * Where each GL source's entry date is checked against a closed accounting period.
 *
 * WHY A REGISTRY. Every source already declares the column its journal entry is dated from
 * (LedgerRealtimeSync::SOURCE_DATE_COLUMNS). This declares the other half: **who refuses that
 * date when its period is closed.** The two together are what
 * PostingDateGuardConformanceTest enforces, so a new money source cannot ship without
 * someone answering the question.
 *
 * It exists because the answer was got wrong six times in a row — each fixed locally, as if it
 * were that module's own bug, and each time the next module shipped with the same hole. The
 * clearest illustration is custody: the first fix (F-93) guarded the SETTLEMENT and left the
 * GRANT open, so an operator could book a عهدة into a closed month, and the settlement guard
 * would then refuse every settlement of it (a settlement may not predate its grant) — a custody
 * recorded, unbacked in the books, and unsettleable.
 *
 * TWO KINDS OF ENTRY:
 *
 *  - **A guard class** — the service (or model) that runs `PostingDate::assertOpen` on the date.
 *    Prefer a service: the refusal can be raised before any related work begins. Several sources
 *    have no create/update service at all (their Filament resource writes the model), and for
 *    those the model's own save is the single choke point every path shares — form, console,
 *    API, seeder — so they use {@see GuardsPostingDate}.
 *
 *  - **`system:<reason>`** — the date can never be operator-typed, so there is nothing to guard.
 *    The conformance test fails if a form later offers a DatePicker for that column, because
 *    that exemption would then be silently false.
 */
class PostingDateGuards
{
    /** Marks a date the system sets; the text after the colon is the reason. */
    public const SYSTEM_PREFIX = 'system:';

    /**
     * Source model => the class that guards its posting date, or a `system:` reason.
     *
     * Keep in step with LedgerRealtimeSync::SOURCE_DATE_COLUMNS — the gate enforces it.
     */
    public const GUARDS = [
        // --- guarded in a service (preferred) ---
        CreditNote::class => CreditNoteService::class,
        \App\Models\InvoiceWriteOff::class => \App\Services\WriteOffInvoiceService::class,
        VendorBill::class => VendorBillService::class,
        VendorBillPayment::class => VendorBillService::class,
        Payroll::class => PayrollService::class,
        FixedAssetDisposal::class => DisposeFixedAssetService::class,
        EmployeeAdvance::class => GrantEmployeeAdvanceService::class,
        EmployeeAdvanceRepayment::class => RecordAdvanceRepaymentService::class,
        Custody::class => GrantCustodyService::class,
        CustodyTransaction::class => SettleCustodyService::class,
        StockMovement::class => StockMovementService::class,
        OwnerStatementRun::class => FinaliseOwnerStatementRunService::class,
        Disbursement::class => DisbursementService::class,

        // --- guarded on the model, because there is no create/update service ---
        // Their Filament resource writes the model directly, so the model's save is the only
        // choke point every path shares.
        Expense::class => Expense::class,
        MarketingSpend::class => MarketingSpend::class,
        DepositTransaction::class => DepositTransaction::class,
        FixedAsset::class => FixedAsset::class,
        // Payment was guarded only on the admin CREATE page, which left the edit form, the
        // portal, the mobile API and the console uncovered — moving a captured payment's date
        // into a closed month sailed through. On the model, every path is covered at once.
        Payment::class => Payment::class,

        // --- the date is not operator-typed ---
        DepreciationEntry::class => self::SYSTEM_PREFIX.
            'period_month is set by DepreciationService::run from the month being posted; the '.
            'operator-reachable inputs are the scheduler and the admin button (both now()) and '.
            'PostDepreciationCommand --month, which is guarded there.',

        SlaPenalty::class => self::SYSTEM_PREFIX.
            'applied_at is stamped now() at the moment the penalty is applied — a penalty cannot '.
            'be applied into the past.',

        TenantCreditApplication::class => self::SYSTEM_PREFIX.
            'entry_date is deliberately stamped at application time, never the source receipt\'s '.
            'date. That decoupling is the whole point: it lets an old overpayment settle a current '.
            'invoice without ever posting into the closed period the overpayment came from.',

        StraightLineRentAdjustment::class => self::SYSTEM_PREFIX.
            'entry_date is the last day of the month being recognised, derived by '.
            'PostStraightLineRentService and never operator-typed. The sweep refuses to post into a '.
            'closed period, which is also what makes an amendment forward-only: months already '.
            'recognised are left exactly as they were.',

        // CORRECTED 2026-08-11. This said `system:` — "entry_date is stamped at application time
        // and is not operator-typable" — and it was factually false. `ApplyDepositToInvoiceService`
        // stamps `$on`, a PARAMETER, and `SettleMoveOutService` passes the operator's
        // `settlement_date` straight off an unconstrained DatePicker on the Lease resource.
        //
        // A `system:` exemption asserting a safety property that does not hold is worse than no
        // entry at all: the gate reports coverage. The gate could not catch this either, because it
        // checks the registry's own declarations, and the offending field lives on a different
        // resource under a different name. Back-dating a settlement into a closed March netted
        // 120,000 of arrears off the deposit, closed the AR, showed "Saved ✓" — and the post was
        // refused inside the best-effort sync job, leaving a tie-out gap of exactly that much.
        //
        // Guarded in BOTH services now: the one that stamps the date onto the row, and the one that
        // takes it from the operator (so a refusal arrives before the first side effect rather than
        // half way through a final account).
        DepositApplication::class => ApplyDepositToInvoiceService::class,
        // The finalisation guard already froze issue_date once an invoice is ISSUED; what
        // remained was a DRAFT back-dated and then issued, posting AR into a sealed month.
        Invoice::class => Invoice::class,
    ];

    /** True when the entry is a `system:` exemption rather than a guard class. */
    public static function isSystemDated(string $guard): bool
    {
        return str_starts_with($guard, self::SYSTEM_PREFIX);
    }
}
