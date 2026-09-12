<?php

namespace App\Models;

use App\Models\Concerns\IsCodeCatalogue;
use App\Models\Concerns\RefusesDeletionWhenReferenced;
use App\Support\ActivityLogging;
use App\Support\Attributes\DeletableWhenUnused;
use App\Support\Attributes\PortfolioShared;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * فئة أصل ثابت — the asset CLASS, and what it decides for every asset entered under it
 * (meeting 2026-09-02, points 11 · 13 · 14).
 *
 * `fixed_assets.category` was free text with six seeded suggestions; the asset number was typed by
 * hand; the salvage value, the useful life and the tax pool were typed per asset with nothing
 * proposing them. SAP's asset class drives the number range, the depreciation key and the account
 * determination, and every register in the market (Yardi Fixed Assets, Odoo's asset model) carries
 * the same defaults — so the accountant's "category first, and the number comes from it" is the
 * standard, not a preference.
 *
 * ## What the row decides, and how far
 *
 * - {@see $tag_prefix} is the NUMBER SERIES: an asset saved with no tag is numbered
 *   `{prefix}-0001` per property ({@see FixedAsset::generateTag()}); a tag supplied by the form or
 *   by a migrating register is kept.
 * - {@see $default_useful_life_months}, {@see $default_salvage_value} and {@see $default_tax_pool}
 *   are PREFILLS, never derivations: they fill a blank field when the category is picked (the form)
 *   or the row is created (the model, for the importer and any door with no form), and are never
 *   read again. What is on the asset is what depreciates; revising the class leaves every asset
 *   already registered alone — the same rule that keeps an issued invoice on its billed VAT rate.
 * - The memo value ships at **1.00** on every row (SAP's rule): a fully-depreciated asset then
 *   stays on the register at one pound rather than at nil, which is what the accountant asked for
 *   under "salvage default 1". The operator may set 0 on a class that scraps to nothing.
 *
 * Everything else — the memo, the flush, the labels, the floor — is {@see IsCodeCatalogue}.
 */
#[DeletableWhenUnused(
    blockedBy: ['fixedAssets'],
    instead: 'Deactivate it. A class that has numbered an asset stays in the register, because every asset under it stores the code itself and its tag carries the series prefix.',
)]
// Shared: an asset class is an accounting definition (SAP keeps it at chart-of-depreciation level),
// and the same chiller is the same kind of thing in every mall the operator runs.
#[PortfolioShared]
class FixedAssetCategory extends Model
{
    use IsCodeCatalogue;
    use LogsActivity;
    use RefusesDeletionWhenReferenced;

    /**
     * The classes a fresh install ships — the floor `ValueSets` accepts before the catalogue is
     * seeded, labelled by `admin.enums.category_suggestions.fixed_asset` in both languages. The
     * seeder gives each its series prefix and its defaults; a stored value outside this list is the
     * operator's own row.
     *
     * @var list<string>
     */
    public const FLOOR = ['furniture', 'equipment', 'HVAC', 'IT', 'vehicles', 'fit-out', 'generator', 'elevator'];

    protected $fillable = [
        'code',
        'name_en',
        'name_ar',
        'tag_prefix',
        'default_useful_life_months',
        'default_salvage_value',
        'default_tax_pool',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'default_useful_life_months' => 'integer',
        'default_salvage_value' => 'decimal:2',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected $attributes = [
        'is_active' => true,
        'sort_order' => 0,
    ];

    /** The assets registered under this class — what makes it undeletable once used. */
    public function fixedAssets(): HasMany
    {
        return $this->hasMany(FixedAsset::class, 'category', 'code');
    }

    protected static function catalogueMemoKey(): string
    {
        return 'fixed_asset_category';
    }

    protected static function catalogueFallbackGroup(): string
    {
        return 'admin.enums.category_suggestions.fixed_asset';
    }

    /** @return array<int, string> */
    protected static function catalogueFloorCodes(): array
    {
        return self::FLOOR;
    }

    protected static function booted(): void
    {
        // A series prefix is what the row IS for, so a blank one is derived from the code rather
        // than refused: an operator adding "Generator" gets `GEN` and can change it before the
        // first asset is numbered. Upper-cased either way — `gen` and `GEN` are one series.
        static::saving(function (self $category): void {
            $prefix = strtoupper(trim((string) $category->tag_prefix));

            if ($prefix === '') {
                $prefix = strtoupper(substr((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $category->code), 0, 4)) ?: 'FA';
                $base = $prefix;
                $n = 1;

                while (static::query()->where('tag_prefix', $prefix)->whereKeyNot($category->getKey() ?? 0)->exists()) {
                    $prefix = substr($base, 0, 3).(++$n);
                }
            }

            $category->tag_prefix = $prefix;
        });
    }

    /**
     * What a new asset of this class is proposed with. ONE query, deliberately NOT memoised like
     * the labels are: the label memo is process-local and `queue:work`/Horizon is one long-lived
     * process, which for a label means a rename shows late and for a LIFE or a MEMO VALUE means an
     * import job on a warm worker registers every row under the figures the accountant corrected
     * an hour ago — a money default, not a word. An asset is registered rarely; the form asks once
     * per class pick and the model once per create, and a query per ask is nothing.
     *
     * @return array{tag_prefix: ?string, useful_life_months: ?int, salvage_value: ?float, tax_pool: ?string}|null
     */
    public static function defaultsFor(?string $code): ?array
    {
        if ($code === null || $code === '') {
            return null;
        }

        try {
            $row = static::query()
                ->where('code', $code)
                ->first(['code', 'tag_prefix', 'default_useful_life_months', 'default_salvage_value', 'default_tax_pool']);
        } catch (\Throwable) {
            // The table is absent while the migration that creates it has not run yet.
            return null;
        }

        return $row === null ? null : [
            'tag_prefix' => $row->tag_prefix,
            'useful_life_months' => $row->default_useful_life_months,
            'salvage_value' => $row->default_salvage_value === null ? null : (float) $row->default_salvage_value,
            'tax_pool' => $row->default_tax_pool,
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return ActivityLogging::for($this, 'fixed_asset_category');
    }
}
