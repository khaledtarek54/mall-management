<?php

namespace App\Support;

use App\Filament\Admin\Pages\ActivityLog;
use App\Filament\Admin\Pages\ArAging;
use App\Filament\Admin\Pages\ArAgingByType;
use App\Filament\Admin\Pages\ArCollections;
use App\Filament\Admin\Pages\BalanceSheet;
use App\Filament\Admin\Pages\BillingRunPreview;
use App\Filament\Admin\Pages\Budget;
use App\Filament\Admin\Pages\CashFlow;
use App\Filament\Admin\Pages\ConfigurationHealth;
use App\Filament\Admin\Pages\Dashboard;
use App\Filament\Admin\Pages\ClauseRegister;
use App\Filament\Admin\Pages\ExpirationSchedule;
use App\Filament\Admin\Pages\GeneralLedger;
use App\Filament\Admin\Pages\Handbook;
use App\Filament\Admin\Pages\IncomeStatement;
use App\Filament\Admin\Pages\MonthEndClose;
use App\Filament\Admin\Pages\NotificationCenter;
use App\Filament\Admin\Pages\OccupancyCost;
use App\Filament\Admin\Pages\OccupancyMap;
use App\Filament\Admin\Pages\OpeningBalances;
use App\Filament\Admin\Pages\PropertyOverrides;
use App\Filament\Admin\Pages\RentableItemMap;
use App\Filament\Admin\Pages\RentRoll;
use App\Filament\Admin\Pages\ReportHub;
use App\Filament\Admin\Pages\Reports;
use App\Filament\Admin\Pages\RevenueForecast;
use App\Filament\Admin\Pages\SalesAnalytics;
use App\Filament\Admin\Pages\Settings;
use App\Filament\Admin\Pages\TaxDepreciation;
use App\Filament\Admin\Pages\TrialBalance;
use App\Filament\Admin\Pages\VatReturn;
use App\Filament\Admin\Pages\VendorScorecard;
use App\Filament\Admin\Pages\WeeklySpend;
use App\Filament\Admin\Pages\WithholdingTaxReturn;
use App\Filament\Admin\Pages\Workflows;
use App\Filament\Admin\Resources\AccountingPeriods\AccountingPeriodResource;
use App\Filament\Admin\Resources\AccountMappings\AccountMappingResource;
use App\Filament\Admin\Resources\Announcements\AnnouncementResource;
use App\Filament\Admin\Resources\ApprovalRules\ApprovalRuleResource;
use App\Filament\Admin\Resources\Areas\AreaResource;
use App\Filament\Admin\Resources\Assets\AssetResource;
use App\Filament\Admin\Resources\BankAccounts\BankAccountResource;
use App\Filament\Admin\Resources\BankStatements\BankStatementResource;
use App\Filament\Admin\Resources\CamExpensePools\CamExpensePoolResource;
use App\Filament\Admin\Resources\ChargeCodes\ChargeCodeResource;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\CreditNotes\CreditNoteResource;
use App\Filament\Admin\Resources\Custodies\CustodyResource;
use App\Filament\Admin\Resources\CustomFields\CustomFieldResource;
use App\Filament\Admin\Resources\Departments\DepartmentResource;
use App\Filament\Admin\Resources\DepositTransactions\DepositTransactionResource;
use App\Filament\Admin\Resources\Disbursements\DisbursementResource;
use App\Filament\Admin\Resources\DocumentTemplates\DocumentTemplateResource;
use App\Filament\Admin\Resources\Employees\EmployeeResource;
use App\Filament\Admin\Resources\Equipment\EquipmentResource;
use App\Filament\Admin\Resources\ExpenseCategories\ExpenseCategoryResource;
use App\Filament\Admin\Resources\Expenses\ExpenseResource;
use App\Filament\Admin\Resources\FacilityWorkOrders\FacilityWorkOrderResource;
use App\Filament\Admin\Resources\FailureCodes\FailureCodeResource;
use App\Filament\Admin\Resources\FixedAssets\FixedAssetResource;
use App\Filament\Admin\Resources\Holidays\HolidayResource;
use App\Filament\Admin\Resources\InventoryItems\InventoryItemResource;
use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Filament\Admin\Resources\JournalEntries\JournalEntryResource;
use App\Filament\Admin\Resources\Leases\LeaseResource;
use App\Filament\Admin\Resources\LedgerAccounts\LedgerAccountResource;
use App\Filament\Admin\Resources\MarketingBudgets\MarketingBudgetResource;
use App\Filament\Admin\Resources\MarketingPosts\MarketingPostResource;
use App\Filament\Admin\Resources\OwnerRequests\OwnerRequestResource;
use App\Filament\Admin\Resources\OwnerStatementRuns\OwnerStatementRunResource;
use App\Filament\Admin\Resources\PaymentMethods\PaymentMethodResource;
use App\Filament\Admin\Resources\Payments\PaymentResource;
use App\Filament\Admin\Resources\PayrollRates\PayrollRateResource;
use App\Filament\Admin\Resources\Payrolls\PayrollResource;
use App\Filament\Admin\Resources\PostDatedCheques\PostDatedChequeResource;
use App\Filament\Admin\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Filament\Admin\Resources\RecurringExpenses\RecurringExpenseResource;
use App\Filament\Admin\Resources\RentableItems\RentableItemResource;
use App\Filament\Admin\Resources\RentIndices\RentIndexResource;
use App\Filament\Admin\Resources\RetailCategories\RetailCategoryResource;
use App\Filament\Admin\Resources\Roles\RoleResource;
use App\Filament\Admin\Resources\ServicePlans\ServicePlanResource;
use App\Filament\Admin\Resources\SlaPolicies\SlaPolicyResource;
use App\Filament\Admin\Resources\StockMovements\StockMovementResource;
use App\Filament\Admin\Resources\TaxCodes\TaxCodeResource;
use App\Filament\Admin\Resources\TenantRequests\TenantRequestResource;
use App\Filament\Admin\Resources\TenantRequestSubcategories\TenantRequestSubcategoryResource;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Filament\Admin\Resources\TenantSalesDeclarations\TenantSalesDeclarationResource;
use App\Filament\Admin\Resources\Trades\TradeResource;
use App\Filament\Admin\Resources\UnitOwnerships\UnitOwnershipResource;
use App\Filament\Admin\Resources\Units\UnitResource;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Filament\Admin\Resources\UtilityMeters\UtilityMeterResource;
use App\Filament\Admin\Resources\UtilityTariffs\UtilityTariffResource;
use App\Filament\Admin\Resources\VendorBills\VendorBillResource;
use App\Filament\Admin\Resources\VendorDocumentTypes\VendorDocumentTypeResource;
use App\Filament\Admin\Resources\Vendors\VendorResource;
use App\Filament\Admin\Resources\ViolationCategories\ViolationCategoryResource;
use App\Filament\Admin\Resources\Violations\ViolationResource;
use App\Filament\Admin\Resources\Warehouses\WarehouseResource;
use App\Filament\Admin\Resources\WorkPermits\WorkPermitResource;
use App\Support\Filament\NavigationItemMemo;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Navigation\NavigationManager;
use Filament\Support\Icons\Heroicon;

