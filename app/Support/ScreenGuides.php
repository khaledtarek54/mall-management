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
use App\Filament\Admin\Resources\CreditNotes\CreditNoteResource;
use App\Filament\Admin\Resources\Custodies\CustodyResource;
use App\Filament\Admin\Resources\Departments\DepartmentResource;
use App\Filament\Admin\Resources\DepositTransactions\DepositTransactionResource;
use App\Filament\Admin\Resources\Disbursements\DisbursementResource;
use App\Filament\Admin\Resources\Employees\EmployeeResource;
use App\Filament\Admin\Resources\Equipment\EquipmentResource;
use App\Filament\Admin\Resources\Expenses\ExpenseResource;
use App\Filament\Admin\Resources\FacilityWorkOrders\FacilityWorkOrderResource;
use App\Filament\Admin\Resources\FixedAssets\FixedAssetResource;
use App\Filament\Admin\Resources\InventoryItems\InventoryItemResource;
use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Filament\Admin\Resources\JournalEntries\JournalEntryResource;
use App\Filament\Admin\Resources\Leases\LeaseResource;
use App\Filament\Admin\Resources\LedgerAccounts\LedgerAccountResource;
use App\Filament\Admin\Resources\MarketingBudgets\MarketingBudgetResource;
use App\Filament\Admin\Resources\MarketingPosts\MarketingPostResource;
use App\Filament\Admin\Resources\OwnerRequests\OwnerRequestResource;
use App\Filament\Admin\Resources\OwnerStatementRuns\OwnerStatementRunResource;
use App\Filament\Admin\Resources\Payments\PaymentResource;
use App\Filament\Admin\Resources\Payrolls\PayrollResource;
use App\Filament\Admin\Resources\PostDatedCheques\PostDatedChequeResource;
use App\Filament\Admin\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Filament\Admin\Resources\RentableItems\RentableItemResource;
use App\Filament\Admin\Resources\RentIndices\RentIndexResource;
use App\Filament\Admin\Resources\Roles\RoleResource;
use App\Filament\Admin\Resources\ServicePlans\ServicePlanResource;
use App\Filament\Admin\Resources\SlaPolicies\SlaPolicyResource;
use App\Filament\Admin\Resources\StockMovements\StockMovementResource;
use App\Filament\Admin\Resources\TaxCodes\TaxCodeResource;
use App\Filament\Admin\Resources\TenantRequests\TenantRequestResource;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Filament\Admin\Resources\TenantSalesDeclarations\TenantSalesDeclarationResource;
use App\Filament\Admin\Resources\Trades\TradeResource;
use App\Filament\Admin\Resources\UnitOwnerships\UnitOwnershipResource;
use App\Filament\Admin\Resources\Units\UnitResource;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Filament\Admin\Resources\UtilityMeters\UtilityMeterResource;
use App\Filament\Admin\Resources\UtilityTariffs\UtilityTariffResource;
use App\Filament\Admin\Resources\VendorBills\VendorBillResource;
use App\Filament\Admin\Resources\Vendors\VendorResource;
use App\Filament\Admin\Resources\Violations\ViolationResource;
use App\Filament\Admin\Resources\Warehouses\WarehouseResource;
use App\Filament\Admin\Resources\WorkPermits\WorkPermitResource;
use App\Filament\Portal\Pages\CompanyProfile;
use App\Filament\Portal\Resources\CamAllocations\CamAllocationResource;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use ReflectionClass;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

