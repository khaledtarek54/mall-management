<?php

namespace App\Models\Concerns;

use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\RetailCategory;
use App\Models\TenantRequestSubcategory;
use App\Models\VendorDocumentType;
use App\Models\ViolationCategory;
use App\Support\ValueSets;
use Illuminate\Database\Eloquent\Builder;

/**
 * A register of operator-editable CODES that a column stores and a picker offers.
 *
 * Six models have this shape — {@see PaymentMethod}, {@see ExpenseCategory},
 * {@see TenantRequestSubcategory}, {@see RetailCategory},
 * {@see ViolationCategory}, {@see VendorDocumentType} — and the first four
 * each carried their own copy of the same four methods. That duplication had already produced one
 * cross-cutting bug: the label memo was keyed without the locale, so a request that switches
 * language (every PDF service, every queued notification) read the other language's cache. Finding
 * it once meant fixing it four times. One seam means the next one is fixed once.
 *
 * ## What a catalogue owes
 *
 * - `code`, `name_en`, `name_ar`, `is_active`, `sort_order` columns.
 * - {@see catalogueMemoKey()} — a unique per-model prefix for the request-scoped memos.
 * - {@see catalogueFallbackGroup()} — the lang group that labels the SHIPPED codes, for a database
 *   that has not been seeded and for a legacy value with no row.
 * - a `ValueSets` floor and a `CATALOGUE_WIDENED` entry per column it drives, so the enforced set
 *   and the offered set widen together.
 *
 * ## The four rules this encodes, each learned the hard way
 *
 * **The floor applies PER CODE.** A shipped code stays offered until a ROW retires it. Both simpler
 * rules are wrong in a way that has cost a screen: the union of floor ∪ active leaves a retired code
 * in every dropdown and makes `is_active` inert (`ExpenseCategory` shipped that), while rows-first
 * drops a shipped code the catalogue happens not to mention — which took `bank` out of the deposit
 * picker on every seeded install while both deposit forms defaulted to it.
 *
 * **Labels include INACTIVE rows.** Retiring a code stops it being offered; it must not blank the
 * label on a document that already carries it, or on a historical report.
 *
 * **Never fall back to a raw translation key.** An operator-added code has no lang key, so
 * resolving the fallback group against it renders `admin.enums.method.fawry` on the very screen
 * whose filter lists Fawry. The last resort is the code itself.
 *
 * **A read must survive the table not existing.** `ValueSets::allowed()` is reached from the global
 * `eloquent.saving: *` listener, so these run during the migration that creates the table and during
 * every earlier migration. An empty answer is correct there — the floor is then the whole set,
 * which is the behaviour that predates the catalogue.
 */
trait IsCodeCatalogue
{
    /** A unique prefix for this catalogue's request-scoped memos. */
    abstract protected static function catalogueMemoKey(): string;

    /** The lang group labelling the shipped codes, for an unseeded database or a legacy value. */
    abstract protected static function catalogueFallbackGroup(): string;

    /**
     * Memo suffixes this catalogue fills BEYOND `codes` and `labels.{locale}`.
     *
     * A catalogue whose codes are scoped — by direction, by request type — memoises one entry per
     * scope, and every one of them has to be dropped on write or a queue worker answers from a map
     * built before the row existed.
     *
     * @return array<int, string>
     */
    protected static function catalogueMemoSuffixes(): array
    {
        return [];
    }

    /**
     * Locales whose label memo must be dropped when a row changes.
     *
     * Every locale, not just the current one: an operator editing a name in English must not leave
     * the Arabic cache holding the old word.
     *
     * @return array<int, string>
     */
    protected static function catalogueLocales(): array
    {
        return (array) config('app.supported_locales', ['en', 'ar']);
    }

    /** Wire the flush. A model with its own `booted()` keeps it — this boots independently. */
    public static function bootIsCodeCatalogue(): void
    {
        static::saved(fn () => static::flushCatalogue());
        static::deleted(fn () => static::flushCatalogue());

        // A column default applies when the column is OMITTED, never when null is written to it,
        // and a blanked numeric field in Filament submits null.
        static::saving(function ($row): void {
            $row->sort_order ??= 0;
        });
    }

    /** Drop every memo this catalogue fills, and `ValueSets`' per-process table map. */
    public static function flushCatalogue(): void
    {
        static::forgetCatalogueMemos();

        // The enforced set is derived from this catalogue — see ValueSets::CATALOGUE_WIDENED.
        ValueSets::flushCatalogueCache();
    }

