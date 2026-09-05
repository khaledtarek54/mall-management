<?php

namespace App\Support;

/**
 * **What order a table opens in, and why.**
 *
 * ## The question this answers
 *
 * The panel has 144 places that decide a table's row order, and until this registry each one was
 * decided on its own. Most were right; a handful sorted by a raw foreign key or by a surrogate
 * primary key where the record's own date or name existed, so the list opened in an order that
 * means nothing to the reader — `order by sla_policies.asset_id`, a people register sorted by
 * signup order, a payslip history sorted by insertion rather than by pay period.
 *
 * The convention is Yardi's, and it is NOT "newest first everywhere". A rent roll sorted by
 * creation date is unreadable; so is a chart of accounts. Yardi, MRI and Entrata all sort a
 * DOCUMENT register newest-first and a MASTER register by name or code, and this splits the same
 * way. {@see KINDS} names the five reasons a table can be ordered and the sixth escape hatch.
 *
 * ## What is deliberately NOT here
 *
 * **A pagination tie-breaker.** The obvious defect — 300 invoices raised by one billing run all
 * carrying the same `issue_date`, so page 2 repeats a row page 1 already showed — does not exist.
 * Filament appends the qualified primary key itself: `Table::$hasDefaultKeySort` defaults to true
 * and `CanSortRecords::applySortingToTableQuery()` adds `order by <table>.id` unless an order
 * already names it. Building one here would be a second answer to a question upstream already
 * answers, and it would drift.
 *
 * ## Adding a table
 *
 * Classify it. `TableSortPolicyConformanceTest` discovers every file that OWNS a row order —
 * configures a table, does not delegate to another table class, and is not fed from an array —
 * and fails on one that is neither classified nor {@see EXEMPT} with a reason. Discovery is from
 * disk rather than from this list, because a registry read only by the gate that guards it cannot
 * see what the registry omits.
 */
final class TableSortPolicy
{
    /** A register of dated documents. Newest first, on the document's own date. */
    public const LEDGER = 'ledger';

    /** Master data or a catalogue, read by name or code. Alphabetical / `sort_order`, ascending. */
    public const REGISTER = 'register';

    /** Owed action by a deadline. Soonest first — the top row is the next thing to do. */
    public const WORKLIST = 'worklist';

    /** The order IS the content: a thread, a schedule read forward, a statement's line order. */
    public const SEQUENCE = 'sequence';

    /** Ordered by a magnitude or flag that is itself the answer — biggest share first. */
    public const RANKED = 'ranked';

    /** A domain order expressed as a closure or query scope, stated at the call site. */
    public const CUSTOM = 'custom';

    public const KINDS = [self::LEDGER, self::REGISTER, self::WORKLIST, self::SEQUENCE, self::RANKED, self::CUSTOM];

