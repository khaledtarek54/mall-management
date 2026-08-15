<?php

namespace App\Support;

use App\Models\AccountingPeriod;
use App\Models\AccountMapping;
use App\Models\Announcement;
use App\Models\ApprovalRule;
use App\Models\Area;
use App\Models\Asset;
use App\Models\AssetOwner;
use App\Models\BankAccount;
use App\Models\BankMatch;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Models\Charge;
use App\Models\ChargeCode;
use App\Models\CreditNote;
use App\Models\CreditNoteApplication;
use App\Models\CreditNoteItem;
use App\Models\Custody;
use App\Models\CustodyTransaction;
use App\Models\Department;
use App\Models\DepositApplication;
use App\Models\DepositTransaction;
use App\Models\DepreciationEntry;
use App\Models\DeviceToken;
use App\Models\Disbursement;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeAdvanceRepayment;
use App\Models\Equipment;
use App\Models\Expense;
use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Models\FixedAssetDisposal;
use App\Models\Floor;
use App\Models\InventoryItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceWriteOff;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Lease;
use App\Models\LeaseCamTerm;
use App\Models\LeaseEvent;
use App\Models\LeaseOption;
use App\Models\LeasePercentageRentTier;
use App\Models\LedgerAccount;
use App\Models\LowStockAlert;
use App\Models\MaintenancePenalty;
use App\Models\MaintenancePlan;
use App\Models\MaintenanceWorkOrder;
use App\Models\MaintenanceWorkOrderItem;
use App\Models\MaintenanceWorkOrderPart;
use App\Models\MarketingBudget;
use App\Models\MarketingPost;
use App\Models\MarketingSpend;
use App\Models\MeterReading;
use App\Models\Note;
use App\Models\OwnerRequest;
use App\Models\OwnerRequestReply;
use App\Models\OwnerStatement;
use App\Models\OwnerStatementRun;
use App\Models\Payment;
use App\Models\Payroll;
use App\Models\PayrollLine;
use App\Models\PostDatedCheque;
use App\Models\PropertySetting;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestLine;
use App\Models\RentableItem;
use App\Models\ReportPreference;
use App\Models\SavedReport;
use App\Models\SlaPolicy;
use App\Models\StockMovement;
use App\Models\StraightLineRentAdjustment;
use App\Models\SystemSetting;
use App\Models\TableView;
use App\Models\TaxCode;
use App\Models\TaxRate;
use App\Models\Tenant;
use App\Models\TenantCreditApplication;
use App\Models\TenantDocument;
use App\Models\TenantRequest;
use App\Models\TenantRequestComment;
use App\Models\TenantSalesDeclaration;
use App\Models\TenantUser;
use App\Models\Unit;
use App\Models\UnitArea;
use App\Models\UnitOwnership;
use App\Models\User;
use App\Models\UtilityMeter;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Models\VendorBillPayment;
use App\Models\VendorContact;
use App\Models\VendorContract;
use App\Models\VendorContractAmendment;
use App\Models\VendorDocument;
use App\Models\Violation;
use App\Models\Warehouse;

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
        Invoice::class => 'cancel the invoice, or issue a credit note',
        Payment::class => 'void the payment (VoidPaymentService) — it reverses the GL and re-opens the invoice',
        JournalEntry::class => 'post a reversing entry; a posted entry is never removed',
        CreditNote::class => 'cancel the note — it un-applies against the original invoice',
        VendorBill::class => 'cancel the bill',
        Expense::class => 'cancel the expense',
        DepositTransaction::class => 'reverse the deposit transaction',
        Payroll::class => 'cancel the run — payslips and their GL entries follow it',
        PostDatedCheque::class => 'cancel or bounce the cheque',

        // Money and audit records reached through a parent rather than their own screen. Each was
        // checked for a deletion call site in app/ before being listed — none has one, so guarding
        // them removes nothing that works today.
        Disbursement::class => 'cancel the disbursement — it is a GL source and an owner payout',
        StockMovement::class => 'post a correcting movement; the original is what the GL was built from',
        DepreciationEntry::class => 'reverse the depreciation run',
        VendorBillPayment::class => 'void the payment — money left the bank',
        FixedAssetDisposal::class => 'reverse the disposal',
        MaintenancePenalty::class => 'waive or release the penalty — it feeds the vendor bill',
        LeaseEvent::class => 'record the correcting event — a lease event is an assertion about something that happened, and the model refuses updates and deletes outright (no deletion call site exists in app/, so guarding it removes nothing that works)',
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
        Tenant::class => [
            // + postDatedCheques: a NEVER-deletable money record a tenant can hold before any invoice
            // (a year of PDCs lodged up front) — omitting it left a tenant with only lodged cheques
            // deletable, stranding them on the maturity dashboard (pre-go-live review).
            // + violations: directly tenant-scoped (no lease link), restrictOnDelete at the FK — so
            // a tenant with only violations was deletable-via-SQL-error rather than refused cleanly.
            'blocked_by' => ['leases', 'invoices', 'payments', 'creditNotes', 'salesDeclarations', 'maintenanceRequests', 'postDatedCheques', 'violations'],
            'instead' => 'set the tenant to inactive — the history stays queryable and the AR still ties out',
        ],
        Vendor::class => [
            // NOT `purchaseRequests`: a PR's vendor_id is nullable + nullOnDelete BY DESIGN — the
            // vendor there is a pre-award suggestion, not a commitment, and PurchaseRequest is
            // classified operational-ALLOWED. The actual AP obligation is the VendorBill, which
            // blocks. A vendor with only PRs and no bills/contracts/documents never transacted.
            'blocked_by' => ['bills', 'contracts', 'maintenanceRequests', 'documents'],
            'instead' => 'set the vendor to inactive (or blacklisted) — it disappears from every assignment picker without losing its bills',
        ],
        Lease::class => [
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
        UnitOwnership::class => [
            // A sale that has billed anything is history, and a resale is `ended_at` — never a
            // delete, or every invoice and statement that quoted this ownership loses its basis.
            // Deletable only while it is a mistyped record that never reached the books.
            //
            // `invoices` and `charges` are the two that can reference it (phase 2 adds both FKs);
            // until they exist the list is empty of live blockers, which is correct — an ownership
            // recorded today genuinely has nothing behind it yet.
            'blocked_by' => ['invoices', 'charges'],
            'instead' => 'transfer the ownership — that is the documented end of a holding, and it keeps the assessment history and the arrears at handover',
        ],
        Floor::class => [
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
        TaxCode::class => [
            'blocked_by' => ['chargeCodes'],
            'instead' => 'deactivate the tax code — it leaves the pickers immediately and still explains what past documents were taxed at',
        ],
        RentableItem::class => [
            // A bay that has ever been let is part of the property record — the lease history and
            // its billing reference it. Withdraw it from letting instead; that is what
            // `out_of_service` is for, and it is the same call as a unit set to maintenance.
            'blocked_by' => ['leases'],
            'instead' => 'set the item out of service — an item that has been let is part of the property record',
        ],
        Unit::class => [
            // allLeases, NOT leases: a multi-unit lease keeps its extra units in the lease_unit
            // pivot, so the master-only relation would report a leased unit as never used.
            'blocked_by' => ['allLeases', 'maintenanceRequests', 'utilityMeters'],
            'instead' => 'set the unit to maintenance if it is out of service — a unit that has been leased is part of the property record',
        ],
        Asset::class => [
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
        Employee::class => [
            'blocked_by' => ['payrollLines', 'advances', 'custodies'],
            'instead' => 'set the employee inactive — payroll history is a statutory record',
        ],
        LedgerAccount::class => [
            // + accountMappings: a source→account mapping (restrictOnDelete) is what makes an account
            // a posting target BEFORE anything posts to it — without this, a mapped-but-unposted
            // account failed on a DB constraint instead of the friendly refusal.
            'blocked_by' => ['lines', 'children', 'accountMappings'],
            'instead' => 'deactivate the account — removing one that has been posted to breaks every prior statement',
        ],
        AccountingPeriod::class => [
            'blocked_by' => ['entries'],
            'instead' => 'a period that has been posted to is part of the books; close it rather than remove it',
        ],
        CamExpensePool::class => [
            'blocked_by' => ['allocations'],
            'instead' => 'void the allocations first — they are what tenants were billed from',
        ],
        Department::class => [
            // members = app Users (RBAC); employees = HR staff. Two different dimensions — the
            // employees.department_id FK is nullOnDelete, so a department with staff would be
            // deletable and silently un-assign them. Both block.
            'blocked_by' => ['members', 'employees'],
            'instead' => 'move its members first, then delete the empty department',
        ],
        Warehouse::class => [
            'blocked_by' => ['movements'],
            'instead' => 'a warehouse with stock history is part of the inventory record',
        ],
        InventoryItem::class => [
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
        InvoiceItem::class => 'parent-managed: rebuilt whenever the invoice is recomputed',
        CreditNoteItem::class => 'parent-managed: rebuilt with its credit note',
        CreditNoteApplication::class => 'parent-managed: deleted to UN-APPLY a credit note',
        TenantCreditApplication::class => 'parent-managed: soft-deleted to reverse an applied tenant credit',
        DepositApplication::class => 'parent-managed: soft-deleted to reverse a deposit netted against an invoice (ApplyDepositToInvoiceService::reverse), which re-opens the AR and returns the deposit balance',
        StraightLineRentAdjustment::class => 'parent-managed: soft-deleted to reverse a month\'s rent-recognition adjustment (PostStraightLineRentService::reverseFrom), which voids its journal entry — the path a forward-only re-derivation uses after an amendment',
        InvoiceWriteOff::class => 'parent-managed: soft-deleted to reverse a bad-debt write-off (WriteOffInvoiceService::reverse), which voids the GL entry and re-opens the invoice. NEVER_DELETABLE would have broken that recovery path — the exact trap CLAUDE.md warns about before adding a model to NEVER',
        JournalLine::class => 'parent-managed: rebuilt when its entry is re-posted',
        PayrollLine::class => 'parent-managed: rebuilt when payslips are regenerated',
        OwnerStatement::class => 'parent-managed: force-deleted when its run is rebuilt',
        EmployeeAdvanceRepayment::class => 'parent-managed: deleted to reverse a repayment',
        CustodyTransaction::class => 'parent-managed: removed on settlement',
        MaintenanceWorkOrderItem::class => 'parent-managed: edited as part of the work order',
        MaintenanceWorkOrderPart::class => 'parent-managed: edited as part of the work order',
        PurchaseRequestLine::class => 'parent-managed: edited while the request is still a draft',
        VendorContractAmendment::class => 'parent-managed: append-only in practice, removable while unsent',
        OwnerRequestReply::class => 'parent-managed: belongs to its thread',
        TenantRequestComment::class => 'parent-managed: belongs to its request',
        LeaseCamTerm::class => 'parent-managed: effective-dated terms on a lease',
        LeasePercentageRentTier::class => 'parent-managed: one band of a lease\'s breakpoint ladder, edited from the lease',
        LeaseOption::class => 'parent-managed: the optionality recorded on a lease, edited from it. An option that was never really in the contract is removed; one that WAS is resolved (exercised/lapsed/waived), which keeps the history',
        AssetOwner::class => 'parent-managed: the ownership pivot, edited from the property',
        DeviceToken::class => 'parent-managed: pruned automatically when a push token goes dead',

        // configuration / setup
        AccountMapping::class => 'configuration: which account a source posts to',
        UnitArea::class => 'parent-managed: one measurement of a unit for a period, edited from the unit. A wrong figure is corrected by recording a new measurement — the register is what makes a past period explicable, so rows are not removed',
        ChargeCode::class => 'configuration: the billing vocabulary. A code the engine references by name is refused at the screen; an operator-added one that was never billed is ordinary cleanup',
        // Parent-managed: one rung of a tax code's dated ladder, edited from the code. A rung
        // posts nothing and settles nothing — issued documents carry their own rate and are
        // never re-rated — so removing one changes what is billed NEXT and no history. Same
        // call, and same reasoning, as LeaseCamTerm's effective-dated terms.
        TaxRate::class => 'parent-managed: effective-dated rates on a tax code, edited from the code',
        // A bookmark. It records no money, explains no balance and is referenced by nothing —
        // deleting one loses a set of filters its owner chose to keep, and nothing else.
        SavedReport::class => 'preference: a saved set of report filters, owned by the operator who saved it',
        TableView::class => 'preference: a saved filter/sort state for a resource list, owned by the operator who saved it — same reasoning as SavedReport above',
        ApprovalRule::class => 'configuration: approval bands',
        // Configuration today: nothing references a bank account yet. Slice 2 of the
        // reconciliation plan adds statements, and this MUST become WHEN_UNUSED blocked_by them at
        // that point — an account with reconciled statements behind it explains a balance.
        BankAccount::class => 'configuration: the operator\'s bank accounts (revisit when statements exist)',
        // Evidence, not a money record: a statement posts nothing, so removing a mis-imported one
        // changes no balance. Re-import is the correction, and the unique (account, period) index is
        // what makes deleting the wrong one the only way to re-import it.
        BankStatement::class => 'evidence: re-import the statement',
        BankStatementLine::class => 'evidence: parent-managed, rebuilt on re-import',
        // Unmatching IS the correction, and it deletes the row. A match posted nothing, so removing
        // one changes no balance — the reason this is annotation rather than a money record.
        BankMatch::class => 'annotation: unmatch it',
        SlaPolicy::class => 'configuration: SLA targets',
        // Clearing an override IS the correction — it restores the portfolio's answer, which is
        // always available. Nothing posted, so removing one changes no balance.
        PropertySetting::class => 'configuration: a per-property override; deleting restores the portfolio default',
        // A UI preference. Clearing it restores the report's own default, which is always available.
        ReportPreference::class => 'preference: one operator\'s remembered report filters',
        SystemSetting::class => 'configuration',
        Area::class => 'configuration: a zone used for routing',
        Equipment::class => 'configuration: an asset register entry with no ledger of its own',
        MaintenancePlan::class => 'configuration: a PPM schedule',
        MarketingBudget::class => 'configuration: a spend envelope',
        FiscalYear::class => 'configuration: its periods carry the entries, and they are guarded',
        Charge::class => 'configuration: a recurring billing line; issued invoices keep their own copy',
        Note::class => 'configuration: a free-text note',
        Announcement::class => 'configuration: a notice board post',

        // operational records (work, not money)
        MaintenanceWorkOrder::class => 'operational: a job record',
        TenantRequest::class => 'operational: terminal states are already immutable',
        OwnerRequest::class => 'operational: responded requests are already immutable',
        PurchaseRequest::class => 'operational: its GRNI posting lives on the vendor bill',
        Violation::class => 'operational: force-delete is already blocked once a fine is billed',
        MeterReading::class => 'operational: already refuses deletion once billed',
        TenantSalesDeclaration::class => 'operational: locking is what makes it billable, and a locked one voids rather than deletes',
        CamAllocation::class => 'operational: voided through the pool, not removed',
        MarketingSpend::class => 'operational: a spend line',
        MarketingPost::class => 'operational: shopper-facing content, not a record of anything that happened. `archived` is the retirement path an operator should use (it keeps the campaign in the register with its engagement counters), but a post typed by mistake — wrong mall, duplicated draft, artwork that never ran — is genuinely a row that should not exist, and refusing it would leave the register full of things the marketing team has to mentally skip. Soft-deletes, so a mis-delete is recoverable',
        LowStockAlert::class => 'operational: a transient alert',
        Custody::class => 'operational: settled through SettleCustodyService',
        EmployeeAdvance::class => 'operational: reversed rather than removed',
        VendorContract::class => 'operational: expired/terminated rather than removed',
        VendorDocument::class => 'operational: superseded by a newer certificate',
        TenantDocument::class => 'operational: superseded by a newer certificate',
        VendorContact::class => 'operational: a contact person',
        OwnerStatementRun::class => 'operational: superseded by a new version rather than removed',
        UtilityMeter::class => 'operational: soft-delete IS the retirement path, and the energy trend already excludes retired meters',
        FixedAsset::class => 'operational: soft-delete IS the retirement path — the sweep voids the asset\'s entire GL footprint, which a scenario test pins',

        // identity
        User::class => 'identity: deactivated in practice; delete stays super_admin-only',
        TenantUser::class => 'identity: a portal login',
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