    /**
     * Drop this catalogue's OWN memos and nothing else.
     *
     * Separate from {@see flushCatalogue()} so `ValueSets::flushCatalogueCache()` can call it
     * without recursing back into itself — and it must, because the two caches are one fact stored
     * twice. `guard()`'s last chance before refusing a value re-derives the enforced set from the
     * database, for the case its own docblock describes: a `queue:work` daemon that answered once
     * before an operator activated Fawry keeps refusing a value the web request accepts, and a
     * wrong REFUSAL is the worse direction because the row is lost and the failure reads as a bug
     * in the job.
     *
     * That recovery was a NO-OP from the day it was written. `flushCatalogueCache()` cleared only
     * `ValueSets::$byTable`; the rebuild then called `codes()`, which answered from the container
     * memo that was stale in the first place — so the re-derive re-read the exact cache it was
     * trying to get past. Laravel does not help either: a worker resets `forgetScopedInstances()`
     * between jobs, and these are plain instance bindings.
     */
    public static function forgetCatalogueMemos(): void
    {
        $key = static::catalogueMemoKey();

        foreach (array_merge(['codes'], static::catalogueMemoSuffixes()) as $suffix) {
            app()->forgetInstance($key.'.'.$suffix);
        }

        foreach (static::catalogueLocales() as $locale) {
            app()->forgetInstance($key.'.labels.'.$locale);
        }
    }

