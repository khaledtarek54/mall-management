<?php

namespace App\Filament\Admin\Pages\Concerns;

use App\Models\Asset;
use App\Models\FiscalYear;
use App\Services\Accounting\LedgerReportService;
use App\Support\Filament\PropertyField;
use App\Support\ReportPreferences;
use App\Support\TenantScope;
use App\Support\UnallocatedNotice;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Shared scaffold for the read-only ledger report PAGES — Trial Balance, General
 * Ledger, Income Statement, Balance Sheet, Cash Flow, and the two statutory returns
 * (VAT and Withholding). SEVEN pages; `RentRoll` shares the blade and NOT this trait,
 * so it has no `$year`/`$period` at all — its parameter is `$asOf`. Centralizes the year +
 * property filter state, the `general_ledger.view` gate, the Accounting nav
 * group, the property-scoped asset-id resolution, and the year list, so each
 * page declares only its icon/sort/route/title and the one report-service call.
 *
 * The year/property pickers are a native Filament Schema (`filtersForm`), not
 * hand-written <select> markup. They stay bound to the `$year` / `$assetId`
 * Livewire properties on purpose: the PDF and CSV header actions read those, so
 * screen and export are driven by one piece of state and cannot disagree about
 * which period was exported.
 */
trait ScopesLedgerReport
{
    use KeepsFilterAnswered;

    /**
     * Nullable so a cleared picker cannot leave it UNINITIALISED. `KeepsFilterAnswered` restores it
     * on the same request, so none of its seventeen readers ever sees null — the restore is the
     * actual fix and the type is the belt to its braces.
     */
    public ?int $year = null;

    /**
     * A period WITHIN the selected fiscal year, or null for the whole year.
     *
     * Usually a month (`YYYY-MM`) — but not always: `WithholdingTaxReturn` files Egypt's Form 41
     * quarterly and its own `periodOptions()` offers `YYYY-Qn`. Which shapes are legal is therefore
     * the PAGE's answer, read off its own picker, never a regex here; a shape written twice is what
     * discarded every Form 41 link (SW-131/SW-209).
     *
     * Null is the default, so every existing report opens exactly as it did.
     */
    public ?string $period = null;

    public ?int $assetId = null;

    public static function canAccess(): bool
    {
        return Auth::user()?->can('general_ledger.view') ?? false;
    }

    public function mount(): void
    {
        $this->hydrateLedgerScopeFromQuery();
    }

