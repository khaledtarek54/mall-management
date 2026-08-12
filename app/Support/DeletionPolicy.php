<?php

namespace App\Support;

/**
 * What may be deleted, and what must be corrected instead.
 *
 * **The decision (operator, 2026-07-31).** A financial document is not a row you remove — it is a
 * record of something that happened. Under Egyptian bookkeeping (and ETA e-invoicing once live) an
 * issued invoice is a legal record: you cancel it or credit-note it, and the trail survives. This
 * system already speaks that language — cancel, void, credit note, reverse, un-apply — and every
 * one of those leaves a document explaining the change. Delete was a fifth path that bypassed all
 * of them.
 *
 * **What it looked like before.** `Invoice`, `Payment` and `JournalEntry` had no deletion guard at
 * all (only `CreditNote` and `MeterReading` did), and every one of the eight money resources
 * offered a Delete button on its Edit page. Soft deletes meant nothing was destroyed — but a
 * super_admin could delete a PAID invoice and the tenant's AR would simply change, with no
 * cancellation, no reason, and nothing to show an auditor. The money moved and the paperwork
 * didn't.
 *
 * **The tiers.**
 *
 * - `NEVER` — money and audit records. No Delete button, no `.delete` permission, and a model-level
 *   guard as the backstop. Correct via the document's own correction path.
 * - `WHEN_UNUSED` — master data that history can point at. Deletable only while nothing references
 *   it; once it has been used, deletion is REFUSED and the operator is pointed at deactivation.
 *   Operator's call 2026-07-31, matching Yardi/MRI/Entrata: none of them offer
 *   "delete anyway with a warning", because the damage lands on the reports and audit trail that
 *   referenced the record, not on the record itself.
 * - Everything else keeps the standard gate: super_admin only, soft-deleted, bulk-delete off.
 *
 * `DeletionPolicyConformanceTest` fails the build when a NEVER model's resource ships a Delete
 * action or its permission reappears — the hand-maintained delete test it replaces covered 10 of
 * 41 resources, which is how a gap this size stayed invisible.
 */
class DeletionPolicy
{
    /**
     * Money and audit records: never deletable, with the correction path that replaces it.
     *
     * @var array<class-string, string>
     */
    public const NEVER_DELETABLE = [
        \App\Models\Invoice::class => 'cancel the invoice, or issue a credit note',
        \App\Models\Payment::class => 'void the payment (VoidPaymentService) — it reverses the GL and re-opens the invoice',
        \App\Models\JournalEntry::class => 'post a reversing entry; a posted entry is never removed',
        \App\Models\CreditNote::class => 'cancel the note — it un-applies against the original invoice',
        \App\Models\VendorBill::class => 'cancel the bill',
        \App\Models\Expense::class => 'cancel the expense',
        \App\Models\DepositTransaction::class => 'reverse the deposit transaction',
        \App\Models\Payroll::class => 'cancel the run — payslips and their GL entries follow it',
        \App\Models\PostDatedCheque::class => 'cancel or bounce the cheque',

        // Money and audit records reached through a parent rather than their own screen. Each was
        // checked for a deletion call site in app/ before being listed — none has one, so guarding
        // them removes nothing that works today.
        \App\Models\Disbursement::class => 'cancel the disbursement — it is a GL source and an owner payout',
        \App\Models\StockMovement::class => 'post a correcting movement; the original is what the GL was built from',
        \App\Models\DepreciationEntry::class => 'reverse the depreciation run',
        \App\Models\VendorBillPayment::class => 'void the payment — money left the bank',
        \App\Models\FixedAssetDisposal::class => 'reverse the disposal',
        \App\Models\MaintenancePenalty::class => 'waive or release the penalty — it feeds the vendor bill',
        \App\Models\LeaseEvent::class => 'record the correcting event — a lease event is an assertion about something that happened, and the model refuses updates and deletes outright (no deletion call site exists in app/, so guarding it removes nothing that works)',
    ];


