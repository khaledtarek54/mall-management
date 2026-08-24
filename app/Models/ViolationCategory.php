<?php

namespace App\Models;

use App\Models\Concerns\IsCodeCatalogue;
use App\Models\Concerns\RefusesDeletionWhenReferenced;
use App\Support\ActivityLogging;
use App\Support\Attributes\DeletableWhenUnused;
use App\Support\Attributes\PortfolioShared;
use App\Support\ValueSets;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * بند مخالفة — one rule in the mall's house rules, and the standard fine for breaking it.
 *
 * Seven values in a `const` on {@see Violation} drove the field officer's picker, the by-category
 * filter, every "which tenants repeat safety breaches" report, and the description on the invoice
 * that bills the fine. The migration that introduced the column promised the set was "theirs to
 * extend without a migration" and it was not.
 *
 * A rule book is exactly the thing an operator revises. A mall publishes house rules in its tenant
 * handbook, amends them when a problem recurs, and cites the clause on the notice it hands over —
 * `signage` / `noise` / `other` cannot carry that, and "other" is where everything specific went.
 *
 * ## The fine is a PREFILL, never a derivation
 *
 * {@see default_fine_amount} fills the form when a category is picked and an amount has not been
 * typed. It is never read again: `violations.fine_amount` is the operator's number, the fine invoice
 * bills what the violation says, and revising the tariff leaves every recorded violation alone —
 * the same rule that keeps an issued invoice on the VAT rate it was billed at.
 *
 * Everything else — the memo, the flush, the labels, the floor — is {@see IsCodeCatalogue}.
 */
#[DeletableWhenUnused(
    blockedBy: ['violations'],
    instead: 'Deactivate it. A rule that classified a recorded breach stays in the book, because the violation record, the notice served on the tenant and every repeat-offender report read its label.',
)]
// Shared: the house rules are Eltizam's, applied across the malls it runs. A per-property rule book
// would mean re-stating "blocked fire exit" once per mall and losing the portfolio-wide comparison.
#[PortfolioShared]
class ViolationCategory extends Model
{
    use IsCodeCatalogue;
    use LogsActivity;
    use RefusesDeletionWhenReferenced;

    protected $fillable = [
        'code',
        'name_en',
        'name_ar',
        'default_fine_amount',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'default_fine_amount' => 'decimal:2',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected $attributes = [
        'is_active' => true,
        'sort_order' => 0,
    ];

    /** Breaches recorded under this rule — what makes it undeletable once used. */
    public function violations(): HasMany
    {
        return $this->hasMany(Violation::class, 'category', 'code');
    }

    protected static function catalogueMemoKey(): string
    {
        return 'violation_category';
    }

    protected static function catalogueFallbackGroup(): string
    {
        return 'admin.violations.categories';
    }

    /** @return array<int, string> */
    protected static function catalogueMemoSuffixes(): array
    {
        return ['fines'];
    }

    /** @return array<int, string> */
    protected static function catalogueFloorCodes(): array
    {
        return ValueSets::allowed('violations', 'category') ?? [];
    }

    /**
     * The standard fine for this rule, or null when the rule book names none.
     *
     * Memoised like every other read here: the violations list asks once per row when the operator
     * is comparing what was charged against what the book says.
     */
    public static function defaultFineFor(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        $memo = self::catalogueMemoKey().'.fines';

        $map = app()->has($memo)
            ? app($memo)
            : tap(static::safeFines(), fn (array $m) => app()->instance($memo, $m));

        return $map[$code] ?? null;
    }

    /** @return array<string, string|null> */
    private static function safeFines(): array
    {
        try {
            return static::query()->pluck('default_fine_amount', 'code')->all();
        } catch (\Throwable) {
            return [];
        }
    }

    public function getActivitylogOptions(): LogOptions
    {
        return ActivityLogging::for($this, 'violation_category');
    }
}