    /**
     * Take the year, month and property from the URL when a drill-down link supplies them.
     *
     * A statement links into the ledger for the period and property it was itself run for; landing
     * on "this year, all properties" would answer a different question from the one the operator
     * clicked. Every value is validated rather than trusted — `assetId` in particular, which is the
     * property-isolation dimension and arrives from a query string.
     */
    protected function hydrateLedgerScopeFromQuery(): void
    {
        $period = request()->query('period');
        $period = is_string($period) ? $period : null;

        // The year first, because `periodOptions()` is built from it. A link normally carries both
        // (`ReportParameters::urlFor()` writes every declared property), but a hand-trimmed one may
        // name only the period — and defaulting to THIS year would then throw the period away,
        // silently, by failing the membership test below.
        $year = request()->query('year');
        $this->year = is_numeric($year) ? (int) $year : (int) now()->year;

        // **The year is SEARCHED for, never read off the period's first four digits.** That shortcut
        // was the first attempt and it is wrong on exactly the configuration this operator uses: a
        // fiscal year starting in April makes FY2026 run Apr 2026 – Mar 2027, so `periodOptions()`
        // legitimately offers `2027-01`, `2027-02`, `2027-03`. Reading `2027` out of `2027-01` moves
        // the page to FY2027 (Apr 2027 – Mar 2028), where that period is not a member — so the
        // period is discarded AND the year has moved, and the report opens on a different twelve
        // months with nothing to say so. It made the case it was added for worse than before it
        // existed. One neighbouring year each way is the whole span a month can belong to.
        if (! is_numeric($year) && $period !== null && ! array_key_exists($period, $this->periodOptions())) {
            foreach ([$this->year - 1, $this->year + 1] as $candidate) {
                $this->year = $candidate;

                if (array_key_exists($period, $this->periodOptions())) {
                    break;
                }

                $this->year = (int) now()->year;
            }
        }

        // **Validated against the page's OWN period options, not against a shape.** The regex here
        // was `/^\d{4}-\d{2}$/` — every ledger report's period is a month, except the one whose
        // period is a QUARTER: `WithholdingTaxReturn` files Egypt's Form 41 quarterly and offers
        // `2026-Q1`. So its own drill-down link, and every saved view of it, came back nulled and
        // the screen opened on the full YEAR — while the emailed CSV, which never passes through
        // here, still carried the quarter. One report, two periods, and the tell is a figure that
        // does not match rather than an error.
        //
        // Deriving from `periodOptions()` means a page's accepted set is exactly what its own picker
        // offers, so a report with a new period shape needs nothing here — the shape written twice
        // is what drifted.
        $this->period = ($period !== null && array_key_exists($period, $this->periodOptions()))
            ? $period
            : null;

        $assetId = request()->query('assetId');

        if (filled($assetId) && is_numeric($assetId)) {
            // Clamped to the operator's visible set. An unclamped id here would let a link hand
            // someone another mall's ledger, which is the one thing this dimension must not do.
            $visible = TenantScope::visibleAssetIds();
            $candidate = (int) $assetId;

            $this->assetId = ($visible === null || in_array($candidate, $visible, true))
                ? $candidate
                : null;
        }

        // Then this operator's standing choice, for anything the URL did not state. Dates are never
        // remembered (see ReportPreferences::VOLATILE) — only which slice of the business they work.
        ReportPreferences::restore($this);

        // Last word to the property switcher. Everything above — a drill-down link, a remembered
        // choice from the mall this operator was in yesterday — is a value `scopedAssetIds()` will
        // clamp to the SELECTED property anyway. Left unpinned, the disabled picker would name one
        // mall while the rows underneath it came from another, which is the single failure mode a
        // financial statement must not have.
        $this->assetId = TenantScope::currentAssetId() ?? $this->assetId;
    }

    /**
     * Changing the year must clear the month.
     *
     * Livewire keeps `$period` across the update, so without this, picking 2025 while March 2026
     * was selected would leave a report headed "2025" showing March 2026 — the pickers disagreeing
     * with each other, which is worse than either being wrong on its own.
     */
    public function updatedYear(): void
    {
        // Restored FIRST: `updatedYear()` runs before the generic hook, and clearing the year must
        // not then clear the month as though the operator had picked a different one.
        $this->restoreAnsweredFilter('year');

        $this->period = null;
    }

    /**
     * The fiscal year is never blank. `period` deliberately is not listed: its blank means
     * "full year", which is why it is typed `?string` and carries a placeholder saying so.
     *
     * @return array<string, mixed>
     */
    protected function answerableFilters(): array
    {
        return ['year' => (int) now()->year];
    }

