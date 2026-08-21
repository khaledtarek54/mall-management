<?php

namespace App\Models;

use App\Models\Concerns\RefusesDeletionWhenReferenced;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\Journalizers\Concerns\MapsExpenseCategory;
use App\Support\Attributes\DeletableWhenUnused;
use App\Support\Attributes\PortfolioShared;
use App\Support\CostNature;
use App\Support\ValueSets;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * بند مصروف — a kind of cost, and the P&L account it books to.
 *
 * ## Why this is a row
 *
 * The category was the ONLY thing deciding which expense account a supplier bill hits, and it lived
 * in a six-entry `private const` inside {@see MapsExpenseCategory}.
 * Anything outside those six fell to `admin_expense` behind a `Log::warning` — insurance, government
 * fees and licences, bank charges, legal and professional fees, generator fuel. In an Egyptian mall
 * that is most of the overhead, arriving in one bucket. It also drives {@see CostNature}, so the
 * miscoding reached the CAM pool and the tenants' service charge, not only the P&L.
 *
 * ## The floor, and why nothing moves on deploy
 *
 * `ledger_account_id` is NULLABLE and null is the normal state: {@see accountIdOrFloor()} then falls
 * back to the same six-entry map the trait held, and to `admin_expense` beyond it — including the
 * warning, which is still the right noise for a category nobody has classified. So a database that
 * has never seen this table posts exactly as before.
 *
 * A category names its account **directly**, not a `PostingRoles` key, for the reason
 * {@see PaymentMethod} does: `Health::accountingReadiness()` requires every role to be mapped, so
 * "Insurance" as a new role would turn a BLOCKING health row red on every install until the
 * accountant mapped it. A row pointing at an account the operator already has does not.
 */
#[DeletableWhenUnused(
    blockedBy: ['expenses', 'vendorBills', 'custodyTransactions'],
    instead: 'Deactivate it. A category that classified a posted cost stays in the catalogue, because every document and every P&L comparative reads its label — deleting the row would leave those documents naming a code nothing can explain.',
)]
// Shared: how Eltizam classifies its costs is one chart of overhead, not a per-mall opinion.
#[PortfolioShared]
class ExpenseCategory extends Model
{
    use LogsActivity;
    use RefusesDeletionWhenReferenced;

    private const MEMO = 'expense_category.map';

    protected $fillable = [
        'code',
        'name_en',
        'name_ar',
        'ledger_account_id',
        'cost_nature',
        'is_active',
        'sort_order',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected $attributes = [
        'cost_nature' => CostNature::VARIABLE,
        'is_active' => true,
        'sort_order' => 0,
    ];

    protected static function booted(): void
    {
        // A column default applies when the column is OMITTED, never when null is written to it, and
        // blanking a numeric field in Filament submits null.
        static::saving(function (self $category): void {
            $category->sort_order ??= 0;
            $category->cost_nature ??= CostNature::VARIABLE;
        });

        $flush = function (): void {
            app()->forgetInstance(self::MEMO);
            app()->forgetInstance(self::MEMO.'.labels');
            app()->forgetInstance(self::MEMO.'.codes');

            // The enforced set is derived from this catalogue.
            ValueSets::flushCatalogueCache();
        };

        static::saved($flush);
        static::deleted($flush);
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

    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'category', 'code');
    }

    public function vendorBills(): HasMany
    {
        return $this->hasMany(VendorBill::class, 'category', 'code');
    }

    public function custodyTransactions(): HasMany
    {
        return $this->hasMany(CustodyTransaction::class, 'category', 'code');
    }

    /**
     * Active codes — what {@see ValueSets} unions onto its floor, and what the pickers offer.
     *
     * Inactive rows are excluded: retiring a category stops it being offered, it does not invalidate
     * the documents that already carry it — which is why `ValueSets` only ever WIDENS from here.
     *
     * Safe before the table exists: `ValueSets::allowed()` runs from the global `eloquent.saving`
     * listener, including during migrations that predate this one.
     *
     * @return array<int, string>
     */
    public static function codes(): array
    {
        if (app()->has(self::MEMO.'.codes')) {
            return app(self::MEMO.'.codes');
        }

        try {
            $codes = static::query()->where('is_active', true)->orderBy('sort_order')->pluck('code')->all();
        } catch (\Throwable) {
            return [];
        }

        app()->instance(self::MEMO.'.codes', $codes);

        return $codes;
    }

    /**
     * The P&L account this category books to, resolved through the catalogue and then the floor.
     *
     * The ONE place the fallback lives, so the four journalizers that classify a cost cannot drift.
     */
    public static function accountIdOrFloor(
        ?string $code,
        ?int $assetId,
        AccountResolver $accounts,
        string $floorRole,
    ): int {
        $map = app()->has(self::MEMO)
            ? app(self::MEMO)
            : tap(static::safeMap(), fn (array $m) => app()->instance(self::MEMO, $m));

        $id = $map[$code] ?? null;

        if ($id !== null && ($account = LedgerAccount::find($id)) !== null) {
            return $account->id;
        }

        return $accounts->id($floorRole, $assetId);
    }

    /** @return array<string, int|null> */
    private static function safeMap(): array
    {
        try {
            return static::query()->pluck('ledger_account_id', 'code')->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * The label for ONE stored code — what a table cell renders.
     *
     * Never `__("admin.enums.vendor_bill_category.{$code}")`: an operator-added category has no lang
     * key and would print the raw key on the very screen whose filter lists it. Inactive rows are
     * included, because retiring a category must not blank the label on documents that carry it.
     */
    public static function labelFor(?string $code, string $fallbackGroup = 'admin.enums.vendor_bill_category'): string
    {
        if ($code === null || $code === '') {
            return '—';
        }

        $labels = app()->has(self::MEMO.'.labels')
            ? app(self::MEMO.'.labels')
            : tap(static::safeLabels(), fn (array $m) => app()->instance(self::MEMO.'.labels', $m));

        if (isset($labels[$code])) {
            return $labels[$code];
        }

        $key = "{$fallbackGroup}.{$code}";
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

    /**
     * `code => label` for a picker.
     *
     * Keyed off the set the SAVING LISTENER accepts, so a surface cannot offer a value the column
     * refuses — the rule `DepositTransaction::methodOptions()` was written to enforce, and the one
     * EG-11 broke by widening the offer and not the guard.
     *
     * @return array<string, string>
     */
    public static function options(string $fallbackGroup = 'admin.enums.vendor_bill_category'): array
    {
        $enforced = ValueSets::forTable('expenses')['category'] ?? [];

        return collect($enforced)
            ->mapWithKeys(fn (string $code) => [$code => static::labelFor($code, $fallbackGroup)])
            ->all();
    }

    /** Fixed or variable, from the row; the floor is {@see CostNature::MAP}. */
    public static function natureFor(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        try {
            return static::query()->where('code', $code)->value('cost_nature');
        } catch (\Throwable) {
            return null;
        }
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['code', 'name_en', 'name_ar', 'ledger_account_id', 'cost_nature', 'is_active', 'sort_order'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('expense_category');
    }
}
