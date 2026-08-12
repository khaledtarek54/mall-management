<?php

namespace App\Support;

use App\Contracts\DeliverableReport;
use App\Filament\Admin\Pages\ActivityLog;
use App\Filament\Admin\Pages\ArAging;
use App\Filament\Admin\Pages\ArAgingByType;
use App\Filament\Admin\Pages\ArCollections;
use App\Filament\Admin\Pages\BalanceSheet;
use App\Filament\Admin\Pages\BillingRunPreview;
use App\Filament\Admin\Pages\CashFlow;
use App\Filament\Admin\Pages\Dashboard;
use App\Filament\Admin\Pages\ExpirationSchedule;
use App\Filament\Admin\Pages\GeneralLedger;
use App\Filament\Admin\Pages\IncomeStatement;
use App\Filament\Admin\Pages\MonthEndClose;
use App\Filament\Admin\Pages\OccupancyCost;
use App\Filament\Admin\Pages\OccupancyMap;
use App\Filament\Admin\Pages\RentRoll;
use App\Filament\Admin\Pages\Reports;
use App\Filament\Admin\Pages\SalesAnalytics;
use App\Filament\Admin\Pages\Settings;
use App\Filament\Admin\Pages\TrialBalance;
use App\Filament\Admin\Pages\VatReturn;
use App\Filament\Admin\Pages\WeeklySpend;
use App\Filament\Admin\Pages\Workflows;
use Filament\Facades\Filament;

/**
 * Every report this system produces, in one list — the registry behind the Reports hub.
 *
 * There are nineteen of them and they were scattered across five sidebar groups: Accounting,
 * Receivables, Leasing, Settings, and a handful with no group at all. Nothing anywhere said what
 * the system could tell you. An operator who had not been shown a report did not know it existed,
 * and one who had been shown it once had to remember which group it lived under. That is the
 * difference the benchmark systems make with a single Reports menu — the reports were never the
 * missing part, the index was.
 *
 * **Classification is mandatory, so a new report cannot be invisible.** Every page under
 * `app/Filament/Admin/Pages` is either catalogued here or exempt with a reason, and
 * `ReportCatalogueConformanceTest` fails the build on an unclassified one. The same arrangement
 * `SearchPolicy` puts on resources and `PropertyIsolation` on models, for the same reason: the
 * person adding the twentieth report will not know this file exists, and their screen will work
 * perfectly while being reachable only by URL.
 *
 * **Access is not decided here.** Each page answers `canAccess()` for itself, and the hub asks it —
 * so a report is listed exactly when the operator could open it. Duplicating the permission into
 * this registry would be a second opinion that drifts, and the drift would show up as either a
 * teasing link or a hidden report.
 */
class ReportCatalogue
{
    public const FINANCIAL = 'financial';

    public const RECEIVABLES = 'receivables';

    public const LEASING = 'leasing';

    public const OPERATIONS = 'operations';

    public const TAX = 'tax';

    /** Display order of the categories on the hub. */
    public const CATEGORIES = [self::FINANCIAL, self::RECEIVABLES, self::LEASING, self::OPERATIONS, self::TAX];