/**
 * The sidebar. One ordered registry of every admin screen, and the builder that renders it.
 *
 * ## What this replaces
 *
 * Navigation was declared in ninety-nine separate classes — a `getNavigationGroup()` returning a
 * translated string and a `$navigationSort` integer each — with the panel declaring the group ORDER
 * separately. Three things went wrong with that, and all three are the same failure: nobody can see
 * the whole sidebar from any one file.
 *
 *  - **A group nobody declared.** Thirteen accounting and reporting pages returned
 *    `__('admin.groups.accounting')`, and `accounting` was not one of the ten groups the panel
 *    declared. Filament appends an unknown group after the declared ones in whatever order it
 *    happens to encounter it, so the entire financial-reporting section floated at the bottom of
 *    the sidebar in discovery order. It looked deliberate and was not.
 *  - **Screens in no group at all.** Budget, Tax depreciation and Opening balances returned null
 *    and rendered as three loose items above every group, next to Dashboard.
 *  - **Sort collisions.** Fifteen pairs shared a sort integer inside their group (four screens at
 *    Leasing 5 alone), so their order fell to class-discovery order — alphabetical by directory,
 *    which is not a decision anybody made. The integers themselves had drifted to 1, 2, 3, 4 …
 *    46, 60, 90, 92, 95, 96 with nothing to say what the scale meant.
 *
 * ## How it works now
 *
 * The panel calls {@see build()} through `Panel::navigation()`, which replaces Filament's
 * auto-assembly with this file. **Array order IS sidebar order** — there are no sort integers left
 * to collide, and moving a screen is moving one line.
 *
 * Each entry splices in the screen's own `getNavigationItems()`, so everything a resource or page
 * already decides for itself is preserved: its label, icon, badge, active-state and — the part that
 * matters — its visibility. A screen whose module is off or whose permission the operator lacks
 * returns no items and simply is not there, exactly as before. {@see NavigationBuilder} then drops
 * any group left with nothing visible in it, so a role sees only the groups it has screens in.
 *
 * ## The rule this file exists to enforce
 *
 * **A screen is in {@see GROUPS} or in {@see EXEMPT} with a reason, and nowhere else.** A registry
 * that replaces auto-discovery can hide a screen completely by omission, which is a worse failure
 * than the ones above — so `NavigationConformanceTest` discovers every resource and page ON DISK,
 * fails on one this file does not place, fails on a stale entry, and then RENDERS the sidebar as a
 * super_admin and asserts every non-exempt screen is actually in it. Reading the registry proves
 * only what it says; rendering it proves what an operator sees.
 */