    /**
     * Every file that owns a table's row order, keyed by its path under `app/Filament/`.
     *
     * @var array<string, string>
     */
    public const TABLES = [

        // ── LEDGER ────────────────────────────────────────────────────────────
        // A register of dated documents. NEWEST FIRST, on the document's own date.
        'Admin/RelationManagers/AssetStaffRelationManager' => self::LEDGER,
        'Admin/RelationManagers/CreditNoteApplicationsRelationManager' => self::LEDGER,
        'Admin/RelationManagers/CustodyTransactionsRelationManager' => self::LEDGER,
        'Admin/RelationManagers/DepartmentMembersRelationManager' => self::LEDGER,
        'Admin/RelationManagers/DepreciationEntriesRelationManager' => self::LEDGER,
        'Admin/RelationManagers/EmployeeAdvancesRelationManager' => self::LEDGER,
        'Admin/RelationManagers/EmployeePayslipsRelationManager' => self::LEDGER,
        'Admin/RelationManagers/LeaseCamTermsRelationManager' => self::LEDGER,
        'Admin/RelationManagers/LeaseDepositsRelationManager' => self::LEDGER,
        'Admin/RelationManagers/LeaseHistoryRelationManager' => self::LEDGER,
        'Admin/RelationManagers/LeaseInvoicesRelationManager' => self::LEDGER,
        'Admin/RelationManagers/LeaseRentableItemsRelationManager' => self::LEDGER,
        'Admin/RelationManagers/LeaseSalesDeclarationsRelationManager' => self::LEDGER,
        'Admin/RelationManagers/StockConsumptionRelationManager' => self::LEDGER,
        'Admin/RelationManagers/StockMovementsRelationManager' => self::LEDGER,
        'Admin/RelationManagers/TenantInvoicesRelationManager' => self::LEDGER,
        'Admin/RelationManagers/TenantLeasesRelationManager' => self::LEDGER,
        'Admin/RelationManagers/TenantNotesRelationManager' => self::LEDGER,
        'Admin/RelationManagers/TenantPaymentsRelationManager' => self::LEDGER,
        'Admin/RelationManagers/TenantRequestsRelationManager' => self::LEDGER,
        'Admin/RelationManagers/TenantSalesDeclarationsRelationManager' => self::LEDGER,
        'Admin/RelationManagers/TenantViolationsRelationManager' => self::LEDGER,
        'Admin/RelationManagers/UnitLeasesRelationManager' => self::LEDGER,
        'Admin/RelationManagers/UnitOwnershipRentableItemsRelationManager' => self::LEDGER,
        'Admin/RelationManagers/WorkOrderLabourRelationManager' => self::LEDGER,
        'Admin/RelationManagers/WorkOrderProposalsRelationManager' => self::LEDGER,
        'Admin/Resources/AccountingPeriods/Tables/AccountingPeriodsTable' => self::LEDGER,
        'Admin/Resources/Announcements/Tables/AnnouncementsTable' => self::LEDGER,
        'Admin/Resources/BankStatements/Tables/BankStatementsTable' => self::LEDGER,
        'Admin/Resources/CamExpensePools/Tables/CamExpensePoolsTable' => self::LEDGER,
        'Admin/Resources/CreditNotes/Tables/CreditNotesTable' => self::LEDGER,
        'Admin/Resources/Custodies/Tables/CustodiesTable' => self::LEDGER,
        'Admin/Resources/DepositTransactions/Tables/DepositTransactionsTable' => self::LEDGER,
        'Admin/Resources/Disbursements/Tables/DisbursementsTable' => self::LEDGER,
        'Admin/Resources/Expenses/Tables/ExpensesTable' => self::LEDGER,
        'Admin/Resources/FacilityWorkOrders/Tables/FacilityWorkOrdersTable' => self::LEDGER,
        'Admin/Resources/Holidays/Tables/HolidaysTable' => self::LEDGER,
        'Admin/Resources/Invoices/Tables/InvoicesTable' => self::LEDGER,
        'Admin/Resources/JournalEntries/Tables/JournalEntriesTable' => self::LEDGER,
        'Admin/Resources/Leases/Tables/LeasesTable' => self::LEDGER,
        'Admin/Resources/MarketingBudgets/RelationManagers/MarketingSpendsRelationManager' => self::LEDGER,
        'Admin/Resources/MarketingBudgets/Tables/MarketingBudgetsTable' => self::LEDGER,
        'Admin/Resources/OwnerRequests/Tables/OwnerRequestsTable' => self::LEDGER,
        'Admin/Resources/OwnerStatementRuns/Tables/OwnerStatementRunsTable' => self::LEDGER,
        'Admin/Resources/Payments/Tables/PaymentsTable' => self::LEDGER,
        'Admin/Resources/PayrollRates/Tables/PayrollRatesTable' => self::LEDGER,
        'Admin/Resources/Payrolls/Tables/PayrollsTable' => self::LEDGER,
        'Admin/Resources/PurchaseRequests/Tables/PurchaseRequestsTable' => self::LEDGER,
        'Admin/Resources/RentIndices/Tables/RentIndicesTable' => self::LEDGER,
        'Admin/Resources/StockMovements/Tables/StockMovementsTable' => self::LEDGER,
        'Admin/Resources/TaxCodes/RelationManagers/RatesRelationManager' => self::LEDGER,
        'Admin/Resources/TenantRequests/Tables/TenantRequestsTable' => self::LEDGER,
        'Admin/Resources/TenantSalesDeclarations/Tables/TenantSalesDeclarationsTable' => self::LEDGER,
        'Admin/Resources/UtilityMeters/RelationManagers/ReadingsRelationManager' => self::LEDGER,
        'Admin/Resources/UtilityTariffs/RelationManagers/RatesRelationManager' => self::LEDGER,
        'Admin/Resources/VendorBills/RelationManagers/VendorBillPaymentsRelationManager' => self::LEDGER,
        'Admin/Resources/VendorBills/Tables/VendorBillsTable' => self::LEDGER,
        'Admin/Resources/Vendors/RelationManagers/ContractsRelationManager' => self::LEDGER,
        'Admin/Resources/Violations/Tables/ViolationTable' => self::LEDGER,
        'Admin/Resources/WorkPermits/Tables/WorkPermitsTable' => self::LEDGER,
        'Admin/Widgets/RecentPayments' => self::LEDGER,
        'Portal/Resources/CamAllocations/Tables/CamAllocationsTable' => self::LEDGER,
        'Portal/Resources/CreditNotes/Tables/CreditNotesTable' => self::LEDGER,
        'Portal/Resources/Invoices/Tables/InvoicesTable' => self::LEDGER,
        'Portal/Resources/Leases/Tables/LeasesTable' => self::LEDGER,
        'Portal/Resources/Payments/Tables/PaymentsTable' => self::LEDGER,
        'Portal/Resources/TenantRequests/Tables/TenantRequestsTable' => self::LEDGER,
        'Portal/Resources/TenantSalesDeclarations/Tables/TenantSalesDeclarationsTable' => self::LEDGER,

        // ── REGISTER ──────────────────────────────────────────────────────────
        // Master data or a catalogue, read by name or code. Alphabetical / sort_order, ASCENDING.
        // The portfolio view of abstracted clauses, ordered by clause TYPE — the question it
        // answers is "which leases carry a co-tenancy trigger", so the type is the spine and A→Z
        // within it is what a reader scans. Classified 2026-09-01: it shipped with the clause
        // abstract and was never registered, so the gate had been counting 145 tables against a
        // premise of 144 — the premise is what caught it, exactly as intended.
        'Admin/Pages/ClauseRegister' => self::REGISTER,
        'Admin/Pages/OccupancyMap' => self::REGISTER,
        'Admin/Pages/RentableItemMap' => self::REGISTER,
        'Admin/RelationManagers/AssetFloorsRelationManager' => self::REGISTER,
        'Admin/RelationManagers/AssetRentableItemsRelationManager' => self::REGISTER,
        'Admin/RelationManagers/AssetUnitsRelationManager' => self::REGISTER,
        'Admin/RelationManagers/LeaseClausesRelationManager' => self::REGISTER,
        'Admin/RelationManagers/PayrollLinesRelationManager' => self::REGISTER,
        'Admin/RelationManagers/UnitMetersRelationManager' => self::REGISTER,
        'Admin/RelationManagers/WarehouseBinsRelationManager' => self::REGISTER,
        'Admin/Resources/AccountMappings/Tables/AccountMappingsTable' => self::REGISTER,
        'Admin/Resources/ApprovalRules/Tables/ApprovalRulesTable' => self::REGISTER,
        'Admin/Resources/Areas/Tables/AreaTable' => self::REGISTER,
        'Admin/Resources/Assets/Tables/AssetsTable' => self::REGISTER,
        'Admin/Resources/BankAccounts/Tables/BankAccountsTable' => self::REGISTER,
        'Admin/Resources/ChargeCodes/Tables/ChargeCodesTable' => self::REGISTER,
        'Admin/Resources/CustomFields/Tables/CustomFieldsTable' => self::REGISTER,
        'Admin/Resources/Departments/Tables/DepartmentsTable' => self::REGISTER,
        'Admin/Resources/DocumentTemplates/Tables/DocumentTemplatesTable' => self::REGISTER,
        'Admin/Resources/Employees/Tables/EmployeesTable' => self::REGISTER,
        'Admin/Resources/Equipment/Tables/EquipmentTable' => self::REGISTER,
        'Admin/Resources/ExpenseCategories/Tables/ExpenseCategoriesTable' => self::REGISTER,
        'Admin/Resources/FailureCodes/Tables/FailureCodesTable' => self::REGISTER,
        'Admin/Resources/FixedAssets/Tables/FixedAssetsTable' => self::REGISTER,
        'Admin/Resources/InventoryItems/Tables/InventoryItemsTable' => self::REGISTER,
        'Admin/Resources/LedgerAccounts/Tables/LedgerAccountsTable' => self::REGISTER,
        'Admin/Resources/PaymentMethods/Tables/PaymentMethodsTable' => self::REGISTER,
        'Admin/Resources/RecurringExpenses/Tables/RecurringExpensesTable' => self::REGISTER,
        'Admin/Resources/RentableItems/Tables/RentableItemsTable' => self::REGISTER,
        'Admin/Resources/RetailCategories/Tables/RetailCategoriesTable' => self::REGISTER,
        'Admin/Resources/Roles/Tables/RolesTable' => self::REGISTER,
        'Admin/Resources/SlaPolicies/Tables/SlaPoliciesTable' => self::REGISTER,
        'Admin/Resources/TaxCodes/Tables/TaxCodesTable' => self::REGISTER,
        'Admin/Resources/TenantRequestSubcategories/Tables/TenantRequestSubcategoriesTable' => self::REGISTER,
        'Admin/Resources/Tenants/Tables/TenantsTable' => self::REGISTER,
        'Admin/Resources/Trades/Tables/TradesTable' => self::REGISTER,
        'Admin/Resources/UnitOwnerships/Tables/UnitOwnershipsTable' => self::REGISTER,
        'Admin/Resources/Units/Tables/UnitsTable' => self::REGISTER,
        'Admin/Resources/Users/Tables/UsersTable' => self::REGISTER,
        'Admin/Resources/UtilityMeters/Tables/UtilityMetersTable' => self::REGISTER,
        'Admin/Resources/UtilityTariffs/Tables/UtilityTariffsTable' => self::REGISTER,
        'Admin/Resources/VendorDocumentTypes/Tables/VendorDocumentTypesTable' => self::REGISTER,
        'Admin/Resources/Vendors/Tables/VendorsTable' => self::REGISTER,
        'Admin/Resources/ViolationCategories/Tables/ViolationCategoriesTable' => self::REGISTER,
        'Admin/Resources/Warehouses/Tables/WarehousesTable' => self::REGISTER,

        // ── WORKLIST ──────────────────────────────────────────────────────────
        // Owed action by a deadline. SOONEST FIRST — the top row is the next thing to do.
        'Admin/RelationManagers/LeaseOptionsRelationManager' => self::WORKLIST,
        'Admin/RelationManagers/UnitEncumbrancesRelationManager' => self::WORKLIST,
        'Admin/Resources/Announcements/RelationManagers/RecipientsRelationManager' => self::WORKLIST,
        'Admin/Resources/PostDatedCheques/Tables/PostDatedChequesTable' => self::WORKLIST,
        'Admin/Resources/ServicePlans/Tables/ServicePlansTable' => self::WORKLIST,
        'Admin/Resources/Tenants/RelationManagers/DocumentsRelationManager' => self::WORKLIST,
        'Admin/Resources/Vendors/RelationManagers/DocumentsRelationManager' => self::WORKLIST,
        'Admin/Widgets/ExpiringLeases' => self::WORKLIST,
        'Admin/Widgets/OpenTenantRequests' => self::WORKLIST,
        'Portal/Widgets/OpenTenantRequests' => self::WORKLIST,
        'Vendor/Resources/WorkOrders/Pages/ListWorkOrders' => self::WORKLIST,

        // ── SEQUENCE ──────────────────────────────────────────────────────────
        // The order IS the content: a thread, a schedule read forward, a statement's own line order,
        // an append-only feed.
        'Admin/Pages/ActivityLog' => self::SEQUENCE,
        'Admin/RelationManagers/ActivitiesRelationManager' => self::SEQUENCE,
        'Admin/RelationManagers/ChargeScheduleRelationManager' => self::SEQUENCE,
        'Admin/RelationManagers/LeaseStraightLineRelationManager' => self::SEQUENCE,
        'Admin/RelationManagers/PercentageRentTiersRelationManager' => self::SEQUENCE,
        'Admin/RelationManagers/PurchaseRequestLinesRelationManager' => self::SEQUENCE,
        'Admin/RelationManagers/ServiceChecklistRelationManager' => self::SEQUENCE,
        'Admin/RelationManagers/ServicePlanStopsRelationManager' => self::SEQUENCE,
        'Admin/RelationManagers/TenantRequestCommentsRelationManager' => self::SEQUENCE,
        'Admin/RelationManagers/UnitOwnershipChargesRelationManager' => self::SEQUENCE,
        'Admin/RelationManagers/WorkOrderCommentsRelationManager' => self::SEQUENCE,
        'Admin/RelationManagers/WorkOrderPartsRelationManager' => self::SEQUENCE,
        'Admin/Resources/BankStatements/RelationManagers/LinesRelationManager' => self::SEQUENCE,
        'Portal/RelationManagers/PortalTenantRequestCommentsRelationManager' => self::SEQUENCE,

        // ── RANKED ────────────────────────────────────────────────────────────
        // Ordered by a magnitude or a flag that is itself the answer — biggest share first, primary
        // contact first.
        'Admin/RelationManagers/AssetOwnersRelationManager' => self::RANKED,
        'Admin/RelationManagers/CamAllocationsRelationManager' => self::RANKED,
        'Admin/RelationManagers/OwnerStatementsRelationManager' => self::RANKED,
        'Admin/RelationManagers/PortalUsersRelationManager' => self::RANKED,
        'Admin/Resources/Vendors/RelationManagers/ContactsRelationManager' => self::RANKED,
        'Admin/Widgets/TopTenants' => self::RANKED,

        // ── CUSTOM ────────────────────────────────────────────────────────────
        // A domain order expressed as a closure or a query scope, stated at the call site.
        'Admin/Resources/MarketingPosts/Tables/MarketingPostsTable' => self::CUSTOM,
        'Portal/Resources/Announcements/Tables/AnnouncementsTable' => self::CUSTOM,
        'Portal/Resources/MarketingPosts/Tables/MarketingPostsTable' => self::CUSTOM,
    ];

