<?php

namespace App\Models;

use App\Models\Concerns\HasSearchText;
use App\Models\Concerns\RefusesDeletionWhenReferenced;
use App\Support\ActivityLogging;
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
    use HasFactory, HasSearchText, LogsActivity, RefusesDeletionWhenReferenced, SoftDeletes;

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
        'cash_flow_section',
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
        return ActivityLogging::for($this, 'ledger_account');
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
    /**
     * The account's name in the reader's language, falling back to the other one.
     *
     * The fallback is not politeness. The return type is `string` and `name_ar` is nullable, so an
     * account imported from an English-only chart raised a TypeError the moment an Arabic session
     * rendered it — on a method called from the picker, the report filters and the posting map. A
     * half-translated chart is the normal state of an import, not an exotic one.
     */
    public function displayName(): string
    {
        return (string) (app()->getLocale() === 'ar'
            ? ($this->name_ar ?: $this->name_en)
            : ($this->name_en ?: $this->name_ar));
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

        // …and the REVERSE direction, which `saving` cannot do (EG-28, the chart importer).
        //
        // `resolveParentIdFromCode()` looks BACKWARD for a parent that already exists, so it is
        // complete only when parents are created before their children — true of the seeder, which
        // sorts by code, and false of an import. Filament streams a CSV in file order and offers no
        // after-import hook, so a chart whose file lists `11101` before `111` left the child
        // parented to null: the rollup silently loses a branch, and nothing on screen says so.
        //
        // Adoption closes it, so the tree is correct whatever order the rows arrive in.
        static::saved(function (self $account) {
            // Only when the tree can actually have changed. Renaming an account or toggling
            // `is_active` cannot orphan anything, and running the adoption query on every save
            // would put an extra write-scan behind every routine edit for no possible effect.
            if ($account->wasRecentlyCreated || $account->wasChanged('code')) {
                static::adoptOrphanedDescendants($account);
            }
        });
    }

    /**
     * Re-parent any account this one should now own — the accounts created before it existed.
     *
     * Claims a descendant only when this account is a CLOSER ancestor than its current parent:
     * either it has none, or its parent's code is a strict prefix of ours. A grandchild already
     * parented to a longer code is left alone, so inserting `111` cannot steal `1110123` from
     * `11101`.
     *
     * Written as a query rather than by saving each child: a model save would re-enter this hook,
     * and on a chart of any size that recursion is the whole import.
     */
    protected static function adoptOrphanedDescendants(self $account): void
    {
        $code = (string) $account->code;

        if ($code === '') {
            return;
        }

        // Resolved to IDS first, then updated by key — and that is not a style choice.
        //
        // MySQL refuses `UPDATE t … WHERE EXISTS (SELECT … FROM t)` outright: error 1093, "you
        // can't specify target table for update in FROM clause". The `orWhereHas` below compiles to
        // exactly that, so the single-statement form threw on every MySQL install while passing on
        // the sqlite the suite runs — `migrate:fresh --seed` and `atriom:install` both died in
        // `ChartOfAccountsSeeder`, which is to say a fresh install was impossible on the production
        // engine and the whole test suite was green. A SELECT carrying the same subquery is fine;
        // only the UPDATE form is forbidden.
        $ids = static::query()
            ->where('code', 'like', $code.'%')
            ->where('code', '!=', $code)
            ->where(fn (Builder $q) => $q
                ->whereNull('parent_id')
                // A parent whose code is SHORTER than ours is further away, so we are the better
                // fit. `whereHas` rather than a join: the parent may be soft-deleted, and this
                // relation already excludes those.
                ->orWhereHas('parent', fn (Builder $p) => $p->whereRaw('LENGTH(code) < ?', [strlen($code)]))
            )
            ->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        // Still a BUILDER update, so no model events fire: a model save would re-enter this hook,
        // and on a real chart that recursion is the whole import.
        static::query()->whereKey($ids)->update(['parent_id' => $account->id]);
    }
}