final class Navigation
{
    /**
     * Screens that sit above every group, in this order.
     *
     * Filament prepends ungrouped items as an unlabelled group at the top of the sidebar. Only two
     * things belong there: where an operator starts, and what is waiting for them.
     *
     * @var array<int, class-string>
     */
    public const TOP_LEVEL = [
        Dashboard::class,
        NotificationCenter::class,
    ];

    /**
     * The sidebar, in order: group key => the screens under it, in order.
     *
     * The groups run in the order the work does — the tenancy that creates an obligation, then what
     * is owed to us, what we recover, what we owe, who we owe it on behalf of, and the ledger all of
     * that lands in — then the operational modules, and last the two groups an operator visits when
     * setting the system up rather than running it.
     *
     * Within a group, RECORDS come before the WORKLISTS and previews that read them. A screen that
     * only ever answers a question — a statement, an aging, a forecast — is in `reports` instead, so
     * the module groups stay short enough to scan; `ReportHub` at the top of that group is the
     * index of all of them, and knows nothing about this file.
     *
     * @var array<string, array<int, class-string>>
     */
    public const GROUPS = [
        // ── The tenancy ────────────────────────────────────────────────────────────────────────
        'leasing' => [
            AssetResource::class,
            UnitResource::class,
            TenantResource::class,
            LeaseResource::class,
            UnitOwnershipResource::class,
            RentableItemResource::class,
            // The three screens a leasing manager opens every morning. They are reports in
            // App\Support\ReportCatalogue — which is a statement about what can be exported and
            // delivered, not about where the work happens — so they stay beside the records.
            OccupancyMap::class,
            // The other floor plan: bays, storage, signage and kiosks. Beside the unit map because
            // it answers the same question about the rest of the lettable estate.
            RentableItemMap::class,
            RentRoll::class,
            ExpirationSchedule::class,
            ClauseRegister::class,
        ],

        // ── What tenants owe ───────────────────────────────────────────────────────────────────
        'receivables' => [
            InvoiceResource::class,
            PaymentResource::class,
            CreditNoteResource::class,
            PostDatedChequeResource::class,
            DepositTransactionResource::class,
            BillingRunPreview::class,
            ArCollections::class,
        ],

        // ── The variable charges ───────────────────────────────────────────────────────────────
        // CAM, utilities and turnover rent are one shape of work — measure a period, apportion it,
        // bill the difference — and they used to be spread through Receivables and Leasing, which
        // made Receivables ten items long and hid the fact that these three behave alike.
        'recoveries' => [
            CamExpensePoolResource::class,
            UtilityMeterResource::class,
            TenantSalesDeclarationResource::class,
            SalesAnalytics::class,
        ],

        // ── What we owe ────────────────────────────────────────────────────────────────────────
        'payables' => [
            VendorBillResource::class,
            ExpenseResource::class,
            RecurringExpenseResource::class,
            PurchaseRequestResource::class,
            VendorResource::class,
            // Not in `reports`: the scorecard is read when DECIDING who to dispatch, so it belongs
            // beside the vendor register it ranks.
            VendorScorecard::class,
        ],

        // ── The owner relationship ─────────────────────────────────────────────────────────────
        // Jawad's three screens. They were split across General Ledger, Payables and Operations,
        // which is three places to look for one counterparty.
        'owners' => [
            OwnerStatementRunResource::class,
            DisbursementResource::class,
            OwnerRequestResource::class,
        ],

        // ── The books ──────────────────────────────────────────────────────────────────────────
        'general_ledger' => [
            LedgerAccountResource::class,
            JournalEntryResource::class,
            AccountingPeriodResource::class,
            OpeningBalances::class,
            MonthEndClose::class,
            BankAccountResource::class,
            BankStatementResource::class,
        ],

        // ── Everything that only answers a question ────────────────────────────────────────────
        // Ordered as an accountant reads them: the statements, then the receivables analyses, then
        // the leasing ones, then the statutory filings. `ReportHub` is first because it is the
        // index — including of the two screens (Budget, Weekly spend) that are neither.
        'reports' => [
            ReportHub::class,
            IncomeStatement::class,
            BalanceSheet::class,
            CashFlow::class,
            TrialBalance::class,
            GeneralLedger::class,
            Reports::class,
            Budget::class,
            WeeklySpend::class,
            ArAging::class,
            ArAgingByType::class,
            OccupancyCost::class,
            RevenueForecast::class,
            VatReturn::class,
            WithholdingTaxReturn::class,
            TaxDepreciation::class,
        ],

        // ── Running the mall ───────────────────────────────────────────────────────────────────
        'operations' => [
            TenantRequestResource::class,
            ViolationResource::class,
            AreaResource::class,
            Workflows::class,
        ],

        'facility' => [
            FacilityWorkOrderResource::class,
            ServicePlanResource::class,
            EquipmentResource::class,
            WorkPermitResource::class,
        ],

        'inventory_assets' => [
            WarehouseResource::class,
            InventoryItemResource::class,
            StockMovementResource::class,
            FixedAssetResource::class,
        ],

        'hr_payroll' => [
            EmployeeResource::class,
            DepartmentResource::class,
            PayrollResource::class,
            CustodyResource::class,
        ],

        'marketing' => [
            MarketingBudgetResource::class,
            AnnouncementResource::class,
            MarketingPostResource::class,
        ],

        // ── Set up once, then leave alone ──────────────────────────────────────────────────────
        // Every code list an operator maintains. They were scattered through the module groups —
        // "Failure codes" under Facility next to Work Orders, "House rules" under Operations,
        // "Payment rails" under General Ledger — where a screen visited twice a year sat in the
        // way of one visited hourly. Collapsed by default (see COLLAPSED).
        'setup' => [
            ChargeCodeResource::class,
            TaxCodeResource::class,
            PaymentMethodResource::class,
            ExpenseCategoryResource::class,
            AccountMappingResource::class,
            RetailCategoryResource::class,
            RentIndexResource::class,
            UtilityTariffResource::class,
            TenantRequestSubcategoryResource::class,
            ViolationCategoryResource::class,
            TradeResource::class,
            FailureCodeResource::class,
            SlaPolicyResource::class,
            HolidayResource::class,
            VendorDocumentTypeResource::class,
            PayrollRateResource::class,
            ApprovalRuleResource::class,
            DocumentTemplateResource::class,
            CustomFieldResource::class,
        ],

        // ── The system itself ──────────────────────────────────────────────────────────────────
        'administration' => [
            Settings::class,
            PropertyOverrides::class,
            ConfigurationHealth::class,
            UserResource::class,
            RoleResource::class,
            ActivityLog::class,
            Handbook::class,
        ],
    ];

