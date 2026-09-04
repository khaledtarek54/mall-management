<?php

namespace App\Support;

/**
 * Every critical section in the codebase, and what it protects.
 *
 * **Why this registry exists.** `SQLiteGrammar::compileLock()` returns `''`, and the suite runs on
 * sqlite `:memory:` — so every one of the {@see totalLocks()} registered lock acquisitions is **inert in every
 * test**. Deleting one turned nothing red. Production is unaffected (MySQL honours the locks); what was unprotected
 * is the *guard itself*. Concurrency is the one invariant class in CLAUDE.md that never got a
 * registry and a gate, and it is the class this project has already been bitten by twice — the unit
 * double-booking race and the Paymob double-charge race.
 *
 * `ConcurrencyPolicyConformanceTest` fails the build when a locking file is unregistered, when a
 * registered file stops locking, and when the number of locks in a file changes. It counts row
 * locks (`lockForUpdate` / `sharedLock`) **and atomic cache locks** (`Cache::lock`) together: they
 * are the same guard by different mechanisms, and some critical sections use one because the thing
 * being protected is not a row — `AllocatesDocumentNumber` takes a blocking cache lock around every
 * numbered-document insert, and the sales-declaration scan uses one where its siblings lock a row. The count is the
 * teeth: a lock quietly dropped during a refactor is exactly the failure mode, and the fix when the
 * gate fires is to confirm the change was intended and update the number — not to delete the entry.
 *
 * **`PROVEN` vs `REGISTERED`.** A registry entry says a lock is there; it cannot say the lock is
 * *reached* on the real code path. `PROVEN` sections are additionally driven through their service
 * by `Tests\Support\LockSpy`, which makes the lock observable on SQLite by compiling it to a SQL
 * comment. Those tests fail when the lock is removed. Everything else is count-pinned only, and
 * that difference is stated rather than blurred.
 *
 * **A lock serialises writers; it does NOT make the guard behind it SEE them.** This is the third
 * thing the registry now records, and it is the one that was measured wrong. Under MySQL
 * REPEATABLE READ a transaction's consistent-read snapshot is fixed at its FIRST plain read, so a
 * guard query that runs *after* `lockForUpdate()` is still answered from before the wait. Proven
 * with two processes on two connections (pre-staging QA, F-09): the second transaction's
 * `isActivelyLeased()` returned **false** with the first transaction's lease committed on that very
 * unit, while a **locking** read of the same query at the same instant returned 1. What actually
 * prevented the double-booking was the UNIQUE index on the document number — and only because both
 * writers computed the same number from the same stale snapshot, so the loser got a duplicate-key
 * 500 instead of the intended refusal.
 *
 * {@see AUTHORITATIVE_GUARDS} therefore registers the *reads*, not just the locks: a guard that
 * decides whether a write may proceed has to be a locking read itself. On SQLite the difference is
 * invisible, which is precisely why it needs a gate rather than a convention.
 *
 * **What none of this proves.** That two concurrent transactions actually serialise. That needs
 * MySQL and two connections, and no amount of single-process testing substitutes for it. The gate
 * protects the guard from being deleted; MySQL is what makes the guard work. The end-to-end proof
 * is `docs/qa/scripts/race.sh`.
 */