    /** The reader's language, falling back to the other rather than to a blank cell. */
    public function label(): string
    {
        return app()->getLocale() === 'ar'
            ? ($this->name_ar ?: $this->name_en)
            : ($this->name_en ?: $this->name_ar);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Active codes — what {@see ValueSets} widens the column from.
     *
     * @return array<int, string>
     */
    public static function codes(): array
    {
        return static::cachedCodes('codes', fn (Builder $q) => $q);
    }

    /**
     * Memoised code list for one scope, safe before the table exists.
     *
     * @param  \Closure(Builder): Builder  $scope
     * @return array<int, string>
     */
    protected static function cachedCodes(string $memoSuffix, \Closure $scope): array
    {
        $memo = static::catalogueMemoKey().'.'.$memoSuffix;

        if (app()->has($memo)) {
            return app($memo);
        }

        try {
            $codes = $scope(static::query()->where('is_active', true))
                ->orderBy('sort_order')
                ->pluck('code')
                ->all();
        } catch (\Throwable) {
            return [];
        }

        app()->instance($memo, $codes);

        return $codes;
    }

    /**
     * `code => label` for a picker — active rows, plus any shipped code no row has retired.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return static::catalogueOptions();
    }

    /**
     * The shared body of every `options()` variant.
     *
     * @param  \Closure(Builder): Builder|null  $scope
     * @param  array<int, string>|null  $floor
     * @return array<string, string>
     */
    protected static function catalogueOptions(?\Closure $scope = null, ?array $floor = null, ?string $fallbackGroup = null): array
    {
        $floor = $floor ?? static::catalogueFloorCodes();
        $rows = [];
        $known = [];

        try {
            $all = static::query()->orderBy('sort_order')->get();
            $known = $all->pluck('code')->all();

            $query = static::query()->where('is_active', true);
            $rows = ($scope ? $scope($query) : $query)
                ->orderBy('sort_order')
                ->get()
                ->mapWithKeys(fn ($row) => [$row->code => $row->label()])
                ->all();
        } catch (\Throwable) {
            // Before the table exists — the floor is the whole answer.
        }

        // ── THE FLOOR APPLIES PER CODE, NOT PER TABLE ─────────────────────────────────────────
        // A shipped code stays offered unless a ROW for it says otherwise. Choosing between "the
        // rows" and "the floor" was wrong in the case that actually happens: `deposit_transactions
        // .method` accepts `cash|bank`, the rail catalogue seeds `bank_transfer` and no `bank`, and
        // both deposit forms `->default('bank')`. So on every seeded install the form's own default
        // stopped being one of its options — Filament resolves a Select's `Rule::in` by labelling
        // the value, cannot, and refuses the submit as INVALID on a field the operator never
        // touched. Editing a deposit already stored as `bank` broke the same way.
        //
        // Per-code also keeps `is_active` meaningful, which the union of floor ∪ active did not: a
        // code the operator switches off has a row saying so, and is dropped.
        foreach ($floor as $code) {
            if (isset($rows[$code]) || in_array($code, $known, true)) {
                continue;
            }

            // Through labelFor(), never __() directly: a floor code with no lang key must render as
            // the code. The floors are wider than their lang groups — `expenses.paid_from` accepts
            // six values and its group names two — so the unguarded version put a raw key on a list.
            //
            // The group is passed only when one was GIVEN, because a catalogue whose `labelFor()`
            // takes a second argument of its own — the request type — must not be handed a string.
            $rows[$code] = $fallbackGroup === null ? static::labelFor($code) : static::labelFor($code, $fallbackGroup);
        }

        return $rows;
    }

    /**
     * `code => label` for a FILTER — active rows and retired ones alike.
     *
     * A form asks "what may I file under?" and a filter asks "what is already filed?", and those are
     * different questions. Pointing a filter at {@see options()} meant that retiring a code made its
     * own history unfindable: the violations recorded under it, the certificates filed under it and
     * the payments taken on it were all still there and no longer reachable from the list they were
     * on. Same reasoning as {@see labelFor()} including inactive rows.
     *
     * @return array<string, string>
     */
    public static function filterOptions(): array
    {
        return static::catalogueFilterOptions();
    }

    /**
     * The shared body of every filter-options variant: EVERY row, plus EVERY floor code.
     *
     * A UNION, unlike {@see catalogueOptions()}, and the difference is the whole point. A form asks
     * what may be filed NOW, so a retired code is dropped. A filter asks what is already filed, and
     * the column accepts floor ∪ active — so anything in that set can be sitting in a row and must
     * be reachable. Choosing rows-first here hid `bank` from the deposit filter on every seeded
     * install, which is the only value that column actually holds.
     *
     * @param  \Closure(Builder): Builder|null  $scope
     * @param  array<int, string>|null  $floor
     * @return array<string, string>
     */
    protected static function catalogueFilterOptions(?\Closure $scope = null, ?array $floor = null, ?string $fallbackGroup = null): array
    {
        $options = [];

        try {
            $query = static::query();
            $options = ($scope ? $scope($query) : $query)
                ->orderBy('sort_order')
                ->get()
                ->mapWithKeys(fn ($row) => [$row->code => $row->label()])
                ->all();
        } catch (\Throwable) {
            // Before the table exists.
        }

        foreach ($floor ?? static::catalogueFloorCodes() as $code) {
            if (! isset($options[$code])) {
                $options[$code] = $fallbackGroup === null ? static::labelFor($code) : static::labelFor($code, $fallbackGroup);
            }
        }

        return $options;
    }

    /**
     * The shipped codes a picker falls back to when the catalogue is empty.
     *
     * Defaults to none: a model that wants a floor overrides this, usually with its `ValueSets` set.
     *
     * @return array<int, string>
     */
    protected static function catalogueFloorCodes(): array
    {
        return [];
    }

    /**
     * The label for ONE stored code.
     *
     * Inactive rows included, deliberately — see the class docblock. `$fallbackGroup` overrides
     * {@see catalogueFallbackGroup()} for a catalogue whose codes are labelled by several groups
     * depending on which column they came from.
     */
    public static function labelFor(?string $code, ?string $fallbackGroup = null): string
    {
        if ($code === null || $code === '') {
            return '—';
        }

        $labels = static::cachedLabels();

        if (isset($labels[$code])) {
            return $labels[$code];
        }

        $key = ($fallbackGroup ?? static::catalogueFallbackGroup()).'.'.$code;
        $translated = __($key);

        return $translated === $key ? $code : $translated;
    }

    /**
     * The label map, memoised PER LOCALE.
     *
     * Keyed by locale because a PDF service and a queued notification both switch language
     * mid-request; a single key meant English read the Arabic cache. All four of the catalogues
     * that predate this trait had that bug, and one of them ALSO forgot the wrong key on write —
     * `tenant_request_subcategory` dropped `…labels` while filling `…labels.en`, so an operator
     * renaming a subcategory saw the old word until the worker restarted.
     *
     * @return array<string, string>
     */
    protected static function cachedLabels(): array
    {
        $memo = static::catalogueMemoKey().'.labels.'.app()->getLocale();

        return app()->has($memo)
            ? app($memo)
            : tap(static::catalogueLabels(), fn (array $m) => app()->instance($memo, $m));
    }

    /**
     * The rows as `code => label`, INACTIVE included. Overridable by a catalogue whose codes are
     * unique only within a scope — see {@see TenantRequestSubcategory}.
     *
     * @return array<string, string>
     */
    protected static function catalogueLabels(): array
    {
        try {
            return static::query()->get()->mapWithKeys(fn ($row) => [$row->code => $row->label()])->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
