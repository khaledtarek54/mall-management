<?php

namespace App\Models;

use App\Models\Concerns\HasSearchText;
use App\Models\Concerns\RefusesDeletionWhenReferenced;
use App\Support\Attributes\DeletableWhenUnused;
use App\Support\Attributes\PortfolioShared;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * دليل الحسابات — a single account in the chart of accounts.
 *
 * Accounts form a tree via `parent_id`. Only `is_postable` leaves accept journal
 * lines; parents are summary/rollup accounts. `normal_balance` is derived from
 * `type` (asset/expense → debit, liability/equity/revenue → credit).
 */
#[DeletableWhenUnused(blockedBy: ['lines', 'children', 'accountMappings'], instead: 'deactivate the account — removing one that has been posted to breaks every prior statement')]
// one shared chart of accounts; property is a dimension on entries
#[PortfolioShared]
class LedgerAccount extends Model
{
    use RefusesDeletionWhenReferenced, HasFactory, HasSearchText, LogsActivity, SoftDeletes;

    public const TYPES = ['asset', 'liability', 'equity', 'revenue', 'expense'];

    /**
     * Egyptian chart convention — the leading code digit fixes the account nature:
     * 1 assets · 2 liabilities · 3 equity · 4 revenue · 5 expenses. Codes that start
     * with any other digit (custom ranges) are unconstrained.
     */
    public const TYPE_BY_LEADING_DIGIT = [
        '1' => 'asset',
        '2' => 'liability',
        '3' => 'equity',
        '4' => 'revenue',
        '5' => 'expense',
    ];

    protected $fillable = [
        'code',
        'parent_id',
        'name_en',
        'name_ar',
        'type',
        'normal_balance',
        'is_postable',
        'is_active',
        'description',
    ];

    protected $casts = [
        'is_postable' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * Account code and both names. The code is what an accountant types.
     *
     * @return array<int, string|int|float|null>
     */
    public function searchTextSources(): array
    {
        return [
            $this->code,
            $this->name_en,
            $this->name_ar,
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['code', 'name_en', 'name_ar', 'type', 'is_postable', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('ledger_account');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    /**
     * The source→account mappings that post to this account. The FK is restrictOnDelete, so a
     * mapped-but-unposted account (deletable under the lines/children blockers alone) would fail
     * on a database constraint; blocking here turns that into the friendly "deactivate instead".
     *
     * @return HasMany<AccountMapping, $this>
     */
    public function accountMappings(): HasMany
    {
        return $this->hasMany(AccountMapping::class);
    }

    /** The debit/credit side an account of this nature increases on. */
    public static function normalBalanceFor(string $type): string
    {
        return in_array($type, ['asset', 'expense'], true) ? 'debit' : 'credit';
    }

    /** The type a code's leading digit implies, or null for an unconstrained range. */
    public static function expectedTypeForCode(string $code): ?string
    {
        return self::TYPE_BY_LEADING_DIGIT[substr($code, 0, 1)] ?? null;
    }

    /**
     * The parent id implied by the code: the deepest EXISTING account whose code is a
     * strict prefix of this one (mirrors the seeder). Keeps the tree consistent with
     * the code so it can never be mis-parented by hand. Null for a top-level code.
     */
    protected static function resolveParentIdFromCode(self $account): ?int
    {
        $code = (string) $account->code;
        $prefixes = [];
        for ($len = 1; $len < strlen($code); $len++) {
            $prefixes[] = substr($code, 0, $len);
        }
        if ($prefixes === []) {
            return null;
        }

        // Prefixes are strictly shorter than $code, and codes are unique, so this can
        // never match the account itself.
        return static::query()
            ->whereIn('code', $prefixes)
            ->orderByRaw('LENGTH(code) DESC')
            ->value('id');
    }

    /** Locale-aware display name (Arabic for the accountant, English otherwise). */
    public function displayName(): string
    {
        return app()->getLocale() === 'ar' ? $this->name_ar : $this->name_en;
    }

    /**
     * Shared option list (id => "code — name") of postable accounts for Filament
     * selects and report pickers. Locale-aware. One place so the label format
     * never drifts. $activeOnly is true where you POST (only active accounts) and
     * false where you VIEW history (a deactivated account still has past lines).
     *
     * @return array<int, string>
     */
    public static function postableOptions(bool $activeOnly = true): array
    {
        return static::query()
            ->postable()
            ->when($activeOnly, fn (Builder $q) => $q->active())
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (self $a) => [$a->id => $a->code.' — '.$a->displayName()])
            ->all();
    }

    public function scopePostable(Builder $query): Builder
    {
        return $query->where('is_postable', true);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    protected static function booted(): void
    {
        static::saving(function (self $account) {
            // Keep normal_balance in lockstep with type — it is never set by hand.
            $account->normal_balance = static::normalBalanceFor($account->type);

            // Guard the coding convention: a code in a DEFINED range (leading digit
            // 1-5) must carry the matching type — catches the misclassification class
            // (e.g. a revenue account coded 5xxxx). Custom ranges are unconstrained.
            $expected = static::expectedTypeForCode((string) $account->code);
            if ($expected !== null && $account->type !== $expected) {
                throw new \InvalidArgumentException(
                    "Ledger account code {$account->code} starts with a {$expected} range digit but is typed '{$account->type}'."
                );
            }

            // Derive the parent from the code so the tree can't drift from it.
            $account->parent_id = static::resolveParentIdFromCode($account);
        });
    }
}
