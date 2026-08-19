<?php

namespace App\Support;

use App\Support\Filament\PropertyField;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;

/**
 * One parameter bar, so the same question looks the same on every report (RP-02).
 *
 * ## What this replaces
 *
 * "As of which date?" was asked by five reports, each declaring its own `DatePicker::make('asOf')`
 * — structurally identical down to `->native(false)->live()` — under **four different translation
 * keys** for one concept: `reports.aged_as_of`, `rent_roll.as_of`, `expiration_schedule.as_of` and
 * `sales_analytics.as_of`. An operator moving between the rent roll and the ageing report met the
 * same control wearing a different name, and a fifth report would have invented a fifth.
 *
 * That is the Yardi complaint precisely: the reports were each individually reasonable and
 * collectively inconsistent.
 *
 * ## Components, not a component
 *
 * There is no single bar widget, because the reports genuinely differ — an ageing report is asked
 * *as of* a date, a statement is asked *for a period*, and a spend report is asked *between* two.
 * Forcing one shape would mean showing every report a control it has no use for, which is a worse
 * inconsistency than the one being fixed.
 *
 * So this is a vocabulary: each report composes the questions it actually asks, and each question
 * looks and behaves identically wherever it appears. `ReportFilterConformanceTest` fails the build
 * on a report that hand-rolls one of them instead.
 *
 * ## `$onChange` is required, not optional
 *
 * Every one of these is `->live()`, and every report caches its rows. A filter that updates the
 * state without clearing the cache renders the OLD numbers under the NEW date — the single most
 * dangerous failure available on a financial report, because it is invisible and it looks
 * authoritative. Making the callback a required argument means it cannot be forgotten; passing an
 * empty closure is at least a decision somebody typed.
 */
class ReportFilters
{
    /**
     * Report pages that declare a filter of their own instead of taking the shared one, with why.
     *
     * All three are `assetId`, all three are already scoped to what the operator may see, and all
     * three ask a genuinely different question from {@see property()}:
     *
     * @var array<string, string>
     */
    public const EXEMPT = [
        'app/Filament/Admin/Pages/OccupancyMap.php' => 'The map is drawn FOR one property, so its picker is required rather than an optional narrowing — an empty value has no rendering. Scoped through visibleAssets().',
        'app/Filament/Admin/Pages/Concerns/ScopesLedgerReport.php' => 'The ledger reports take the PINNED control (PropertyField::reportScope) rather than this optional narrowing, and they persist the choice themselves. Their picker used to offer "Consolidated (all)" while reportAssetIds() clamped every pick back to the selected mall.',
        'app/Filament/Admin/Pages/GeneralLedger.php' => 'Uses the ledger scope above, alongside its own account picker.',
    ];

    /**
     * "As of" — the date a point-in-time report is answered for.
     *
     * Not optional and not hidden: "90 days late" only means something relative to a day, and a
     * report that fixes it silently at today cannot be reconciled to a statement printed last week.
     */
    public static function asOf(callable $onChange, ?string $label = null): DatePicker
    {
        return DatePicker::make('asOf')
            ->label($label ?? __('admin.reports.as_of'))
            ->native(false)
            ->live()
            ->afterStateUpdated(self::persisting($onChange));
    }

    /** The start of a range. Pair with {@see to()}. */
    public static function from(callable $onChange): DatePicker
    {
        return DatePicker::make('from')
            ->label(__('admin.reports.from'))
            ->native(false)
            ->live()
            ->afterStateUpdated(self::persisting($onChange));
    }

    /** The end of a range. Pair with {@see from()}. */
    public static function to(callable $onChange): DatePicker
    {
        return DatePicker::make('to')
            ->label(__('admin.reports.to'))
            ->native(false)
            ->live()
            ->afterStateUpdated(self::persisting($onChange));
    }

    /**
     * The property a report is answered for — pinned to the mall the operator is standing in.
     *
     * It was nullable, on the theory that the empty option meant "every property I can see". With
     * the property switcher offering only real malls, that set is `[currentId]`: the blank and
     * every other option resolved to the same single property, so the control was a choice in
     * appearance only. {@see PropertyField::reportScope()} shows the answer instead of asking a
     * question it cannot honour, and stays scoped through `EntitySelect` so it can neither offer
     * nor accept a mall this operator may not see.
     *
     * No report calls this today — every one of them scopes through `TenantScope` directly. It is
     * kept, pinned, so that the next one to ask the question gets the honest control rather than
     * reinventing the old one.
     */
    public static function property(callable $onChange, ?string $label = null): Select
    {
        $field = PropertyField::reportScope(afterStateUpdated: self::persisting($onChange));

        return $label !== null ? $field->label($label) : $field;
    }

    /**
     * Run the report's own callback, then remember the choice.
     *
     * Wrapped HERE rather than asked of each page, so remembering cannot be the thing somebody
     * forgets — the same reasoning that makes `$onChange` a required argument. `ReportPreferences`
     * drops every date before storing, so a filter that moves the as-of date runs the page's
     * callback and stores nothing.
     */
    /**
     * Run the report's own callback, then remember the choice.
     *
     * Wrapped HERE rather than asked of each page, so remembering cannot be the thing somebody
     * forgets — the same reasoning that makes `$onChange` a required argument. `ReportPreferences`
     * drops every date before storing, so a filter that moves the as-of date runs the page's
     * callback and stores nothing.
     *
     * `$livewire` is declared explicitly because Filament injects these arguments **by parameter
     * name**, not by position — a variadic closure receives none of them and the page would never
     * be found. The wrapped callback is invoked with no arguments, which is the contract every
     * caller already writes (`fn () => $this->rows = null`).
     */
    private static function persisting(callable $onChange): Closure
    {
        return function ($state, $livewire) use ($onChange) {
            $onChange();

            if ($livewire instanceof Page) {
                ReportPreferences::remember($livewire);
            }
        };
    }
}