/**
 * In-app operator guidance: what a screen is for, and what it changes elsewhere.
 *
 * **This replaced a markdown dump, and the reasons are worth keeping.** The first version rendered
 * `docs/business-model/NN-*.md` into a modal. It avoided duplicating content, and it was wrong on
 * three counts: the docs are ENGLISH ONLY, so an Arabic operator got English help in an RTL panel;
 * it styled itself with `prose` classes that do not exist in this build (no typography plugin), so
 * it rendered as unspaced raw text; and a whole reference document dumped into a dialogue is not
 * guidance — someone who opens help is stuck on one thing, not looking for a chapter.
 *
 * So the in-app guide is SHORT, STRUCTURED and TRANSLATED, and the module doc stays the deep
 * reference behind it. That is a deliberate second surface rather than a second source of truth: the
 * doc explains the module with worked numbers, this answers "what am I looking at and what happens
 * if I touch it".
 *
 * Four fields per screen, because they are the four questions actually asked:
 *   purpose — what this screen is, in one sentence
 *   steps   — how the everyday task is done
 *   affects — **what changes elsewhere in the system**, which is the one nothing else tells you
 *   rules   — the constraints that will otherwise surprise someone
 *
 * Content lives in `lang/{en,ar}/guides.php`, so it is translated like everything else and the
 * translation gate covers it.
 *
 * **This class was `ResourceGuides` and covered 13 admin resources.** It now covers every SCREEN in
 * both panels — 49 admin resources, 25 admin pages and 7 portal resources — because the pages are
 * where the money questions actually get asked (a trial balance, a month-end close, a billing run
 * preview) and they had no help at all. `GuideAction::for()` never touched resource-specific API, so
 * a page works through the same path a resource does.
 *
 * **The registry is now exhaustive, and that is a reversal.** The old conformance test said coverage
 * "is still deliberately not asserted — a registry padded with exemption reasons would be noise".
 * That held while 13 screens had guides and 68 did not. With content written for all of them, an
 * unregistered screen is an omission rather than a gap, so `ScreenGuideConformanceTest` discovers
 * every screen class under `app/Filament` and fails on one that is neither registered nor EXEMPT —
 * the same shape as `DeletionPolicy` and `PropertyIsolation`.
 */