    /**
     * A LEDGER whose newest-first order is the PRIMARY KEY rather than a date column.
     *
     * Legitimate only where the model has no back-dateable document date, so insertion order IS
     * the chronology — `id desc` is then exact and cheaper than a date sort. It is NOT legitimate
     * where a document date exists and can differ from insertion order: a payroll run for March
     * can be generated in May, so a payslip history sorted by `id` claims a sequence the pay
     * periods do not have.
     *
     * @var array<string, string> path => why this model has no document date
     */
    public const LEDGER_BY_INSERTION = [
        'Admin/Resources/Disbursements/Tables/DisbursementsTable' => 'A disbursement has no date of its own — approved_at and paid_on come later in its life, '
            .'and it is never back-dated. Insertion order is its chronology.',
        'Admin/Resources/PurchaseRequests/Tables/PurchaseRequestsTable' => 'A purchase request carries no request date; decided_at/ordered_at/received_at are '
            .'stages, not the document date. It is raised when it is created and never back-dated.',
    ];

    /**
     * Files that own a row order and are deliberately unclassified.
     *
     * @var array<string, string> path => why
     */
    public const EXEMPT = [];

    /** The kind this file's order expresses, or null if it is not classified. */
    public static function kindOf(string $path): ?string
    {
        return self::TABLES[self::relative($path)] ?? null;
    }