    /**
     * Master data: deletable only while nothing in the system points at it.
     *
     * **The industry standard, and why.** Yardi, MRI and Entrata all refuse to remove a record
     * that carries history — a tenant with ledger activity becomes *Past*, a vendor goes on
     * *Hold*, a unit goes *Down*. None of them offer "delete anyway with a warning", because the
     * damage is not to the record you deleted: it is to every report, statement and audit trail
     * that referenced it. A warning dialog puts that decision on whoever is clicking fastest.
     *
     * So the rule is **refuse, and say what to do instead**. A record with no history is a genuine
     * mistake — a vendor typed twice, a unit created in error — and stays deletable, because
     * nothing is lost and the alternative is an operator whose lists fill with rubbish they cannot
     * clear.
     *
     * `blocked_by` names the relations that constitute history. They are checked against the model
     * at build time by `DeletionPolicyConformanceTest` — a mistyped relation would silently block
     * nothing, which is the failure mode this whole registry exists to avoid.
     *
     * @var array<class-string, array{blocked_by: array<int, string>, instead: string}>
     */
    public const WHEN_UNUSED = [
        \App\Models\Tenant::class => [
            // + postDatedCheques: a NEVER-deletable money record a tenant can hold before any invoice
            // (a year of PDCs lodged up front) — omitting it left a tenant with only lodged cheques
            // deletable, stranding them on the maturity dashboard (pre-go-live review).
            // + violations: directly tenant-scoped (no lease link), restrictOnDelete at the FK — so
            // a tenant with only violations was deletable-via-SQL-error rather than refused cleanly.
            'blocked_by' => ['leases', 'invoices', 'payments', 'creditNotes', 'salesDeclarations', 'maintenanceRequests', 'postDatedCheques', 'violations'],
            'instead' => 'set the tenant to inactive — the history stays queryable and the AR still ties out',
        ],
        \App\Models\Vendor::class => [
            // NOT `purchaseRequests`: a PR's vendor_id is nullable + nullOnDelete BY DESIGN — the
            // vendor there is a pre-award suggestion, not a commitment, and PurchaseRequest is
            // classified operational-ALLOWED. The actual AP obligation is the VendorBill, which
            // blocks. A vendor with only PRs and no bills/contracts/documents never transacted.
            'blocked_by' => ['bills', 'contracts', 'maintenanceRequests', 'documents'],
            'instead' => 'set the vendor to inactive (or blacklisted) — it disappears from every assignment picker without losing its bills',
        ],
        \App\Models\Lease::class => [
            // deposits + postDatedCheques are NEVER-deletable money records that reference the lease
            // and can exist BEFORE any invoice (a deposit is taken at signing, a year of PDCs lodged
            // up front) — so an invoices/charges-only list left a lease with a deposit or lodged
            // cheque deletable, stranding the money record (pre-go-live review).
            // 'events' because LeaseEvent is NEVER_DELETABLE: without it, force-deleting a lease
            // would cascade away the very audit records this registry promises to keep — the exact
            // shape of the Asset/financial-dimension omission found in the deletion-policy review.
            'blocked_by' => ['invoices', 'charges', 'salesDeclarations', 'camAllocations', 'maintenanceRequests', 'renewals', 'deposits', 'postDatedCheques', 'events'],
            'instead' => 'terminate the lease — that is the documented end of a tenancy, and it keeps the billing history',
        ],
        \App\Models\Floor::class => [
            // A floor with anything standing on it is part of the property's structure — deleting it
            // would orphan units and bays from the geography every report groups by.
            'blocked_by' => ['units', 'rentableItems'],
            'instead' => 'rename or re-order the floor — a floor that holds space is part of the property record',
        ],
        // A tax nothing is billed under is an unused draft and ordinary cleanup. One a charge
        // code points at explains what every invoice raised under it was taxed at, and deleting it
        // would cascade its whole rate ladder away — so the history stops being explicable rather
        // than the code stopping being useful. Deactivate instead: it disappears from every picker
        // and keeps answering for the past.
        \App\Models\TaxCode::class => [
            'blocked_by' => ['chargeCodes'],
            'instead' => 'deactivate the tax code — it leaves the pickers immediately and still explains what past documents were taxed at',
        ],
        \App\Models\RentableItem::class => [
            // A bay that has ever been let is part of the property record — the lease history and
            // its billing reference it. Withdraw it from letting instead; that is what
            // `out_of_service` is for, and it is the same call as a unit set to maintenance.
            'blocked_by' => ['leases'],
            'instead' => 'set the item out of service — an item that has been let is part of the property record',
        ],
        \App\Models\Unit::class => [
            // allLeases, NOT leases: a multi-unit lease keeps its extra units in the lease_unit
            // pivot, so the master-only relation would report a leased unit as never used.
            'blocked_by' => ['allLeases', 'maintenanceRequests', 'utilityMeters'],
            'instead' => 'set the unit to maintenance if it is out of service — a unit that has been leased is part of the property record',
        ],
        \App\Models\Asset::class => [
            // The property is the ROOT of the GL isolation dimension, so it carries the widest
            // history — and the financial/HR children's asset_id FKs are cascadeOnDelete, so a
            // MISSING blocker doesn't just orphan on delete, a force-delete DESTROYS them outright,
            // incl. a NEVER-deletable MaintenancePenalty, bypassing every model guard. journalEntries
            // is the GL catch-all (every posting stamps asset_id); the direct money records are
            // listed too so an UN-posted one still blocks. (Pre-go-live review — the original list of
            // 4 physical relations left the whole money/HR/GL dimension un-guarded.)
            // NB: NOT `owners` — archiving an owned property (the ownership pivot populated) is a
            // legitimate, tested flow, and ownership is a relationship, not money/audit history the
            // books depend on. The blockers below are the money/HR/register children whose loss
            // corrupts or destroys the books.
            'blocked_by' => [
                'units', 'leases', 'camPools', 'utilityMeters',
                'journalEntries', 'expenses', 'vendorBills', 'payrolls', 'disbursements',
                'maintenancePenalties', 'depositTransactions', 'postDatedCheques',
                'employees', 'fixedAssets', 'marketingBudgets', 'violations',
            ],
            'instead' => 'deactivate the property — deleting one would orphan (or cascade-destroy) every book, payroll, register and penalty that reports on it',
        ],
        \App\Models\Employee::class => [
            'blocked_by' => ['payrollLines', 'advances', 'custodies'],
            'instead' => 'set the employee inactive — payroll history is a statutory record',
        ],
        \App\Models\LedgerAccount::class => [
            // + accountMappings: a source→account mapping (restrictOnDelete) is what makes an account
            // a posting target BEFORE anything posts to it — without this, a mapped-but-unposted
            // account failed on a DB constraint instead of the friendly refusal.
            'blocked_by' => ['lines', 'children', 'accountMappings'],
            'instead' => 'deactivate the account — removing one that has been posted to breaks every prior statement',
        ],
        \App\Models\AccountingPeriod::class => [
            'blocked_by' => ['entries'],
            'instead' => 'a period that has been posted to is part of the books; close it rather than remove it',
        ],
        \App\Models\CamExpensePool::class => [
            'blocked_by' => ['allocations'],
            'instead' => 'void the allocations first — they are what tenants were billed from',
        ],
        \App\Models\Department::class => [
            // members = app Users (RBAC); employees = HR staff. Two different dimensions — the
            // employees.department_id FK is nullOnDelete, so a department with staff would be
            // deletable and silently un-assign them. Both block.
            'blocked_by' => ['members', 'employees'],
            'instead' => 'move its members first, then delete the empty department',
        ],
        \App\Models\Warehouse::class => [
            'blocked_by' => ['movements'],
            'instead' => 'a warehouse with stock history is part of the inventory record',
        ],
        \App\Models\InventoryItem::class => [
            'blocked_by' => ['movements'],
            'instead' => 'deactivate the item — its movements are what the stock valuation was built from',
        ],
    ];