class ScreenGuides
{
    /**
     * Screen class => guide key in `guides.php`.
     *
     * Admin resources, admin pages and portal resources in one map, because a guide answers the same
     * four questions whatever kind of class renders the screen.
     *
     * @var array<class-string, string>
     */
    public const SCREENS = [
        // ── Property & leasing ────────────────────────────────────────────────────────────────
        AssetResource::class => 'properties',
        UnitResource::class => 'units',
        AreaResource::class => 'areas',
        RentableItemResource::class => 'rentable_items',
        TenantResource::class => 'tenants',
        LeaseResource::class => 'leases',
        UnitOwnershipResource::class => 'unit_ownerships',

        // ── Billing & accounts receivable ─────────────────────────────────────────────────────
        InvoiceResource::class => 'invoices',
        PaymentResource::class => 'payments',
        CreditNoteResource::class => 'credit_notes',
        DepositTransactionResource::class => 'deposits',
        PostDatedChequeResource::class => 'post_dated_cheques',

        // ── Recoveries & tenant sales ─────────────────────────────────────────────────────────
        CamExpensePoolResource::class => 'cam',
        TenantSalesDeclarationResource::class => 'sales_declarations',
        UtilityMeterResource::class => 'utility_meters',

        // ── Operations ────────────────────────────────────────────────────────────────────────
        TenantRequestResource::class => 'tenant_requests',
        FacilityWorkOrderResource::class => 'work_orders',
        ServicePlanResource::class => 'service_plans',
        EquipmentResource::class => 'equipment',
        SlaPolicyResource::class => 'sla_policies',
        ViolationResource::class => 'violations',

        // ── Vendors, procurement & stock ──────────────────────────────────────────────────────
        VendorResource::class => 'vendors',
        VendorBillResource::class => 'vendor_bills',
        PurchaseRequestResource::class => 'purchase_requests',
        InventoryItemResource::class => 'inventory_items',
        StockMovementResource::class => 'stock_movements',
        WarehouseResource::class => 'warehouses',

        // ── People & cash-out ─────────────────────────────────────────────────────────────────
        EmployeeResource::class => 'employees',
        PayrollResource::class => 'payrolls',
        CustodyResource::class => 'custodies',
        ExpenseResource::class => 'expenses',
        DepartmentResource::class => 'departments',

        // ── Owners ────────────────────────────────────────────────────────────────────────────
        OwnerRequestResource::class => 'owner_requests',
        OwnerStatementRunResource::class => 'owner_statements',
        DisbursementResource::class => 'disbursements',

        // ── Marketing ─────────────────────────────────────────────────────────────────────────
        MarketingBudgetResource::class => 'marketing_budgets',
        MarketingPostResource::class => 'marketing_posts',
        AnnouncementResource::class => 'announcements',

        // ── Accounting core ───────────────────────────────────────────────────────────────────
        JournalEntryResource::class => 'journal_entries',
        LedgerAccountResource::class => 'ledger_accounts',
        AccountingPeriodResource::class => 'accounting_periods',
        AccountMappingResource::class => 'posting_map',
        FixedAssetResource::class => 'fixed_assets',
        BankAccountResource::class => 'bank_accounts',
        BankStatementResource::class => 'bank_statements',

        // ── Configuration & access ────────────────────────────────────────────────────────────
        ChargeCodeResource::class => 'charge_codes',
        RentIndexResource::class => 'rent_indices',
        WorkPermitResource::class => 'work_permits',
        TradeResource::class => 'trades',
        TaxCodeResource::class => 'tax_codes',
        UtilityTariffResource::class => 'utility_tariffs',
        ApprovalRuleResource::class => 'approval_rules',
        UserResource::class => 'users',
        RoleResource::class => 'roles',

        // ── Admin pages ───────────────────────────────────────────────────────────────────────
        Dashboard::class => 'dashboard',
        BillingRunPreview::class => 'billing_run',
        RentRoll::class => 'rent_roll',
        ExpirationSchedule::class => 'expiration_schedule',
        RevenueForecast::class => 'revenue_forecast',
        OccupancyMap::class => 'occupancy_map',
        OccupancyCost::class => 'occupancy_cost',
        SalesAnalytics::class => 'sales_analytics',
        ArAging::class => 'ar_aging',
        ArAgingByType::class => 'ar_aging_by_type',
        ArCollections::class => 'ar_collections',
        TrialBalance::class => 'trial_balance',
        GeneralLedger::class => 'general_ledger',
        Budget::class => 'budget',
        OpeningBalances::class => 'opening_balances',
        TaxDepreciation::class => 'tax_depreciation',
        IncomeStatement::class => 'income_statement',
        BalanceSheet::class => 'balance_sheet',
        CashFlow::class => 'cash_flow',
        VatReturn::class => 'vat_return',
        MonthEndClose::class => 'month_end_close',
        WeeklySpend::class => 'weekly_spend',
        VendorScorecard::class => 'vendor_scorecard',
        Reports::class => 'reports',
        ReportHub::class => 'report_hub',
        Workflows::class => 'workflows',
        ActivityLog::class => 'activity_log',
        Settings::class => 'settings',
        PropertyOverrides::class => 'property_overrides',
        ConfigurationHealth::class => 'configuration_health',
        NotificationCenter::class => 'notification_center',
        Handbook::class => 'handbook',

        // ── Tenant portal ─────────────────────────────────────────────────────────────────────
        // A separate namespace on purpose: the reader here is the retailer, not the operator, so the
        // same module needs different words. A guide telling a tenant what the billing run does
        // would be answering a question they cannot act on.
        \App\Filament\Portal\Resources\Invoices\InvoiceResource::class => 'portal_invoices',
        \App\Filament\Portal\Resources\CreditNotes\CreditNoteResource::class => 'portal_credit_notes',
        \App\Filament\Portal\Resources\Payments\PaymentResource::class => 'portal_payments',
        \App\Filament\Portal\Resources\Leases\LeaseResource::class => 'portal_leases',
        \App\Filament\Portal\Resources\TenantRequests\TenantRequestResource::class => 'portal_requests',
        \App\Filament\Portal\Resources\TenantSalesDeclarations\TenantSalesDeclarationResource::class => 'portal_sales',
        CamAllocationResource::class => 'portal_cam',
        \App\Filament\Portal\Resources\MarketingPosts\MarketingPostResource::class => 'portal_posts',
        \App\Filament\Portal\Resources\Announcements\AnnouncementResource::class => 'portal_announcements',
        \App\Filament\Portal\Pages\NotificationCenter::class => 'portal_notifications',
        CompanyProfile::class => 'portal_company_profile',
    ];

