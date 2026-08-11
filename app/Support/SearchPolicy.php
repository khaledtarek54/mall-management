<?php

namespace App\Support;

use App\Filament\Admin\Resources\AccountingPeriods\AccountingPeriodResource;
use App\Filament\Admin\Resources\AccountMappings\AccountMappingResource;
use App\Filament\Admin\Resources\ChargeCodes\ChargeCodeResource;
use App\Filament\Admin\Resources\Announcements\AnnouncementResource;
use App\Filament\Admin\Resources\Areas\AreaResource;
use App\Filament\Admin\Resources\Assets\AssetResource;
use App\Filament\Admin\Resources\CamExpensePools\CamExpensePoolResource;
use App\Filament\Admin\Resources\CreditNotes\CreditNoteResource;
use App\Filament\Admin\Resources\Custodies\CustodyResource;
use App\Filament\Admin\Resources\Departments\DepartmentResource;
use App\Filament\Admin\Resources\DepositTransactions\DepositTransactionResource;
use App\Filament\Admin\Resources\Disbursements\DisbursementResource;
use App\Filament\Admin\Resources\Employees\EmployeeResource;
use App\Filament\Admin\Resources\Equipment\EquipmentResource;
use App\Filament\Admin\Resources\Expenses\ExpenseResource;
use App\Filament\Admin\Resources\FixedAssets\FixedAssetResource;
use App\Filament\Admin\Resources\InventoryItems\InventoryItemResource;
use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Filament\Admin\Resources\JournalEntries\JournalEntryResource;
use App\Filament\Admin\Resources\Leases\LeaseResource;
use App\Filament\Admin\Resources\LedgerAccounts\LedgerAccountResource;
use App\Filament\Admin\Resources\MaintenancePlans\MaintenancePlanResource;
use App\Filament\Admin\Resources\MaintenanceWorkOrders\MaintenanceWorkOrderResource;
use App\Filament\Admin\Resources\MarketingBudgets\MarketingBudgetResource;
use App\Filament\Admin\Resources\OwnerRequests\OwnerRequestResource;
use App\Filament\Admin\Resources\OwnerStatementRuns\OwnerStatementRunResource;
use App\Filament\Admin\Resources\Payments\PaymentResource;
use App\Filament\Admin\Resources\Payrolls\PayrollResource;
use App\Filament\Admin\Resources\PostDatedCheques\PostDatedChequeResource;
use App\Filament\Admin\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Filament\Admin\Resources\Roles\RoleResource;
use App\Filament\Admin\Resources\SlaPolicies\SlaPolicyResource;
use App\Filament\Admin\Resources\StockMovements\StockMovementResource;
use App\Filament\Admin\Resources\TenantRequests\TenantRequestResource;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Filament\Admin\Resources\TenantSalesDeclarations\TenantSalesDeclarationResource;
use App\Filament\Admin\Resources\Units\UnitResource;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Filament\Admin\Resources\UtilityMeters\UtilityMeterResource;
use App\Filament\Admin\Resources\VendorBills\VendorBillResource;
use App\Filament\Admin\Resources\Vendors\VendorResource;
use App\Filament\Admin\Resources\Violations\ViolationResource;
use App\Filament\Admin\Resources\Warehouses\WarehouseResource;
use App\Filament\Portal\Resources\CamAllocations\CamAllocationResource as PortalCamAllocationResource;
use App\Filament\Portal\Resources\MarketingPosts\MarketingPostResource as PortalMarketingPostResource;
use App\Filament\Portal\Resources\TenantSalesDeclarations\TenantSalesDeclarationResource as PortalTenantSalesDeclarationResource;
use App\Models;

