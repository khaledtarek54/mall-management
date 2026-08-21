<?php

namespace App\Models;

use App\Models\Concerns\RefusesDeletionWhenReferenced;
use App\Support\Attributes\DeletableWhenUnused;
use App\Support\Attributes\PortfolioShared;
use App\Support\ValueSets;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * تصنيف تجاري — the merchandising mix, as rows.
 *
 * Twelve values in a `const` on {@see Tenant} drove the store directory, the public shopper API's
 * category filter and every tenant-mix analysis an owner reads. Yardi and MRI make this a row for
 * the reason a leasing team would recognise: the mix is their working vocabulary and it is revised
 * per mall and per season. A mall that lands a cinema, a clinic cluster or a co-working floor wants
 * it in the directory that afternoon.
 *
 * Twelve also flattens differences an Egyptian operator cares about — a pharmacy and a gym are both
 * `health_beauty`, a phone shop and a white-goods showroom are both `electronics`.
 *
 * Same shape as {@see PaymentMethod} and {@see ExpenseCategory}: a code the rows already store, a
 * bilingual name, an active flag, and `ValueSets` widened from the active set with the twelve as its
 * floor. Note for whoever adds the FIFTH of these — the memo/flush/labelFor/options quartet is now
 * repeated four times and has earned extraction into a shared concern; it has not been done here
 * because refactoring three shipped catalogues to save one is the wrong trade today.
 */
#[DeletableWhenUnused(
    blockedBy: ['tenants'],
    instead: 'Deactivate it. A category that classified a retailer stays in the register, because the directory, the shopper app and every historical tenant-mix report read its label.',
)]
// Shared: the mix is one vocabulary across the portfolio, so two malls can be compared.
#[PortfolioShared]
class RetailCategory extends Model
{
    use LogsActivity;
    use RefusesDeletionWhenReferenced;

    private const MEMO = 'retail_category.map';

    protected $fillable = [
        'code',
        'name_en',
        'name_ar',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected $attributes = [
        'is_active' => true,
        'sort_order' => 0,
    ];

    protected static function booted(): void
    {
        // A column default applies when the column is OMITTED, never when null is written to it,
        // and a blanked numeric field in Filament submits null.
        static::saving(fn (self $row) => $row->sort_order ??= 0);

        $flush = function (): void {
            app()->forgetInstance(self::MEMO);
            foreach (config('app.supported_locales', ['en', 'ar']) as $loc) {
                app()->forgetInstance(self::MEMO.'.labels.'.$loc);
            }

            ValueSets::flushCatalogueCache();
        };

        static::saved($flush);
        static::deleted($flush);
    }

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

    /** Retailers classified here — what makes a category undeletable once used. */
    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class, 'retail_category', 'code');
    }

    /**
     * Active codes — what {@see ValueSets} widens `tenants.retail_category` from.
     *
     * Safe before the table exists: `ValueSets::allowed()` runs from the global `eloquent.saving`
     * listener, including during migrations that predate this one.
     *
     * @return array<int, string>
     */
    public static function codes(): array
    {
        if (app()->has(self::MEMO)) {
            return app(self::MEMO);
        }

        try {
            $codes = static::query()->where('is_active', true)->orderBy('sort_order')->pluck('code')->all();
        } catch (\Throwable) {
            return [];
        }

        app()->instance(self::MEMO, $codes);

        return $codes;
    }

    /**
     * `code => label` for a picker — active rows first, the floor only when the catalogue is empty.
     *
     * Rows first, NOT the `ValueSets` union: the union keeps the twelve floor codes for ever, so
     * keying off it would leave a retired category in every dropdown and make `is_active` inert.
     * That exact mistake shipped once on `ExpenseCategory`.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        try {
            $rows = static::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get()
                ->mapWithKeys(fn (self $c) => [$c->code => $c->label()])
                ->all();

            if ($rows !== []) {
                return $rows;
            }
        } catch (\Throwable) {
            // Before the table exists.
        }

        return collect(ValueSets::allowed('tenants', 'retail_category') ?? [])
            ->mapWithKeys(fn (string $code) => [$code => static::labelFor($code)])
            ->all();
    }

    /**
     * The label for one stored code.
     *
     * Inactive rows included: retiring a category must not blank the label on the retailers already
     * classified under it, or on a historical tenant-mix report.
     */
    public static function labelFor(?string $code): string
    {
        if ($code === null || $code === '') {
            return '—';
        }

        $labels = app()->has(self::MEMO.'.labels.'.app()->getLocale())
            ? app(self::MEMO.'.labels.'.app()->getLocale())
            : tap(static::safeLabels(), fn (array $m) => app()->instance(self::MEMO.'.labels.'.app()->getLocale(), $m));

        if (isset($labels[$code])) {
            return $labels[$code];
        }

        $key = "admin.retail_categories.{$code}";
        $translated = __($key);

        return $translated === $key ? $code : $translated;
    }

    /** @return array<string, string> */
    private static function safeLabels(): array
    {
        try {
            return static::query()->get()->mapWithKeys(fn (self $c) => [$c->code => $c->label()])->all();
        } catch (\Throwable) {
            return [];
        }
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['code', 'name_en', 'name_ar', 'is_active', 'sort_order'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('retail_category');
    }
}