    /**
     * The icon each group carries, keyed the same as {@see GROUPS}.
     *
     * Not decoration: with `sidebarCollapsibleOnDesktop()` a collapsed sidebar renders the GROUP
     * icon and nothing else, so a group without one is an unlabelled blank the operator has to
     * expand to identify.
     *
     * @var array<string, Heroicon>
     */
    public const ICONS = [
        'leasing' => Heroicon::OutlinedBuildingOffice2,
        'receivables' => Heroicon::OutlinedBanknotes,
        'recoveries' => Heroicon::OutlinedArrowsRightLeft,
        'payables' => Heroicon::OutlinedInboxArrowDown,
        'owners' => Heroicon::OutlinedUserGroup,
        'general_ledger' => Heroicon::OutlinedBookOpen,
        'reports' => Heroicon::OutlinedChartBar,
        'operations' => Heroicon::OutlinedClipboardDocumentList,
        'facility' => Heroicon::OutlinedWrenchScrewdriver,
        'inventory_assets' => Heroicon::OutlinedCube,
        'hr_payroll' => Heroicon::OutlinedUsers,
        'marketing' => Heroicon::OutlinedMegaphone,
        'setup' => Heroicon::OutlinedSquares2x2,
        'administration' => Heroicon::OutlinedShieldCheck,
    ];

    /**
     * Groups that open collapsed.
     *
     * The two an operator configures rather than works in. Everything else is expanded, because a
     * collapsed group hides the only thing that tells someone the screen they want exists.
     *
     * @var array<int, string>
     */
    public const COLLAPSED = ['setup', 'administration'];

    /**
     * Screens deliberately absent from the sidebar, and why.
     *
     * The reason is required and the conformance gate rejects a stale entry, because "it is not in
     * the sidebar" and "somebody forgot it" look identical from outside this file.
     *
     * @var array<class-string, string>
     */
    public const EXEMPT = [];