    /**
     * DELIBERATELY NOT LISTED: UtilityMeter.
     *
     * Soft-deleting a meter is already this product's retirement mechanism, and it works — the
     * module-10 close-out fixed the energy trend to exclude soft-deleted meters, and the readings
     * survive because they are what past recharges were billed from. Adding it here would have
     * overridden a deliberate, tested design with a generic rule; the existing scenario test caught
     * that, which is the only reason this note exists rather than a regression.
     *
     * The distinction the tier is actually drawing: does removing the record HIDE history that
     * reports must still show (tenant, lease, unit, property), or is it a supported retirement the
     * system already handles end-to-end (meter)?
     */


    /**
     * Everything else, and WHY each is safe to delete.
     *
     * The point of listing these is not to restrict them — they keep the standard gate
     * (super_admin only, soft-deleted, bulk-delete off). It is that "nobody classified this yet"
     * and "we decided this is fine" look identical from the outside, and the first one is how a
     * money record ends up deletable by accident. `DeletionPolicyConformanceTest` fails on any
     * model missing from all three registers, so model #63 forces a decision instead of inheriting
     * a default.
     *
     * Three recurring reasons, and the third is the one that matters:
     *
     *  - **parent-managed** — a child row its parent rebuilds or removes as part of a documented
     *    workflow. Guarding these would break the workflow, not protect it. Verified against real
     *    call sites: `CreditNoteApplication` is deleted to un-apply a credit,
     *    `TenantCreditApplication` is soft-deleted to reverse an applied credit, `OwnerStatement`
     *    is force-deleted when a run is rebuilt, `EmployeeAdvanceRepayment` when a repayment is
     *    reversed, `CustodyTransaction` on settlement. Every one of those would have broken.
     *  - **configuration** — setup data with no financial footprint of its own.
     *  - **operational** — records that describe work, not money.
     *
     * @var array<class-string, string>
     */
    public const ALLOWED = [
        // parent-managed children (deleting these IS the workflow)
        \App\Models\InvoiceItem::class => 'parent-managed: rebuilt whenever the invoice is recomputed',
        \App\Models\CreditNoteItem::class => 'parent-managed: rebuilt with its credit note',
        \App\Models\CreditNoteApplication::class => 'parent-managed: deleted to UN-APPLY a credit note',
        \App\Models\TenantCreditApplication::class => 'parent-managed: soft-deleted to reverse an applied tenant credit',
        \App\Models\DepositApplication::class => 'parent-managed: soft-deleted to reverse a deposit netted against an invoice (ApplyDepositToInvoiceService::reverse), which re-opens the AR and returns the deposit balance',
        \App\Models\StraightLineRentAdjustment::class => 'parent-managed: soft-deleted to reverse a month\'s rent-recognition adjustment (PostStraightLineRentService::reverseFrom), which voids its journal entry — the path a forward-only re-derivation uses after an amendment',
        \App\Models\InvoiceWriteOff::class => 'parent-managed: soft-deleted to reverse a bad-debt write-off (WriteOffInvoiceService::reverse), which voids the GL entry and re-opens the invoice. NEVER_DELETABLE would have broken that recovery path — the exact trap CLAUDE.md warns about before adding a model to NEVER',
        \App\Models\JournalLine::class => 'parent-managed: rebuilt when its entry is re-posted',
        \App\Models\PayrollLine::class => 'parent-managed: rebuilt when payslips are regenerated',
        \App\Models\OwnerStatement::class => 'parent-managed: force-deleted when its run is rebuilt',
        \App\Models\EmployeeAdvanceRepayment::class => 'parent-managed: deleted to reverse a repayment',
        \App\Models\CustodyTransaction::class => 'parent-managed: removed on settlement',
        \App\Models\MaintenanceWorkOrderItem::class => 'parent-managed: edited as part of the work order',
        \App\Models\MaintenanceWorkOrderPart::class => 'parent-managed: edited as part of the work order',
        \App\Models\PurchaseRequestLine::class => 'parent-managed: edited while the request is still a draft',
        \App\Models\VendorContractAmendment::class => 'parent-managed: append-only in practice, removable while unsent',
        \App\Models\OwnerRequestReply::class => 'parent-managed: belongs to its thread',
        \App\Models\TenantRequestComment::class => 'parent-managed: belongs to its request',
        \App\Models\LeaseCamTerm::class => 'parent-managed: effective-dated terms on a lease',
        \App\Models\LeasePercentageRentTier::class => 'parent-managed: one band of a lease\'s breakpoint ladder, edited from the lease',
        \App\Models\LeaseOption::class => 'parent-managed: the optionality recorded on a lease, edited from it. An option that was never really in the contract is removed; one that WAS is resolved (exercised/lapsed/waived), which keeps the history',
        \App\Models\AssetOwner::class => 'parent-managed: the ownership pivot, edited from the property',
        \App\Models\DeviceToken::class => 'parent-managed: pruned automatically when a push token goes dead',

        // configuration / setup
        \App\Models\AccountMapping::class => 'configuration: which account a source posts to',
        \App\Models\UnitArea::class => 'parent-managed: one measurement of a unit for a period, edited from the unit. A wrong figure is corrected by recording a new measurement — the register is what makes a past period explicable, so rows are not removed',
        \App\Models\ChargeCode::class => 'configuration: the billing vocabulary. A code the engine references by name is refused at the screen; an operator-added one that was never billed is ordinary cleanup',
        // Parent-managed: one rung of a tax code's dated ladder, edited from the code. A rung
        // posts nothing and settles nothing — issued documents carry their own rate and are
        // never re-rated — so removing one changes what is billed NEXT and no history. Same
        // call, and same reasoning, as LeaseCamTerm's effective-dated terms.
        \App\Models\TaxRate::class => 'parent-managed: effective-dated rates on a tax code, edited from the code',
        // A bookmark. It records no money, explains no balance and is referenced by nothing —
        // deleting one loses a set of filters its owner chose to keep, and nothing else.
        \App\Models\SavedReport::class => 'preference: a saved set of report filters, owned by the operator who saved it',
        \App\Models\ApprovalRule::class => 'configuration: approval bands',
        // Configuration today: nothing references a bank account yet. Slice 2 of the
        // reconciliation plan adds statements, and this MUST become WHEN_UNUSED blocked_by them at
        // that point — an account with reconciled statements behind it explains a balance.
        \App\Models\BankAccount::class => 'configuration: the operator\'s bank accounts (revisit when statements exist)',
        // Evidence, not a money record: a statement posts nothing, so removing a mis-imported one
        // changes no balance. Re-import is the correction, and the unique (account, period) index is
        // what makes deleting the wrong one the only way to re-import it.
        \App\Models\BankStatement::class => 'evidence: re-import the statement',
        \App\Models\BankStatementLine::class => 'evidence: parent-managed, rebuilt on re-import',
        // Unmatching IS the correction, and it deletes the row. A match posted nothing, so removing
        // one changes no balance — the reason this is annotation rather than a money record.
        \App\Models\BankMatch::class => 'annotation: unmatch it',
        \App\Models\SlaPolicy::class => 'configuration: SLA targets',
        // Clearing an override IS the correction — it restores the portfolio's answer, which is
        // always available. Nothing posted, so removing one changes no balance.
        \App\Models\PropertySetting::class => 'configuration: a per-property override; deleting restores the portfolio default',
        \App\Models\SystemSetting::class => 'configuration',
        \App\Models\Area::class => 'configuration: a zone used for routing',
        \App\Models\Equipment::class => 'configuration: an asset register entry with no ledger of its own',
        \App\Models\MaintenancePlan::class => 'configuration: a PPM schedule',
        \App\Models\MarketingBudget::class => 'configuration: a spend envelope',
        \App\Models\FiscalYear::class => 'configuration: its periods carry the entries, and they are guarded',
        \App\Models\Charge::class => 'configuration: a recurring billing line; issued invoices keep their own copy',
        \App\Models\Note::class => 'configuration: a free-text note',
        \App\Models\Announcement::class => 'configuration: a notice board post',

        // operational records (work, not money)
        \App\Models\MaintenanceWorkOrder::class => 'operational: a job record',
        \App\Models\TenantRequest::class => 'operational: terminal states are already immutable',
        \App\Models\OwnerRequest::class => 'operational: responded requests are already immutable',
        \App\Models\PurchaseRequest::class => 'operational: its GRNI posting lives on the vendor bill',
        \App\Models\Violation::class => 'operational: force-delete is already blocked once a fine is billed',
        \App\Models\MeterReading::class => 'operational: already refuses deletion once billed',
        \App\Models\TenantSalesDeclaration::class => 'operational: locking is what makes it billable, and a locked one voids rather than deletes',
        \App\Models\CamAllocation::class => 'operational: voided through the pool, not removed',
        \App\Models\MarketingSpend::class => 'operational: a spend line',
        \App\Models\MarketingPost::class => 'operational: shopper-facing content, not a record of anything that happened. `archived` is the retirement path an operator should use (it keeps the campaign in the register with its engagement counters), but a post typed by mistake — wrong mall, duplicated draft, artwork that never ran — is genuinely a row that should not exist, and refusing it would leave the register full of things the marketing team has to mentally skip. Soft-deletes, so a mis-delete is recoverable',
        \App\Models\LowStockAlert::class => 'operational: a transient alert',
        \App\Models\Custody::class => 'operational: settled through SettleCustodyService',
        \App\Models\EmployeeAdvance::class => 'operational: reversed rather than removed',
        \App\Models\VendorContract::class => 'operational: expired/terminated rather than removed',
        \App\Models\VendorDocument::class => 'operational: superseded by a newer certificate',
        \App\Models\TenantDocument::class => 'operational: superseded by a newer certificate',
        \App\Models\VendorContact::class => 'operational: a contact person',
        \App\Models\OwnerStatementRun::class => 'operational: superseded by a new version rather than removed',
        \App\Models\UtilityMeter::class => 'operational: soft-delete IS the retirement path, and the energy trend already excludes retired meters',
        \App\Models\FixedAsset::class => 'operational: soft-delete IS the retirement path — the sweep voids the asset\'s entire GL footprint, which a scenario test pins',

        // identity
        \App\Models\User::class => 'identity: deactivated in practice; delete stays super_admin-only',
        \App\Models\TenantUser::class => 'identity: a portal login',
    ];