    /**
     * page class => [category, a one-line description key, and the words an operator might search].
     *
     * The description is the field that earns the hub. A list of nineteen titles is a menu; what
     * makes it usable is each line saying what question the report answers, so somebody can find
     * the right one without opening five.
     *
     * @var array<class-string, array{category: string, key: string, keywords: array<int, string>}>
     */
    public const REPORTS = [
        // ---- Financial statements ----
        IncomeStatement::class => ['category' => self::FINANCIAL, 'key' => 'income_statement', 'keywords' => ['p&l', 'profit', 'loss', 'revenue', 'expenses']],
        BalanceSheet::class => ['category' => self::FINANCIAL, 'key' => 'balance_sheet', 'keywords' => ['assets', 'liabilities', 'equity']],
        CashFlow::class => ['category' => self::FINANCIAL, 'key' => 'cash_flow', 'keywords' => ['cash', 'bank', 'movement']],
        TrialBalance::class => ['category' => self::FINANCIAL, 'key' => 'trial_balance', 'keywords' => ['tb', 'debits', 'credits']],
        GeneralLedger::class => ['category' => self::FINANCIAL, 'key' => 'general_ledger', 'keywords' => ['gl', 'account', 'entries', 'statement']],
        MonthEndClose::class => ['category' => self::FINANCIAL, 'key' => 'month_end_close', 'keywords' => ['close', 'period', 'lock']],
        Reports::class => ['category' => self::FINANCIAL, 'key' => 'monthly_close', 'keywords' => ['month', 'kpi', 'summary']],
        WeeklySpend::class => ['category' => self::FINANCIAL, 'key' => 'weekly_spend', 'keywords' => ['spend', 'cost', 'week', 'expenses']],

        // ---- Receivables ----
        ArAging::class => ['category' => self::RECEIVABLES, 'key' => 'ar_aging', 'keywords' => ['ageing', 'aging', 'overdue', 'debtors', 'arrears']],
        ArAgingByType::class => ['category' => self::RECEIVABLES, 'key' => 'ar_aging_by_type', 'keywords' => ['ageing', 'charge type', 'rent', 'service charge']],
        ArCollections::class => ['category' => self::RECEIVABLES, 'key' => 'ar_collections', 'keywords' => ['collections', 'paid', 'recovery']],
        BillingRunPreview::class => ['category' => self::RECEIVABLES, 'key' => 'billing_run_preview', 'keywords' => ['billing', 'run', 'preview', 'dry run']],

        // ---- Leasing ----
        RentRoll::class => ['category' => self::LEASING, 'key' => 'rent_roll', 'keywords' => ['tenancy schedule', 'rent', 'occupancy']],
        ExpirationSchedule::class => ['category' => self::LEASING, 'key' => 'expiration_schedule', 'keywords' => ['expiry', 'renewals', 'rollover']],
        OccupancyMap::class => ['category' => self::LEASING, 'key' => 'occupancy_map', 'keywords' => ['vacancy', 'floor', 'units']],
        OccupancyCost::class => ['category' => self::LEASING, 'key' => 'occupancy_cost', 'keywords' => ['occupancy cost', 'ocr', 'affordability']],
        SalesAnalytics::class => ['category' => self::LEASING, 'key' => 'sales_analytics', 'keywords' => ['turnover', 'sales', 'percentage rent']],

        // ---- Tax ----
        VatReturn::class => ['category' => self::TAX, 'key' => 'vat_return', 'keywords' => ['vat', 'return', 'output', 'input', 'eta']],

        // ---- Operations ----
        Workflows::class => ['category' => self::OPERATIONS, 'key' => 'workflows', 'keywords' => ['approvals', 'process', 'diagram']],
        ActivityLog::class => ['category' => self::OPERATIONS, 'key' => 'activity_log', 'keywords' => ['audit', 'history', 'who changed']],
    ];

    /**
     * Pages that are not reports, with the reason.
     *
     * A page is a report when it ANSWERS A QUESTION about data the operator already has. The two
     * here do something else: one is the landing screen and the other changes configuration.
     * Listing either would make the hub a site map.
     *
     * @var array<class-string, string>
     */
    public const EXEMPT = [
        Dashboard::class => 'The landing screen. It shows widgets drawn from the reports rather than being one.',
        Settings::class => 'Configuration — it changes what the system does rather than reporting on what it did.',
    ];