    /** Every screen this registry places, flat. @return array<int, class-string> */
    public static function placed(): array
    {
        return array_merge(self::TOP_LEVEL, ...array_values(self::GROUPS));
    }

    /** The group key a screen sits in, or null for a top-level or unplaced one. */
    public static function groupOf(string $screen): ?string
    {
        foreach (self::GROUPS as $key => $screens) {
            if (in_array($screen, $screens, true)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Render the whole sidebar.
     *
     * Every item comes from the screen's own `getNavigationItems()` rather than being rebuilt here.
     * That is what keeps this file a registry of ORDER and nothing else: the label, icon, badge,
     * active-state and — the load-bearing part — the visibility rules stay where the screen
     * declares them, so a module toggle or a missing permission still removes the item, and a group
     * left empty is dropped by {@see NavigationBuilder::getNavigation()}.
     */
    public static function build(NavigationBuilder $builder): NavigationBuilder
    {
        $builder->items(self::itemsFor(self::TOP_LEVEL));

        foreach (self::GROUPS as $key => $screens) {
            $builder->group(
                NavigationGroup::make()
                    ->label(fn (): string => __("admin.groups.{$key}"))
                    ->icon(self::ICONS[$key] ?? null)
                    ->collapsed(in_array($key, self::COLLAPSED, true))
                    ->items(self::itemsFor($screens)),
            );
        }

        return $builder;
    }

    /**
     * The navigation items for a list of screens, in the order given — visibility applied HERE.
     *
     * **This is the load-bearing method and the one thing a custom builder must not get wrong.**
     * `getNavigationItems()` builds an item unconditionally; the gate that decides whether an
     * operator may SEE it lives in `registerNavigationItems()`, which only Filament's own
     * auto-assembly calls — and a `Panel::navigation()` builder skips that path entirely
     * ({@see NavigationManager::get()} returns `buildNavigation()` before it
     * ever mounts anything). Splicing `getNavigationItems()` straight in therefore renders every
     * screen to everybody: a viewer would read Settings, Users and Roles in their sidebar, and a
     * module switched off would still list its resources. Each would 403 or refuse on click, so
     * nothing would leak DATA — but a sidebar that offers what it will then deny is a worse lie
     * than a missing feature, and a module toggle that visibly changes nothing reads as broken.
     *
     * So the two refusals Filament applies are applied here, in the same order and with the same
     * meaning:
     *
     *  - `shouldRegisterNavigation()` — the module flag (every resource answers it through
     *    {@see RoleGatedActions}) and any screen that
     *    is deliberately reachable only from somewhere else.
     *  - `canAccess()` — the permission.
     *
     * `getCluster()` is checked for fidelity with upstream even though this panel uses no clusters;
     * a clustered screen registers under its cluster, not here. `NavigationConformanceTest` proves
     * all of it by rendering the sidebar as a restricted role, not by reading this comment.
     *
     * @param  array<int, class-string>  $screens
     * @return array<int, NavigationItem>
     */
    private static function itemsFor(array $screens): array
    {
        $items = [];
        $memo = app(NavigationItemMemo::class);

        foreach ($screens as $screen) {
            // Memoised alongside the item: this pair of permission checks ran for all 103
            // screens on every getNavigation() call, which is at least twice a page.
            if (! $memo->visible($screen, static fn (): bool => self::isVisibleTo($screen))) {
                continue;
            }

            // Memoised for the request. `filament()->getNavigation()` is called by both the
            // sidebar and the topbar blades and resolves a fresh NavigationManager each time, so
            // this method runs FIVE times per page render — and a resource's badge is computed
            // EAGERLY inside `getNavigationItems()`, which made fifty redundant COUNT queries the
            // most expensive thing in the panel chrome. See NavigationItemMemo for the measurement.
            foreach ($memo->for($screen, static fn (): array => $screen::getNavigationItems()) as $item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * May the current operator see this screen in the sidebar at all?
     *
     * Mirrors the refusals in `Page::registerNavigationItems()` and
     * `Resource\Concerns\HasNavigation::registerNavigationItems()` — see {@see itemsFor()} for
     * why they have to be restated rather than inherited.
     *
     * @param  class-string  $screen
     */
    public static function isVisibleTo(string $screen): bool
    {
        if (filled($screen::getCluster())) {
            return false;
        }

        if (! $screen::shouldRegisterNavigation()) {
            return false;
        }

        if (method_exists($screen, 'getParentResourceRegistration') && $screen::getParentResourceRegistration()) {
            return false;
        }

        return $screen::canAccess();
    }
}