final class ConcurrencyPolicy
{
    /**
     * Critical sections driven through a `LockSpy` test — removing the lock turns the build red.
     *
     * Chosen for consequence: the two races that have actually happened here, and the money paths
     * where a lost update is a wrong balance rather than a duplicate notification.
     *
     * @var array<string, array{locks: int, protects: string}>
     */
    /**
     * Guards that decide whether a WRITE may proceed, and must therefore read under a lock.
     *
     * Keyed `Class::method`, because the property is about one method's own query rather than about
     * a file: `Unit::isActivelyLeased()` and `Unit::isActivelyLeasedForUpdate()` ask the same
     * question and only one of them may answer a writer. The plain one is deliberately kept for
     * form validation and table columns, where taking row locks on every render would be a cost
     * with no reader waiting on it.
     *
     * `ConcurrencyPolicyConformanceTest` reads each method's own body and fails when the locking
     * read is gone — which is the mutation that turned nothing red before F-09.
     *
     * @var array<string, string> `Class::method` => what a stale read would let through
     */
    public const AUTHORITATIVE_GUARDS = [
        'App\\Models\\EmployeeAdvance::outstandingForUpdate' => 'Two payroll runs each deducting within the '.
            'pre-approval outstanding, approved at once. The advance row lock serialises the writers; only a '.
            'locking read of the repayments and the approved payroll lines sees the installment the other run '.
            'just committed. Without it the loan is over-repaid and the GL credits Employee Advances twice.',
        'App\\Models\\Unit::isActivelyLeasedForUpdate' => 'Two leases signed on the same vacant unit at once. The unit row lock serialises the '.
            'writers; only a locking read here sees the lease the other one just committed.',

        'App\\Models\\Lease::depositHeldForUpdate' => 'The deposit pot, which is one per lease and spans THREE tables '.
            '(recorded movements, deposit applications, and the settled part of any billed deposit). A move-out '.
            'refund is written from this figure and no UNIQUE index catches a race on it — `number` is unique, but '.
            'the two writers are handed different ones, so nothing constrains the POT — and so two '.
            'settlements each read the whole pot and each disburse it.',

        'App\\Models\\Lease::depositBilledOutstandingForUpdate' => 'A SECOND invoice for a deposit already asked for. `BillSecurityDepositService` locks '.
            'the lease and then decides how much is still unbilled — a comment there claimed the lock was the '.
            'guard, which is the premise this codebase has already disproved twice. Two operators each raise an '.
            'invoice, the tenant owes twice the agreed security, and `deposits_held` is credited twice on payment.',

        'App\\Models\\Invoice::collectableBalanceForUpdate' => 'What a tenant-driven capture may put on an invoice. `RecordDemoPaymentAction` '.
            'locks the invoice and then decides from this figure, so a plain read there is answered from before it '.
            'waited — the same premise both of Payment\'s guards take this lock to avoid.',

        'App\\Models\\Payment::assertInvoicesNotOverAllocated' => 'A second receipt settling an invoice another channel has already paid. All four '.
            'settlement channels are summed here, and the guard is only as strong as its weakest term.',

        'App\\Models\\Payment::refitAllocationsToBalance' => 'The gateway capture path, which clamps rather than throws — a stale read would clamp '.
            'against a balance that predates a concurrent settlement and let the card money over-settle.',
    ];

    public const PROVEN = [
        'app/Services/SettleMoveOutService.php' => [
            'locks' => 1,
            'protects' => 'The LEASE, as the transaction first statement. The deposit pot spans three tables, so '.
                'locking any one of them leaves the other two unserialised — and this service locked nothing at '.
                'all, so two move-outs on one lease each refunded the whole deposit.',
        ],
        'app/Services/ConvertLeaseToHoldoverService.php' => [
            'locks' => 1,
            'protects' => 'The unit. Resuming an expired lease makes it ACTIVE on that shop again, so '.
                'this is the third writer that can put an active lease on a unit. The hole is '.
                'sequential rather than a race: the nightly sweep vacates the unit, leasing re-lets '.
                'it, and the old lease is converted weeks later — two active leases, both billing.',
        ],
        'app/Services/LeaseCreationService.php' => [
            'locks' => 1,
            'protects' => 'The unit. Two leases signed on the same vacant unit at once — the race that '.
                'actually happened here. The contended row must be locked, not the lease being written.',
        ],
        'app/Services/LateFeeService.php' => [
            'locks' => 1,
            'protects' => 'The invoice being penalised. Two sweeps that both passed the '.
                '"no live late fee" check would each raise a fee invoice for the same arrears.',
        ],
        'app/Services/VoidInvoiceService.php' => [
            'locks' => 1,
            'protects' => 'The invoice. Voiding while a payment is being allocated to it would strand '.
                'the settlement on a document that has left the books.',
        ],
        'app/Services/ApplyTenantCreditService.php' => [
            'locks' => 4,
            'protects' => 'The credit balance and the invoice. On-account credit is one of the four AR '.
                'settlement channels; two applications reading the same balance would both spend it.',
        ],
        'app/Services/GeneratePreventiveWorkOrdersService.php' => [
            // Two rounds, one lock each: the calendar round and the counter round. Both re-check
            // due-ness under the lock, because the pre-filtering scope is only a scope.
            'locks' => 2,
            'protects' => 'The plan row, re-checked under the lock before `advanceDue()` (time round) '.
                'or before the usage baseline moves (counter round). Two overlapping sweeps would '.
                'otherwise raise two work orders for the same service — and on a usage plan the '.
                'baseline is what makes the job non-repeating, so an unlocked read-then-write there '.
                'is the same defect wearing a different name.',
        ],
        'app/Services/FacilityWorkOrderService.php' => [
            'locks' => 1,
            'protects' => 'The work order as the aggregate root for itself AND its checklist — every '.
                'mutation of either goes through the same lock, which is why the items table is never '.
                'locked directly.',
        ],
    ];