    /**
     * The reports this operator can actually open, grouped by category and in registry order.
     *
     * `canAccess()` is each page's own answer, asked here rather than duplicated — a report the
     * operator cannot open must not be listed, and a permission copied into this file would drift
     * from the one the page enforces.
     *
     * @return array<string, array<int, array{page: class-string, key: string, title: string, description: string, url: string}>>
     */
    public static function visibleTo(): array
    {
        $grouped = [];

        foreach (self::REPORTS as $page => $meta) {
            if (! rescue(fn () => $page::canAccess(), false, false)) {
                continue;
            }

            $grouped[$meta['category']][] = [
                'page' => $page,
                'key' => $meta['key'],
                'title' => self::titleOf($page, $meta['key']),
                'description' => __("admin.report_hub.descriptions.{$meta['key']}"),
                'url' => rescue(fn () => $page::getUrl(), '#', false),
            ];
        }

        // Registry order within a category, category order from CATEGORIES — so the hub reads the
        // same way every time rather than shuffling with whatever the operator may see.
        return collect(self::CATEGORIES)
            ->mapWithKeys(fn (string $category) => [$category => $grouped[$category] ?? []])
            ->filter(fn (array $reports) => $reports !== [])
            ->all();
    }

    /**
     * The page's own navigation label, which is what the operator already knows it by.
     *
     * Falls back to the catalogue key only if a page has no label — a report that renamed itself
     * and a hub that did not would be two names for one screen.
     */
    public static function titleOf(string $page, string $key): string
    {
        return rescue(fn () => $page::getNavigationLabel(), null, false)
            ?: __("admin.report_hub.descriptions.{$key}");
    }

    /**
     * Reports that cannot be scheduled yet, and why.
     *
     * Delivery needs a report that can render without a browser
     * ({@see DeliverableReport}), and most pages still build their CSV inside the
     * export action's closure, where only a click can reach it. Listing them here rather than
     * letting them quietly not appear is the point: a scheduling picker missing half the catalogue
     * looks like the feature is broken, and a stated "not yet, because…" is information. A
     * conformance test fails on a report that is in neither camp.
     *
     * @var array<class-string, string>
     */
    public const NOT_DELIVERABLE = [
        GeneralLedger::class => 'Its CSV needs an account chosen, and a saved view with none would deliver an empty file every month. Deliverable once the export builds from parameters alone.',
        VatReturn::class => 'Its CSV is assembled inside the export action rather than from a service. Small to lift out — it is next.',
        ArAging::class => 'Its CSV is built from a bucket-specific query in the action. Next after the VAT return.',
        ArAgingByType::class => 'CSV built inline in the export action.',
        ArCollections::class => 'CSV built inline in the export action.',
        BillingRunPreview::class => 'A dry run of what the next billing would raise — it belongs to a moment, not a schedule.',
        MonthEndClose::class => 'A checklist an operator works through, not a document to receive.',
        Reports::class => 'CSV built inline in the export action.',
        WeeklySpend::class => 'CSV built inline in the export action.',
        RentRoll::class => 'CSV built inline in the export action.',
        ExpirationSchedule::class => 'CSV built inline in the export action.',
        OccupancyMap::class => 'A visual floor plan; a CSV of it would answer a different question.',
        OccupancyCost::class => 'CSV built inline in the export action.',
        SalesAnalytics::class => 'CSV built inline in the export action.',
        Workflows::class => 'A diagram of how the system works, not a report on data.',
        ActivityLog::class => 'An audit trail that is searched, not received — a scheduled dump of it would be unread by construction.',
    ];

    /** The page class behind a catalogue key, or null when the key is stale. */
    public static function pageFor(string $key): ?string
    {
        foreach (self::REPORTS as $page => $meta) {
            if ($meta['key'] === $key) {
                return $page;
            }
        }

        return null;
    }

    /**
     * Reports this operator can open AND that can be delivered on a schedule.
     *
     * @return array<string, string> catalogue key => title
     */
    public static function deliverableOptions(): array
    {
        return collect(self::REPORTS)
            ->filter(fn (array $meta, string $page) => is_a($page, DeliverableReport::class, true)
                && rescue(fn () => $page::canAccess(), false, false))
            ->mapWithKeys(fn (array $meta, string $page) => [$meta['key'] => self::titleOf($page, $meta['key'])])
            ->all();
    }

    /** Every admin page class, for the conformance gate. */
    public static function allAdminPages(): array
    {
        return collect(Filament::getPanel('admin')->getPages())
            ->filter(fn (string $page) => str_starts_with($page, 'App\\Filament\\Admin\\Pages\\'))
            ->values()
            ->all();
    }
}