    /** Is this model deletable only while unreferenced? */
    public static function isDeletableWhenUnused(string $model): bool
    {
        return array_key_exists($model, self::WHEN_UNUSED);
    }

    /** The relations that constitute history for this model. */
    public static function blockingRelationsFor(string $model): array
    {
        return self::WHEN_UNUSED[$model]['blocked_by'] ?? [];
    }

    /** What the operator should do instead of deleting. */
    public static function insteadFor(string $model): ?string
    {
        return self::WHEN_UNUSED[$model]['instead'] ?? null;
    }

    /** Is this model one the books depend on? */
    public static function isNeverDeletable(string $model): bool
    {
        return array_key_exists($model, self::NEVER_DELETABLE);
    }

    /** How the operator is told to correct this record instead of deleting it. */
    public static function correctionFor(string $model): ?string
    {
        return self::NEVER_DELETABLE[$model] ?? null;
    }

    /**
     * The `{module}.delete` permissions that must no longer exist.
     *
     * Removing the Delete button is the UI half; leaving the permission seeded would let a role be
     * granted a right nothing can exercise, and would quietly restore the button the moment
     * someone re-added the action.
     *
     * @var array<int, string>
     */
    public const RETIRED_PERMISSIONS = [
        'invoices.delete',
        'payments.delete',
        'journal_entries.delete',
        'credit_notes.delete',
        'vendor_bills.delete',
        'expenses.delete',
        'deposit_transactions.delete',
        'payrolls.delete',
        'post_dated_cheques.delete',
    ];
}