    /**
     * Registered and count-pinned. The lock is there and its removal turns the build red; whether it
     * is reached on the real path is not separately proven.
     *
     * Most of these are the same shape: a scheduled scan takes the row lock and re-checks its
     * idempotency stamp inside the transaction, so an overlapping run cannot double-notify. That
     * shape is a CLAUDE.md invariant ("scheduled scans must be idempotent + lock-safe"), which is
     * why it is stated once here rather than restated sixty times.
     *
     * @var array<string, int>
     */
    public const REGISTERED = [
        // ── Scheduled scans: lock the row, re-check the idempotency stamp inside the transaction ─
        'app/Console/Commands/AutoCloseTenantRequestsCommand.php' => 1,
        // EG-33 — the recurring-cost run locks the SCHEDULE row and re-derives its due date inside
        // the transaction, so two overlapping runs cannot both mint the month's expense. The
        // UNIQUE (recurring_expense_id, expense_date) index is the backstop, not the guard.
        'app/Services/GenerateRecurringExpensesService.php' => 1,
        'app/Console/Commands/EstimateMissingSalesCommand.php' => 1,
        'app/Console/Commands/ExpireVendorContractsCommand.php' => 1,
        'app/Console/Commands/RemindExpiringLeasesCommand.php' => 1,
        'app/Console/Commands/RemindOverdueTenantsCommand.php' => 1,
        'app/Console/Commands/ScanContractRenewalsCommand.php' => 2,
        // Claims a due saved report under the lock and re-checks `isDueOn()` inside the
        // transaction — without it two workers both read "not sent today" and the recipient
        // gets the month-end pack twice.
        'app/Console/Commands/DeliverScheduledReportsCommand.php' => 1,
        // Claims a due notice under the lock and re-checks its status inside the transaction.
        // Without it two workers both read "still scheduled" and every retailer in the mall gets
        // the same push twice — the one failure a broadcast cannot take back.
        'app/Console/Commands/SendScheduledAnnouncementsCommand.php' => 1,
        'app/Console/Commands/ScanLeaseOptionWindowsCommand.php' => 1,
        'app/Console/Commands/ScanLowStockCommand.php' => 1,
        // A cache lock, not a row lock: this scan has no single row to hold, so an atomic lock is
        // the analogue to the siblings' lockForUpdate + stamp. Registered because the mechanism
        // differs, not the obligation.
        'app/Console/Commands/ScanMissingSalesDeclarationsCommand.php' => 1,
        'app/Console/Commands/ScanOverdueInvoicesCommand.php' => 1,
        'app/Console/Commands/ScanTenantDocumentExpiryCommand.php' => 2,
        'app/Console/Commands/ScanTenantRequestSlaBreachesCommand.php' => 1,
        'app/Console/Commands/ScanVendorDocumentExpiryCommand.php' => 2,
        'app/Console/Commands/ScanWorkOrderSlaBreachesCommand.php' => 2,

        // ── Money in ─────────────────────────────────────────────────────────────────────────
        'app/Actions/Api/V1/Payments/RecordDemoPaymentAction.php' => 1,
        'app/Http/Controllers/Paymob/CallbackController.php' => 1,   // the double-charge race that bit
        // Two row locks on the invoices being allocated to, PLUS the six LOCKING READS the two
        // over-allocation guards take across the four settlement channels (2026-08-19, F-09).
        // Locking the invoice row serialises two writers; it does not let either SEE the other's
        // allocation, because under REPEATABLE READ a plain read is served from the snapshot taken
        // before the wait. Measured with two processes: the guard passed on a fully-settled invoice.
        'app/Models/Payment.php' => 10,
        'app/Services/ApplyDepositToInvoiceService.php' => 2,
        'app/Services/Banking/MatchBankStatementLineService.php' => 1,
        // One row lock on the lease, re-read inside the txn. The shortfall is check-then-act over
        // receipts and settled billings, so two operators (or one double-click) would each read the
        // same outstanding figure and each raise an invoice for the whole of it — the landlord then
        // holds twice the deposit and owes it back.
        'app/Services/BillSecurityDepositService.php' => 1,
        // One row lock (the ownership, re-checked inside the txn) plus the per-period cache lock
        // that stops a manual assessment run racing the scheduled one.
        'app/Services/BillUnitOwnershipsService.php' => 2,
        // One row lock on the ownership being sold: two operators transferring the same unit must
        // not each open a buyer tenure, which would leave it owned twice on the same day.
        'app/Services/TransferUnitOwnershipService.php' => 1,
        // Acceptance is a first-writer-wins stamp: two contacts at one contractor opening the
        // same job and both pressing accept must not move the SLA clock. The re-read happens
        // INSIDE the transaction after the lock — a value read before the wait answers from a
        // pre-commit snapshot under MySQL REPEATABLE READ.
        'app/Services/AcceptWorkOrderService.php' => 1,
        'app/Services/CreditNoteService.php' => 11,
        'app/Services/MonthlyBillingService.php' => 2,
        'app/Services/Paymob/PaymobPaymentInitiator.php' => 1,
        // 6 since 2026-08-25: clearing a cheque that names no invoice now takes a LOCKING read of
        // the tenant's open invoices before allocating oldest-first — a plain read there would be
        // answered from the snapshot taken before this transaction waited on the cheque row.
        'app/Services/PostDatedChequeService.php' => 6,
        'app/Services/VoidPaymentService.php' => 1,
        'app/Services/WriteOffInvoiceService.php' => 1,

        // ── Money out ────────────────────────────────────────────────────────────────────────
        'app/Services/GeneratePayrollService.php' => 1,
        // THREE, and they are three different mechanisms: a CACHE lock outside the transaction
        // (keyed on the property and the month — what serialises two approvals that would otherwise
        // each see the other still `draft` and pay one employee twice), a row lock on the PAYROLL
        // itself as the transaction's first statement (which makes idempotence authoritative and
        // leaves no read view open while the advance locks are awaited), and a row lock on each
        // `EmployeeAdvance` the run repays. The advance's own BALANCE is read under a lock too, but
        // that lives in `EmployeeAdvance.php` and is counted there.
        'app/Services/PayrollService.php' => 3,
        'app/Services/DraftReorderPurchaseService.php' => 1,
        'app/Services/PurchaseRequestService.php' => 1,
        'app/Services/RecordAdvanceRepaymentService.php' => 3,
        'app/Services/SettleCustodyService.php' => 3,
        'app/Services/VendorBillService.php' => 1,
        'app/Services/VoidVendorBillPaymentService.php' => 2,

        // ── The ledger ───────────────────────────────────────────────────────────────────────
        'app/Services/Accounting/JournalPostingService.php' => 2,
        'app/Services/Accounting/LedgerPoster.php' => 2,
        'app/Services/Accounting/YearEndCloseService.php' => 1,
        'app/Services/DepreciationService.php' => 1,
        'app/Services/DisposeFixedAssetService.php' => 1,

        // ── Recoveries and variable rent ─────────────────────────────────────────────────────
        'app/Services/BillBouncedChequeFeeService.php' => 1,
        'app/Services/BillMeterReadingService.php' => 1,
        'app/Services/BillViolationFineService.php' => 1,
        'app/Services/CamReconciliationService.php' => 3,
        'app/Services/PercentageRentCalculationService.php' => 3,

        // ── Owner accounting ─────────────────────────────────────────────────────────────────
        'app/Services/OwnerAccounting/DisbursementService.php' => 5,
        'app/Services/OwnerAccounting/FinaliseOwnerStatementRunService.php' => 2,
        'app/Services/OwnerAccounting/GenerateOwnerStatementRunService.php' => 1,
        'app/Services/OwnerAccounting/ReviseOwnerStatementRunService.php' => 1,

        // ── Leasing and space ────────────────────────────────────────────────────────────────
        'app/Services/AssignRentableItemService.php' => 1,
        // The "Record deposit movement" action: a refund/forfeit is a WRITE against the deposit
        // pot, and its cap was a check-then-act on the display twin outside any transaction — the
        // same defect SettleMoveOutService was fixed for, one action away.
        'app/Filament/Admin/Actions/LeaseActions.php' => 1,
        // Four: the deposit pot spans invoices, deposit_transactions and deposit_applications, and
        // `depositHeldForUpdate()` must pin all three or the two it did not are free to move. The
        // fourth pins the deposit-BILLING invoices, so "how much is still unasked for" cannot be
        // answered from before a concurrent invoice was raised.
        // The locking twin of `collectableBalance()` — the figure a tenant-driven capture is
        // written from, which must not be answered from a pre-lock snapshot.
        'app/Models/Invoice.php' => 1,
        'app/Models/Lease.php' => 4,
        // The locking read behind the double-booking guard. `LeaseCreationService` locks the UNIT
        // row (registered in PROVEN); this is the read of `leases` that the lock exists to make
        // authoritative, and without it the guard looks past the very lease it waited for.
        'app/Models/Unit.php' => 1,
        // The locking twin of `outstanding()`. `PayrollService::approve()` locks the advance row and
        // then has to read what is still owed on it; a plain read there answers from the snapshot
        // taken before it waited, so two runs each deducting within the pre-approval outstanding
        // both pass and together over-repay the loan.
        'app/Models/EmployeeAdvance.php' => 2,
        // One row lock per lease, re-checking its expiry inside the transaction, so a sweep cannot
        // expire a lease another request is renewing or holding over at the same moment.
        'app/Console/Commands/ExpireLeasesCommand.php' => 1,
        'app/Services/LeaseRenewalService.php' => 2,
        'app/Services/RemeasureUnitService.php' => 1,
        'app/Services/RentEscalationService.php' => 1,

        // ── Facility ─────────────────────────────────────────────────────────────────────────
        'app/Services/ApplySlaPenaltyService.php' => 3,
        // The locking twin of `SlaPenalty::bill()`. Both paths that RELEASE a penalty — `detach()`
        // and `waive()`, in two DIFFERENT services — call `VendorBill::recompute()`, which rewrites
        // the bill's derived money from every applied penalty on it. They locked the penalty only
        // and reached the bill through the plain relation, so a release racing an apply erased the
        // other penalty's deduction and overstated the payable. One method rather than a lock in
        // each service, because two copies of one rule is how the second is forgotten — which is
        // what this was.
        'app/Models/SlaPenalty.php' => 1,
        'app/Services/AssessSlaPenaltyService.php' => 2,
        'app/Services/AttributeWorkOrderFaultService.php' => 1,
        'app/Services/RaiseCorrectiveWorkOrderService.php' => 3,
        'app/Services/StockMovementService.php' => 1,
        'app/Services/WorkOrderPartService.php' => 5,

        // ── Marketing posts ──────────────────────────────────────────────────────────────────
        'app/Services/MarketingPost/ArchiveMarketingPostService.php' => 1,
        'app/Services/MarketingPost/PublishMarketingPostService.php' => 1,
        'app/Services/MarketingPost/RejectMarketingPostService.php' => 1,
        'app/Services/MarketingPost/SubmitMarketingPostService.php' => 2,

        // ── Cross-cutting ────────────────────────────────────────────────────────────────────
        // A BLOCKING cache lock around every numbered-document insert, so two concurrent creates
        // cannot take the same number. Not a row lock — the thing being protected is a sequence.
        'app/Models/Concerns/AllocatesDocumentNumber.php' => 1,
        // `app/Support/Health.php` was registered here at 2 locks, described as a probe that
        // acquires one to prove the cache driver can. It acquires NONE — it mentions `Cache::lock()`
        // once in a docblock and once inside an operator-facing message, and the scanner was
        // counting those two sentences. A registry entry for a file that is not a critical section
        // is worse than no entry: it reports coverage of a guard that does not exist. Removed when
        // the scanner learnt to read code rather than prose (2026-09-01).
    ];

    /** @return array<string, int> file => expected lock count, across both tiers */
    /**
     * How many lock acquisitions this registry accounts for, across both tiers.
     *
     * Derived rather than written down, because a hand-typed count in a docblock is the thing this
     * project has repeatedly found stale — the two prose figures this replaced said 111 and 118
     * while the registry held 134.
     */
    public static function totalLocks(): int
    {
        $count = array_sum(array_column(self::PROVEN, 'locks'));

        foreach (self::REGISTERED as $locks) {
            $count += is_array($locks) ? $locks['locks'] : $locks;
        }

        return $count;
    }

    public static function expected(): array
    {
        $proven = [];
        foreach (self::PROVEN as $file => $entry) {
            $proven[$file] = $entry['locks'];
        }

        return $proven + self::REGISTERED;
    }
}