/**
 * The authoritative register of what is searchable, how, and — where something
 * is NOT searchable — why that is a decision rather than an oversight.
 *
 * `SearchPolicyConformanceTest` reflects over this registry, so a resource that
 * ships with no way to find its records, or with a search key that cannot work,
 * FAILS the build instead of shipping a search box that quietly returns nothing.
 *
 * ## What this replaces
 *
 * Search was 47 resources each left on whatever Filament's defaults happened to
 * give them. The result was not "basic search" — it was a lottery:
 *   - 7 resources had NO globally searchable attribute at all, so their records
 *     could not be found from the search bar under any spelling. `UtilityMeter`
 *     has a unique `meter_number` and was one of them.
 *   - 3 searched an integer or an enum. `AccountingPeriod` searched `period_no`,
 *     so typing "1" returned periods 1, 10, 11 and 12; `SlaPolicy` searched a
 *     priority enum, so searching "high" returned SLA policies.
 *   - `ViolationResource` pointed `$recordTitleAttribute` at `reference`, which
 *     is a PHP accessor and not a column. It survived only because an override
 *     shadowed it — delete the override and global search on violations becomes
 *     a SQL error.
 *   - 5 tables rendered no search box whatsoever.
 * Each of those is a silent failure. An empty result set is indistinguishable
 * from "no such record", which is exactly why none of them were ever reported.
 *
 * ## The three rules the gate enforces
 *
 * 1. Every resource is classified: globally searchable, or listed in
 *    `GLOBAL_SEARCH_EXEMPT` with a stated reason. No third state.
 * 2. A globally searchable resource searches its model's `search_text` blob
 *    (see `App\Models\Concerns\HasSearchText`), never a raw column. That is what
 *    makes «شركة» find «شركه» and `INV2026` find `INV-2026`. Relation paths point
 *    at the RELATED model's `search_text` for the same reason.
 * 3. Every table — resource tables AND relation managers — must be able to
 *    ANSWER its search box. `TableDefaults` gives every table the blob search,
 *    which is what renders the box, so a table whose model has no blob and no
 *    searchable column must call `->searchable(false)` explicitly. There is
 *    deliberately no registry for this: all three states are readable from the
 *    code itself (does the model use the trait · does a column say
 *    `searchable()` · does the table say `searchable(false)`), so a
 *    hand-maintained list would be a fourth thing to keep in step with three
 *    that cannot drift. The reason a given table opts out is stated at its
 *    `->searchable(false)` call site, where someone changing that table reads it.
 *
 * A search box that always returns nothing is the specific failure rule 3 exists
 * to prevent: it is indistinguishable from "no such record", so it never gets
 * reported as a bug.
 */
class SearchPolicy
{
    /**
     * Below this, global search does not run at all.
     *
     * Global search fans out one query per resource across ~35 resources. A
     * single stray character therefore costs 35 full table scans and up to
     * `RESULTS_PER_RESOURCE` × 35 model hydrations — for a result set nobody
     * wants, since one letter matches most of the database. Two characters is
     * where a query starts to mean something.
     */
    public const MIN_QUERY_LENGTH = 2;

    /**
     * Rows returned per resource per query.
     *
     * Filament's default is 50 PER RESOURCE, which nobody had overridden: one
     * keystroke could hydrate ~1,750 models across 35 categories, most of them
     * never rendered. Five is what fits on screen under a category heading
     * before the operator gives up and opens the full list instead.
     */
    public const RESULTS_PER_RESOURCE = 5;

    /**
     * Models carrying a denormalized, fold-normalized `search_text` blob.
     *
     * Each uses `HasSearchText` and declares `searchTextSources()`. The gate
     * asserts both, asserts the column exists, and asserts the blob actually
     * rebuilds — a model added here without the trait fails the build.
     *
     * Note the column is deliberately NOT indexed. Both Filament search paths
     * build `LIKE '%term%'`, and a leading wildcard cannot use a B-tree index
     * under any circumstance, so an index would be pure write cost for zero read
     * benefit. What the blob buys is correctness (Arabic folding, accessor
     * values, punctuation-insensitivity) and one column scan in place of five
     * ORed ones — not index usage. Index usage comes from the identifier
     * fast-path in `AtriomGlobalSearchProvider`, which hits the real unique
     * indexes with an anchored `LIKE 'term%'`.
     *
     * @var array<int, class-string>
     */
    public const INDEXED = [
        \App\Models\BankAccount::class,
        // ---- Leasing & tenancy ----
        Models\Tenant::class,
        Models\Unit::class,
        Models\Lease::class,
        Models\Asset::class,
        Models\Area::class,

        // ---- Receivables ----
        Models\Invoice::class,
        Models\Payment::class,
        Models\CreditNote::class,
        Models\DepositTransaction::class,
        Models\PostDatedCheque::class,

        // ---- Payables ----
        Models\Vendor::class,
        Models\VendorBill::class,
        Models\Expense::class,
        Models\PurchaseRequest::class,

        // ---- General ledger ----
        Models\JournalEntry::class,
        Models\LedgerAccount::class,
        Models\Disbursement::class,
        Models\OwnerStatementRun::class,

        // ---- Operations ----
        Models\TenantRequest::class,
        Models\Announcement::class,
        Models\MarketingPost::class,
        Models\Violation::class,
        Models\OwnerRequest::class,

        // ---- Facility ----
        Models\MaintenanceWorkOrder::class,
        Models\MaintenancePlan::class,
        Models\Equipment::class,
        Models\UtilityMeter::class,

        // ---- Inventory & assets ----
        Models\InventoryItem::class,
        Models\Warehouse::class,
        Models\StockMovement::class,
        Models\FixedAsset::class,
        Models\Custody::class,

        // ---- People ----
        Models\Employee::class,
        Models\Payroll::class,
        Models\User::class,
        Models\Department::class,
    ];

