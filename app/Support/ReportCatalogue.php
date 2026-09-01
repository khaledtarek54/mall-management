<?php

namespace App\Support;

use App\Contracts\DeliverableReport;
use App\Filament\Admin\Pages\ActivityLog;
use App\Filament\Admin\Pages\Assistant;
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
        IncomeStatement::class => ['category' => self::FINANCIAL, 'key' => 'income_statement', 'keywords' => ['p&l', 'profit', 'loss', 'revenue', 'expenses', 'قائمة الدخل', 'الأرباح', 'الخسائر', 'الإيرادات', 'المصروفات']],
        BalanceSheet::class => ['category' => self::FINANCIAL, 'key' => 'balance_sheet', 'keywords' => ['assets', 'liabilities', 'equity', 'الميزانية', 'المركز المالي', 'الأصول', 'الخصوم', 'حقوق الملكية']],
        CashFlow::class => ['category' => self::FINANCIAL, 'key' => 'cash_flow', 'keywords' => ['cash', 'bank', 'movement', 'التدفقات النقدية', 'النقدية', 'البنك']],
        TrialBalance::class => ['category' => self::FINANCIAL, 'key' => 'trial_balance', 'keywords' => ['tb', 'debits', 'credits', 'ميزان المراجعة', 'مدين', 'دائن']],
        TaxDepreciation::class => ['category' => self::TAX, 'key' => 'tax_depreciation', 'keywords' => ['depreciation', 'tax', 'pool', 'declining', 'law 91', 'إهلاك']],
        GeneralLedger::class => ['category' => self::FINANCIAL, 'key' => 'general_ledger', 'keywords' => ['gl', 'account', 'entries', 'statement', 'دفتر الأستاذ', 'الأستاذ العام', 'القيود', 'كشف حساب']],
        MonthEndClose::class => ['category' => self::FINANCIAL, 'key' => 'month_end_close', 'keywords' => ['close', 'period', 'lock', 'إقفال الشهر', 'إقفال الفترة', 'الإقفال']],
        Reports::class => ['category' => self::FINANCIAL, 'key' => 'monthly_close', 'keywords' => ['month', 'kpi', 'summary', 'الإقفال الشهري', 'ملخص الشهر', 'مؤشرات']],
        WeeklySpend::class => ['category' => self::FINANCIAL, 'key' => 'weekly_spend', 'keywords' => ['spend', 'cost', 'week', 'expenses', 'المصروفات الأسبوعية', 'الإنفاق', 'التكاليف']],

        // ---- Receivables ----
        ArAging::class => ['category' => self::RECEIVABLES, 'key' => 'ar_aging', 'keywords' => ['ageing', 'aging', 'overdue', 'debtors', 'arrears', 'أعمار الديون', 'الذمم المدينة', 'المتأخرات', 'المديونية', 'المستحقات', 'فلوس', 'owes', 'owing', 'owed', 'debt']],
        ArAgingByType::class => ['category' => self::RECEIVABLES, 'key' => 'ar_aging_by_type', 'keywords' => ['ageing', 'charge type', 'rent', 'service charge', 'أعمار حسب النوع', 'رسوم الخدمة', 'الإيجار']],
        ArCollections::class => ['category' => self::RECEIVABLES, 'key' => 'ar_collections', 'keywords' => ['collections', 'paid', 'recovery', 'التحصيل', 'المحصل', 'السداد']],
        BillingRunPreview::class => ['category' => self::RECEIVABLES, 'key' => 'billing_run_preview', 'keywords' => ['billing', 'run', 'preview', 'dry run', 'معاينة الفوترة', 'تشغيل الفوترة', 'الفوترة الشهرية']],

        // ---- Leasing ----
        RentRoll::class => ['category' => self::LEASING, 'key' => 'rent_roll', 'keywords' => ['tenancy schedule', 'rent', 'occupancy', 'كشف الإيجارات', 'جدول الإشغال', 'الإيجارات']],
        ExpirationSchedule::class => ['category' => self::LEASING, 'key' => 'expiration_schedule', 'keywords' => ['expiry', 'renewals', 'rollover', 'جدول انتهاء العقود', 'انتهاء العقود', 'التجديد']],
        ClauseRegister::class => ['category' => self::LEASING, 'key' => 'clause_register', 'keywords' => ['clause', 'co-tenancy', 'kick-out', 'exclusivity', 'radius', 'abstract', 'بند', 'إشغال مشترك']],
        RevenueForecast::class => ['category' => self::LEASING, 'key' => 'revenue_forecast', 'keywords' => ['forecast', 'projection', 'budget', 'income', 'pipeline', 'توقعات الإيرادات', 'التنبؤ', 'الإيرادات المتوقعة']],
        OccupancyMap::class => ['category' => self::LEASING, 'key' => 'occupancy_map', 'keywords' => ['vacancy', 'floor', 'units', 'خريطة الإشغال', 'الشواغر', 'الوحدات', 'الدور']],
        RentableItemMap::class => ['category' => self::LEASING, 'key' => 'rentable_item_map', 'keywords' => ['parking', 'bay', 'kiosk', 'signage', 'storage', 'utilisation', 'موقف', 'كشك']],
        OccupancyCost::class => ['category' => self::LEASING, 'key' => 'occupancy_cost', 'keywords' => ['occupancy cost', 'ocr', 'affordability', 'تكلفة الإشغال', 'نسبة تكلفة الإشغال']],
        SalesAnalytics::class => ['category' => self::LEASING, 'key' => 'sales_analytics', 'keywords' => ['turnover', 'sales', 'percentage rent', 'تحليل المبيعات', 'المبيعات', 'رقم الأعمال', 'نسبة الإيجار']],

        // ---- Tax ----
        VatReturn::class => ['category' => self::TAX, 'key' => 'vat_return', 'keywords' => ['vat', 'return', 'output', 'input', 'eta', 'إقرار القيمة المضافة', 'ضريبة القيمة المضافة', 'الإقرار الضريبي']],
        WithholdingTaxReturn::class => ['category' => self::TAX, 'key' => 'wht_return', 'keywords' => ['withholding', 'wht', 'form 41', 'supplier', 'certificate', 'خصم', 'إضافة']],

        // ---- Operations ----
        Workflows::class => ['category' => self::OPERATIONS, 'key' => 'workflows', 'keywords' => ['approvals', 'process', 'diagram', 'مسارات العمل', 'الاعتمادات', 'الإجراءات']],
        ActivityLog::class => ['category' => self::OPERATIONS, 'key' => 'activity_log', 'keywords' => ['audit', 'history', 'who changed', 'سجل النشاط', 'سجل التغييرات', 'من غيّر']],
        VendorScorecard::class => ['category' => self::OPERATIONS, 'key' => 'vendor_scorecard', 'keywords' => ['vendor', 'supplier', 'sla', 'performance', 'renewal', 'contractor', 'تقييم الموردين', 'أداء المورد', 'المقاولين']],
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
        ConfigurationHealth::class => 'It reports on the SETUP, not on the data — what is unset and what that breaks. Listing it beside the rent roll would put an operator looking for a business answer in front of a maintenance checklist.',
        PropertyOverrides::class => 'Configuration, like the settings page it sits beside — it changes what this property charges rather than reporting on what it charged. Same reasoning as Settings above.',
        Handbook::class => 'The manual, not a measurement. Every report here answers a question about this portfolio\'s data and changes when the data changes; the handbook explains how the system works and reads identically on an empty database. Listing it beside the rent roll would send an operator looking for a number to a page that has none.',
        Budget::class => 'Configuration, and an INPUT screen — it pastes what each P&L account is expected to do. Every report here answers a question about what happened; this states what is planned, and the report that compares the two is the income statement. Same reasoning as Settings and PropertyOverrides above.',
        OpeningBalances::class => 'Cutover data entry — it loads the accountant\'s opening trial balance and creates a DRAFT journal entry. It writes the books rather than reporting on them, and it is used once per go-live rather than per month.',
        Assistant::class => 'A way to FIND a report, not one of them. Every report here answers a question about this portfolio\'s data and changes when the data changes; this searches the guides and this very catalogue, and reads identically on an empty database. Listing it beside the rent roll would send an operator looking for a number to a search box. Same reasoning as the Handbook above.',
        NotificationCenter::class => 'One reader\'s own alert history. Every report here answers a question about the BUSINESS and reads the same for any two operators with the same permissions; this reads differently for every single person, because it is scoped to their own notifications. It is mail, not a report — and listing it in the report hub would promise a portfolio answer and deliver an inbox.',
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
        BillingRunPreview::class => 'A dry run of what the next billing WOULD raise. It belongs to the moment before a run, not to a calendar — and it has no CSV export for the same reason.',
        MonthEndClose::class => 'A checklist an operator works through, not a document to receive. Nothing to export.',
        Reports::class => 'The monthly-close dashboard. Its output is a PDF pack rather than a table, so there is no CSV to attach; scheduling the PDF is its own row.',
        OccupancyMap::class => 'A visual floor plan. A CSV of it would answer a different question from the one the screen answers.',
        RentableItemMap::class => 'The other floor plan, for the same reason: the value is the at-a-glance read of a car park, not a list. The Rentable Items register is where that list already lives, and it exports.',
        Workflows::class => 'A diagram of how the system works, not a report on data.',
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