    /**
     * Screens that deliberately carry no guide, and why.
     *
     * An empty help panel is worse than no help button: it teaches the operator that help is not
     * worth clicking — so this is the escape hatch for a screen where guidance would be noise.
     *
     * **It is empty, and that is the honest state.** Every one of the 81 screens the panels render
     * has a guide. The two obvious candidates — the login form and Filament's tenancy registration
     * screen — are not in here because they are not screens by construction: both extend
     * `Filament\Pages\SimplePage`, the full-page auth shell, rather than `Filament\Pages\Page`, so
     * `discoverScreens()` never offers them for classification. Listing them would have been a
     * registry entry that classifies nothing, which is the failure mode these registries exist to
     * prevent. The gate rejects an EXEMPT entry that is not a discovered screen, so this cannot
     * quietly fill up with the same mistake.
     *
     * @var array<class-string, string>
     */
    public const EXEMPT = [];

    public static function keyFor(string $screen): ?string
    {
        return self::SCREENS[$screen] ?? null;
    }

    public static function has(string $screen): bool
    {
        return isset(self::SCREENS[$screen]);
    }

    public static function isExempt(string $screen): bool
    {
        return isset(self::EXEMPT[$screen]);
    }

    /** One sentence: what this screen is. */
    public static function purpose(string $key): string
    {
        return __("guides.{$key}.purpose");
    }

    /**
     * The everyday task, in order.
     *
     * @return array<int, string>
     */
    public static function steps(string $key): array
    {
        return self::lines("guides.{$key}.steps");
    }

    /**
     * What changes elsewhere when this screen is used — the question nothing else answers.
     *
     * @return array<int, string>
     */
    public static function affects(string $key): array
    {
        return self::lines("guides.{$key}.affects");
    }

    /**
     * The constraints that would otherwise surprise someone.
     *
     * @return array<int, string>
     */
    public static function rules(string $key): array
    {
        return self::lines("guides.{$key}.rules");
    }

    /** @return array<int, string> */
    private static function lines(string $key): array
    {
        $lines = __($key);

        return is_array($lines) ? array_values($lines) : [];
    }

    /**
     * Every screen class the panels actually render, discovered from disk.
     *
     * The conformance gate compares this against SCREENS + EXEMPT, so a new resource or page fails
     * the build until someone decides whether it needs a guide. Discovery rather than a hand-typed
     * list, for the same reason the other registries do it: a list nobody updates classifies nothing.
     *
     * @return array<int, class-string>
     */
    public static function discoverScreens(): array
    {
        $found = [];

        foreach (['Admin/Resources', 'Admin/Pages', 'Portal/Resources', 'Portal/Pages'] as $dir) {
            $path = app_path("Filament/{$dir}");

            if (! is_dir($path)) {
                continue;
            }

            foreach ((new Finder)->files()->in($path)->name('*.php') as $file) {
                $class = self::classFor($file);

                if ($class === null || ! class_exists($class)) {
                    continue;
                }

                $reflection = new ReflectionClass($class);

                // Traits (the Concerns/ directories), abstract bases and Filament's own
                // sub-page classes (ListX/CreateX/EditX) are not screens an operator navigates
                // to as a subject — the RESOURCE is. Relation managers likewise render inside
                // another screen's page.
                if ($reflection->isAbstract() || $reflection->isTrait() || $reflection->isInterface()) {
                    continue;
                }

                if ($reflection->isSubclassOf(\Filament\Resources\Resource::class)
                    || ($reflection->isSubclassOf(Page::class)
                        && ! $reflection->isSubclassOf(\Filament\Resources\Pages\Page::class))) {
                    $found[] = $class;
                }
            }
        }

        sort($found);

        return $found;
    }

    /** PSR-4: app/Filament/… => App\Filament\… */
    private static function classFor(SplFileInfo $file): ?string
    {
        $relative = str_replace(app_path().DIRECTORY_SEPARATOR, '', $file->getRealPath());

        if ($relative === $file->getRealPath()) {
            return null;
        }

        return 'App\\'.str_replace([DIRECTORY_SEPARATOR, '.php'], ['\\', ''], $relative);
    }
}