    /** The year + property picker strip, rendered above the report table. */
    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(['sm' => 2, 'lg' => 3])
                ->schema($this->ledgerFilterComponents()),
        ]);
    }

    /**
     * The scope controls themselves, so a statement can add one of its own beside them.
     *
     * Exposed as an ARRAY rather than a built Schema because that is what a caller needs: the
     * income statement appends its comparison picker (RP-06) into the same Section, which keeps the
     * property and period controls identical to every other financial statement — the whole point
     * of the shared bar. Rebuilding a Schema's components after the fact is the fragile way to do
     * the same thing.
     *
     * @return array<int, mixed>
     */
    protected function ledgerFilterComponents(): array
    {
        return [
            Select::make('year')
                ->label(__('admin.reports.fiscal_year'))
                ->options(fn (): array => $this->yearOptions())
                ->native(false)
                // NOT CLEARABLE. Filament renders a blank option on every Select unless it is
                // told otherwise, and clearing one sets the bound Livewire property to null —
                // which UNSETS a non-nullable typed property, so every later read of it throws
                // and the page 500s. Measured on all seven report screens that had it.
                //
                // The fix is the control, not the type: there is no such thing as "no fiscal
                // year" or "no period" for a statement, so offering the blank was offering an
                // action that cannot work. Where a blank IS an answer it stays — `period` on
                // the shared ledger bar means "full year", says so in its placeholder, and is
                // typed `?string` accordingly.
                ->selectablePlaceholder(false)
                ->live(),
            // The operator runs a MONTHLY close and could not print that month's trial
            // balance, income statement, balance sheet or cash flow — the pages were
            // hardcoded to 1 Jan–31 Dec while the services already took ranges.
            Select::make('period')
                ->label(__('admin.reports.period'))
                ->options(fn (): array => $this->periodOptions())
                ->placeholder(__('admin.reports.full_year'))
                ->native(false)
                ->live(),
            // Pinned to the selected mall. It used to offer "Consolidated (all)" and every other
            // property, and `TenantScope::reportAssetIds()` clamped each of them straight back to
            // the mall you were already standing in — so the figures were right and the caption
            // above them was wrong. Remembering stays wired here (this picker is exempt from
            // ReportFilters) for the console/All-Properties paths where nothing is pinned.
            PropertyField::reportScope(
                afterStateUpdated: fn ($livewire) => ReportPreferences::remember($livewire),
            ),
        ];
    }

    /** First instant of the selected period — the chosen month, else the fiscal year's start. */
    protected function periodStart(): Carbon
    {
        return $this->selectedMonth()?->copy()->startOfMonth()->startOfDay()
            ?? $this->fiscalYearStart();
    }

    /** Last instant of the selected period — the chosen month, else the fiscal year's end. */
    protected function periodEnd(): Carbon
    {
        return $this->selectedMonth()?->copy()->endOfMonth()->endOfDay()
            ?? $this->fiscalYearEnd();
    }

    /** The chosen month as a Carbon, or null when the whole year is selected. */
    private function selectedMonth(): ?Carbon
    {
        if (! is_string($this->period) || ! preg_match('/^\d{4}-\d{2}$/', $this->period)) {
            return null;
        }

        return Carbon::createFromFormat('Y-m-d', $this->period.'-01') ?: null;
    }

    /**
     * The fiscal year's real boundaries.
     *
     * `FiscalYear` carries `starts_on`/`ends_on` and these pages ignored them, so an operator on a
     * non-calendar year (an April→March mall year is ordinary in Egypt) got a report for the wrong
     * twelve months entirely — silently, since the header just said the year number.
     *
     * Falls back to the calendar year when no `FiscalYear` row exists, which is what an install
     * looks like before the accountant sets one up.
     */
    protected function fiscalYearStart(): Carbon
    {
        $start = $this->fiscalYear()?->starts_on;

        return $start ? Carbon::parse($start)->startOfDay() : Carbon::create($this->year, 1, 1)->startOfDay();
    }

    protected function fiscalYearEnd(): Carbon
    {
        $end = $this->fiscalYear()?->ends_on;

        return $end ? Carbon::parse($end)->endOfDay() : Carbon::create($this->year, 12, 31)->endOfDay();
    }

    /** @var array<int, ?FiscalYear> */
    private array $fiscalYearMemo = [];

    /**
     * Memoised per request and per YEAR, because the year moves during `mount()` while the period is
     * being resolved.
     *
     * `periodOptions()` calls `fiscalYearStart()` and `fiscalYearEnd()`, so every membership test
     * cost two queries and the withholding return's three — paid on every mount since the period
     * guard started deriving from the options, and paid on every scheduled delivery
     * (`DeliverSavedReportService`), where the filter form never renders at all.
     */
    private function fiscalYear(): ?FiscalYear
    {
        return $this->fiscalYearMemo[$this->year] ??= FiscalYear::query()->where('year', $this->year)->first();
    }

    /**
     * The months that make up the selected fiscal year, keyed `YYYY-MM`.
     *
     * Labelled with the real calendar month AND year ("Mar 2027"), not "month 12", because on a
     * non-calendar year the twelfth month falls in the next calendar year and a bare ordinal would
     * be actively misleading.
     *
     * @return array<string, string>
     */
    protected function periodOptions(): array
    {
        $cursor = $this->fiscalYearStart()->copy()->startOfMonth();
        $last = $this->fiscalYearEnd()->copy()->startOfMonth();

        $options = [];

        // Bounded rather than `while ($cursor <= $last)`: a malformed FiscalYear row with ends_on
        // before starts_on would otherwise spin forever behind a page render.
        for ($i = 0; $i < 24 && $cursor->lessThanOrEqualTo($last); $i++) {
            $options[$cursor->format('Y-m')] = $cursor->translatedFormat('M Y');
            $cursor->addMonth();
        }

        return $options;
    }

    /** Human label for the period — goes in the PDF header. */
    protected function periodLabel(): string
    {
        $month = $this->selectedMonth();

        return $month ? $month->translatedFormat('F Y') : (string) $this->year;
    }

    /** Filename-safe period — `2026` or `2026-03`, so a monthly export cannot be mistaken for the year's. */
    protected function periodSlug(): string
    {
        return $this->period ?? (string) $this->year;
    }

    /**
     * The report's asset-id filter, clamped to the user's visible properties.
     *
     * The value type is not decoration: a bare `?array` is `array<mixed, mixed>`, so `$ids[0]`
     * reaches `Asset::find()` as `mixed` and larastan resolves the *array* overload — a Collection,
     * which is never null, which is why `propertyLabel()`'s null guard read as redundant.
     *
     * @return array<int>|null
     */
    /**
     * What this statement is NOT showing, because it is filed against no property (EG-27).
     *
     * Every financial statement scopes with `whereIn('je.asset_id', …)`, which never matches NULL —
     * so an entry with no property was invisible in all of them and nothing said so. It is
     * surfaced rather than folded in: a null asset_id is portfolio-level overhead visible from
     * every mall, so absorbing it would show one operator-wide cost in full on each of them.
     *
     * Lives on the concern rather than on five pages, so a sixth statement inherits the warning
     * instead of being the one that quietly omits money.
     *
     * @return array{count: int, total: float, cumulative: bool}|null
     */
    protected function unallocatedNotice(): ?array
    {
        if (! $this->unallocatedNoticeApplies()) {
            return null;
        }

        [$from, $to] = $this->unallocatedRange();

        // `cumulative` comes back ON the notice, derived from the window by the method that owns
        // it — so a statement reading everything up to a date cannot be worded as though it read a
        // period. A balance sheet is the case: it overrides `unallocatedRange()` to open-ended, and
        // "This period holds 47 entries" is then a claim about a span it never read.
        return app(LedgerReportService::class)->unallocated(
            $this->scopedAssetIds(),
            $from,
            $to,
            $this->unallocatedExcludesClosing(),
            $this->unallocatedAccountId(),
        );
    }

    /**
     * Whether there is a statement here for the notice to be beside at all.
     *
     * A page that has not been asked a question yet has no figures, and a warning saying *"They are
     * NOT in the figures above"* about figures that do not exist is a warning about nothing. The
     * general ledger is the case: it needs an account chosen. Its CSV already refuses outright — a
     * scheduled delivery needs a refusal it can report — so this was the screen alone.
     *
     * A predicate rather than a `null` return from `unallocatedAccountId()`, because null there
     * means the WIDEST population, not "none".
     */
    protected function unallocatedNoticeApplies(): bool
    {
        return true;
    }

    /**
     * Whether the notice's population excludes YEAR-END CLOSING entries — mirroring what this
     * page's own statement does.
     *
     * The income statement and the cash flow pass `excludeClosing: true` to
     * `LedgerReportService::aggregate()`; the trial balance, balance sheet and general ledger do
     * not. The notice has to answer the same way, because the close posts a CONSOLIDATED entry for
     * the null-asset bucket every year — so on those two pages the count included an entry no
     * income statement was ever going to show, roughly doubling the figure.
     *
     * A page that changes which statement it renders must change this with it; the pair is asserted
     * behaviourally in `UnallocatedEntriesAreVisibleNotSilentTest`, which posts a null-asset closing
     * entry and requires the notice to fire on exactly the pages whose figures would have carried it.
     */
    protected function unallocatedExcludesClosing(): bool
    {
        return false;
    }

    /**
     * The account the notice is about, when the statement is about ONE account.
     *
     * Only the general ledger is: it renders a single account's movements, so a portfolio-wide count
     * beside it sizes a population the reader is not looking at.
     */
    protected function unallocatedAccountId(): ?int
    {
        return null;
    }

    /**
     * The notice as one sentence.
     *
     * There are FOUR renderers of it — screen, CSV, the scheduled email's copy of that CSV, and the
     * PDF — and they interpolated the same placeholders in three separate places, which is what let
     * a PDF go out quoting a different figure from the screen it was printed from. All of them now
     * resolve through {@see UnallocatedNotice}; this method stays because the blade calls it on
     * `$this`.
     *
     * @param  array{count: int, total: float, cumulative?: bool}  $notice
     */
    protected function unallocatedBody(array $notice): string
    {
        return UnallocatedNotice::sentence($notice);
    }

    /**
     * The same warning, carried on the CSV — the copy that leaves the building.
     *
     * `unallocatedNotice()` was rendered by the page's blade and by nothing else, so the export, the
     * scheduled email and the owner's pack omitted the same money with nothing on them to say so.
     * The screen is the one surface whose reader can also see the ledger; the attachment goes to an
     * accountant, an owner or an auditor who cannot.
     *
     * **Appended, and only when there is something to say.** A notice printed on clean books is
     * trained away long before the period it matters in — the same rule the on-screen one follows —
     * and a trailing row on every statement would be read as boilerplate.
     *
     * A blank ROW then the sentence in the first cell, with every other cell of that row left empty —
     * so it cannot be mistaken for a data row by a spreadsheet or by a person, and no figures column
     * carries a number that would sum into a total.
     *
     * @param  array{filename: string, headers: array<int, string>, rows: array<int, array<int, string|int|float|null>>}  $csv
     * @return array{filename: string, headers: array<int, string>, rows: array<int, array<int, string|int|float|null>>}
     */
    protected function withUnallocatedNotice(array $csv): array
    {
        $notice = $this->unallocatedNotice();

        if ($notice === null || ($notice['count'] ?? 0) <= 0) {
            return $csv;
        }

        $width = max(1, count($csv['headers']));
        $row = array_fill(0, $width, null);

        $row[0] = UnallocatedNotice::line($notice);

        $csv['rows'][] = array_fill(0, $width, null);
        $csv['rows'][] = $row;

        return $csv;
    }

    /**
     * The window the notice counts over — the page's own period by default.
     *
     * Overridable because a balance sheet is an "as at" statement: it reads everything up to the
     * date, so warning only about the selected month would understate what it is missing.
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    protected function unallocatedRange(): array
    {
        return [$this->periodStart(), $this->periodEnd()];
    }

    protected function scopedAssetIds(): ?array
    {
        return TenantScope::reportAssetIds($this->assetId ?: null);
    }

    /**
     * Human label for the report's scope — used in PDF headers. Derived from the
     * CLAMPED asset-id set (scopedAssetIds), never the raw client-bound assetId, so
     * the header can't name a property the user isn't allowed to see and always
     * matches the PDF body. A single allowed property → its name; else Consolidated.
     */
    protected function propertyLabel(): string
    {
        $ids = $this->scopedAssetIds();

        if (is_array($ids) && count($ids) === 1) {
            // `value()` rather than `find()?->name`: one column instead of a hydrated model, and
            // it is honestly nullable — larastan resolves `find()` through an overload that can
            // return a Collection, which made the null guard read as dead code in some contexts.
            $name = Asset::query()->whereKey($ids[0])->value('name');

            return is_string($name) && $name !== ''
                ? $name
                : __('admin.fields.property_consolidated');
        }

        return __('admin.fields.property_consolidated');
    }

    protected function canViewReports(): bool
    {
        return Auth::user()?->can('general_ledger.view') ?? false;
    }

    /** @return array<int, int> */
    protected function yearOptions(): array
    {
        $years = FiscalYear::query()->orderByDesc('year')->pluck('year')->all();
        if (empty($years)) {
            $years = [(int) now()->year];
        }

        return array_combine($years, $years);
    }
}