    /** May this LEDGER sort on the primary key instead of a date? */
    public static function sortsByInsertion(string $path): bool
    {
        return array_key_exists(self::relative($path), self::LEDGER_BY_INSERTION);
    }

    /**
     * Does this file own a table's row order?
     *
     * Three things disqualify a file that configures a table, and each is derived rather than
     * listed: it DELEGATES to a shared table class (the delegate owns the order), or it is fed
     * from an ARRAY via `->records()` (a report page orders its own collection in PHP, and
     * re-ordering it in SQL is not possible). Everything else owns its order and must be
     * classified.
     */
    public static function owns(string $source): bool
    {
        if (! preg_match('/function\s+(configure|table)\s*\(\s*\??Table\s+\$table/', $source)) {
            return false;
        }

        if (preg_match('/return\s+[A-Z]\w*(Table|Tables\\\\\w+)::configure\s*\(/', $source)) {
            return false;
        }

        if (preg_match('/return\s+\$this->\w*[Tt]able\(/', $source) || preg_match('/return\s+self::\w+\(\s*\$table/', $source)) {
            return false;
        }

        return ! str_contains($source, '->records(');
    }

    /**
     * `app/Filament/Foo/Bar.php` (absolute or relative) => `Foo/Bar`.
     *
     * Idempotent: an already-relative key passes through unchanged. Without that, a second call
     * silently chops the first thirteen characters off the key and every lookup misses — which
     * looks exactly like an unclassified table rather than like a bug in the resolver.
     */
    public static function relative(string $path): string
    {
        $path = str_replace(['\\', '.php'], ['/', ''], $path);
        $marker = 'app/Filament/';
        $at = strpos($path, $marker);

        return $at === false ? $path : substr($path, $at + strlen($marker));
    }
}