    /**
     * Resources deliberately absent from global search, and why.
     *
     * The bar: could an operator type something that identifies ONE of these
     * records? If a record is only ever reached by drilling from its parent, or
     * is configuration chosen from a settings list, it does not belong in a
     * search bar — putting it there returns noise that buries the invoice the
     * operator was actually looking for.
     *
     * @var array<class-string, string>
     */
    public const GLOBAL_SEARCH_EXEMPT = [
        CamExpensePoolResource::class => 'A pool is identified by property + year, not by anything typed — it has no reference and no name. Reached from the property CAM page.',
        TenantSalesDeclarationResource::class => 'Identified by lease + period, both of which are filters. There is no identifier an operator would type to find one declaration.',
        AccountingPeriodResource::class => 'Configuration, chosen from the period picker. Its only key is `period_no`, an integer — searching "1" would return periods 1, 10, 11 and 12.',
        MarketingBudgetResource::class => 'Identified by property + year. Its only key is `period_year`, an integer, so every budget for 2026 is one indistinguishable match.',
        SlaPolicyResource::class => 'Operator configuration reached from Settings. Its only key is a priority enum, so searching "high" would return SLA policies alongside real records.',
        RoleResource::class => 'RBAC configuration reached from Settings. Role names are typed by administrators into a permission matrix, never searched for as records.',
        \App\Filament\Admin\Resources\BankStatements\BankStatementResource::class => 'A statement is found by its account and period, both of which are the whole table — nobody types a statement\'s name because it does not have one. Reached from the account, or from the reconciliation work itself.',
        \App\Filament\Admin\Resources\ApprovalRules\ApprovalRuleResource::class => 'The approval ladder — a dozen rows of amount bands under Settings, maintained by scrolling rather than searching. Nothing on it has a name or a number anyone would type: the identity of a band IS its module and its range, both of which are on screen.',
        \App\Filament\Admin\Resources\TaxCodes\TaxCodeResource::class => 'The tax catalogue, reached from the General Ledger group. Rows an accountant maintains — found by scrolling, not by searching. Nobody types "VAT_14" to find a record; they go to the screen to change a rate.',
        ChargeCodeResource::class => 'The billing vocabulary, reached from the General Ledger group. A dozen rows an accountant maintains — found by scrolling, not by searching, and its codes are already searchable where they are billed.',
        AccountMappingResource::class => 'The posting map, reached from the General Ledger group. A row has no identifier of its own — it is a role picked from a fixed list against an account, and both are already searchable where they live.',

        // ---- Portal ----
        PortalCamAllocationResource::class => 'A tenant reaches their CAM allocation from the lease it belongs to. It carries no reference of its own — only a pool and a share.',
        PortalTenantSalesDeclarationResource::class => 'A tenant has a handful of declarations, listed by period. Nothing on one is typed to find it.',
        PortalMarketingPostResource::class => 'A retailer has a handful of their own offers, listed on one screen with a status filter. Global search would be a longer route to a shorter list. (The OPERATOR\'s MarketingPostResource IS searchable — they hold every mall\'s.)',
    ];

    /**
     * Category order in the global search dropdown.
     *
     * Filament sorts categories by `$globalSearchSort`, which means a magic
     * integer scattered across 35 resource classes that nobody can order
     * relative to each other without opening all 35. One ordered list is the
     * whole ordering, readable top to bottom.
     *
     * Ordered by what an operator is most likely to be hunting: who they are
     * dealing with, then where, then the money. Anything unlisted sorts after,
     * which is a safe default — a new resource is findable, just not promoted.
     *
     * @var array<int, class-string>
     */
    public const PRIORITY = [
        TenantResource::class,
        UnitResource::class,
        LeaseResource::class,
        InvoiceResource::class,
        PaymentResource::class,
        TenantRequestResource::class,
        MaintenanceWorkOrderResource::class,
        CreditNoteResource::class,
        VendorResource::class,
        VendorBillResource::class,
        AssetResource::class,
        EmployeeResource::class,
        PostDatedChequeResource::class,
        ExpenseResource::class,
        JournalEntryResource::class,
        UtilityMeterResource::class,
        EquipmentResource::class,
        ViolationResource::class,
        AnnouncementResource::class,
        PurchaseRequestResource::class,
        InventoryItemResource::class,
        FixedAssetResource::class,
        OwnerRequestResource::class,
        AreaResource::class,
        WarehouseResource::class,
        StockMovementResource::class,
        CustodyResource::class,
        PayrollResource::class,
        DepositTransactionResource::class,
        DisbursementResource::class,
        OwnerStatementRunResource::class,
        LedgerAccountResource::class,
        MaintenancePlanResource::class,
        DepartmentResource::class,
        UserResource::class,
    ];

    /**
     * Sort weight for a resource's category. Unlisted resources sort last.
     */
    public static function rank(string $resource): int
    {
        $index = array_search($resource, self::PRIORITY, strict: true);

        return $index === false ? PHP_INT_MAX : $index;
    }

    public static function isGlobalSearchExempt(string $resource): bool
    {
        return array_key_exists($resource, self::GLOBAL_SEARCH_EXEMPT);
    }

    public static function isIndexed(string $model): bool
    {
        return in_array($model, self::INDEXED, strict: true);
    }
}
