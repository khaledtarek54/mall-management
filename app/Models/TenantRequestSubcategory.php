<?php

namespace App\Models;

use App\Enums\TenantRequestType;
use App\Models\Concerns\RefusesDeletionWhenReferenced;
use App\Support\Attributes\DeletableWhenUnused;
use App\Support\Attributes\PortfolioShared;
use App\Support\ValueSets;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * تصنيف فرعي للطلب — what a tenant may actually report, under each kind of request.
 *
 * ## Why this is a row, and why the TYPE is not
 *
 * `TenantRequestType::subcategories()` was a `match()` returning seven maintenance values while
 * `trades` seeds fourteen, and `RaiseCorrectiveWorkOrderService::tradeForRequest()` bridged them by
 * comparing `tenant_requests.category` against `trades.code` — a string match between two lists
 * nothing kept in step. So a tenant could not report a stuck lift, a generator failure, a
 * fire-safety fault, a pest problem, a security issue, a landscaping fault or a waste problem as
 * such. They picked "other", and the work order was raised with NO trade: invisible to every
 * by-trade report, and to vendor eligibility.
 *
 * The fix is the foreign key, not the row. {@see trade()} means the two registers cannot drift
 * again, and a naming mismatch stops mattering — `fire_safety` here can point at the `fire-safety`
 * trade, which the old string match would have resolved to nothing.
 *
 * The **type** stays a PHP enum. It carries behaviour (`requiresDecision()`, `allowsScheduling()`,
 * `referencePrefix()`, `defaultDepartmentSlug()`), and CLAUDE.md's rule is that an enum is the
 * better shape where one exists. Rows would let an operator create a type the code has no answers
 * for. Only the vocabulary moves.
 *
 * ## A subcategory is not always a trade
 *
 * `trade_id` is NULL for everything that is not a maintenance fault. A noise complaint, a lease copy
 * and a parking pass are problems, not crafts — copying the category across as a trade is exactly
 * what put `noise`, `parking` and `lease_copy` in the trade column for the whole of module 26's life.
 */
#[DeletableWhenUnused(
    blockedBy: ['requests'],
    instead: 'Deactivate it. A subcategory that classified a reported fault stays in the register, because every request and every by-category report reads its label.',
)]
// Shared: what a tenant may report is one vocabulary across the portfolio, not a per-mall opinion.
#[PortfolioShared]
class TenantRequestSubcategory extends Model
{
    use LogsActivity;
    use RefusesDeletionWhenReferenced;

    private const MEMO = 'tenant_request_subcategory.map';

    protected $fillable = [
        'request_type',
        'code',
        'name_en',
        'name_ar',
        'trade_id',
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
        static::saving(fn (self $row) => $row->sort_order ??= 0);

        $flush = function (): void {
            foreach (['', '.labels', '.trades'] as $suffix) {
                app()->forgetInstance(self::MEMO.$suffix);
            }

            ValueSets::flushCatalogueCache();
        };

        static::saved($flush);
        static::deleted($flush);
    }

    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }

    /** Requests filed under this subcategory — what makes it undeletable once used. */
    public function requests()
    {
        return $this->hasMany(TenantRequest::class, 'category', 'code')
            ->where('request_type', $this->request_type);
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

    public function scopeOfType(Builder $query, TenantRequestType|string $type): Builder
    {
        return $query->where('request_type', $type instanceof TenantRequestType ? $type->value : $type);
    }

    /**
     * Every active code, across all types — the union {@see ValueSets} widens
     * `tenant_requests.category` from.
     *
     * Flat and type-blind on purpose: the value set answers "may this column hold this string",
     * and `other` is a legitimate subcategory of four different types. Which subcategories a given
     * type OFFERS is {@see optionsFor()}, and that is the narrower question a form asks.
     *
     * @return array<int, string>
     */
    public static function codes(): array
    {
        if (app()->has(self::MEMO)) {
            return app(self::MEMO);
        }

        try {
            $codes = static::query()->where('is_active', true)->pluck('code')->unique()->values()->all();
        } catch (\Throwable) {
            return [];
        }

        app()->instance(self::MEMO, $codes);

        return $codes;
    }

    /**
     * `code => label` for one type's picker, the catalogue first and the enum as the floor.
     *
     * Active rows only — retiring a subcategory stops it being offered, and {@see labelFor()} still
     * labels the requests that already carry it.
     *
     * @return array<string, string>
     */
    public static function optionsFor(TenantRequestType $type): array
    {
        try {
            $rows = static::query()
                ->where('request_type', $type->value)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get()
                ->mapWithKeys(fn (self $row) => [$row->code => $row->label()])
                ->all();

            if ($rows !== []) {
                return $rows;
            }
        } catch (\Throwable) {
            // Before the table exists.
        }

        return collect($type->subcategories())
            ->mapWithKeys(fn (string $code) => [$code => __("admin.enums.tenant_request_subcategory.{$code}")])
            ->all();
    }

    /** The label for one stored code — includes inactive rows, so history keeps its words. */
    public static function labelFor(?string $code, ?TenantRequestType $type = null): string
    {
        if ($code === null || $code === '') {
            return '—';
        }

        $labels = app()->has(self::MEMO.'.labels')
            ? app(self::MEMO.'.labels')
            : tap(static::safeLabels(), fn (array $m) => app()->instance(self::MEMO.'.labels', $m));

        $key = $type !== null ? "{$type->value}.{$code}" : null;

        return $labels[$key] ?? $labels[$code] ?? (function () use ($code) {
            $langKey = "admin.enums.tenant_request_subcategory.{$code}";
            $translated = __($langKey);

            return $translated === $langKey ? $code : $translated;
        })();
    }

    /** @return array<string, string> */
    private static function safeLabels(): array
    {
        try {
            $labels = [];

            foreach (static::query()->get() as $row) {
                $labels["{$row->request_type}.{$row->code}"] = $row->label();
                $labels[$row->code] ??= $row->label();
            }

            return $labels;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * The trade a reported problem belongs to — the LINK, never a string match.
     *
     * Falls back to matching the code against `trades.code`, which is what
     * `RaiseCorrectiveWorkOrderService` did on its own: an unseeded register then behaves exactly as
     * before, and a subcategory with no `trade_id` still resolves for the seven codes that happen to
     * share a name with a trade.
     */
    public static function tradeIdFor(?string $code, ?TenantRequestType $type = null): ?int
    {
        if ($code === null || $code === '') {
            return null;
        }

        $map = app()->has(self::MEMO.'.trades')
            ? app(self::MEMO.'.trades')
            : tap(static::safeTrades(), fn (array $m) => app()->instance(self::MEMO.'.trades', $m));

        $key = $type !== null ? "{$type->value}.{$code}" : null;

        if ($key !== null && array_key_exists($key, $map)) {
            return $map[$key];
        }

        if (array_key_exists($code, $map)) {
            return $map[$code];
        }

        try {
            return Trade::query()->where('code', $code)->value('id');
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, int|null> */
    private static function safeTrades(): array
    {
        try {
            $map = [];

            foreach (static::query()->get() as $row) {
                $map["{$row->request_type}.{$row->code}"] = $row->trade_id;
                $map[$row->code] ??= $row->trade_id;
            }

            return $map;
        } catch (\Throwable) {
            return [];
        }
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['request_type', 'code', 'name_en', 'name_ar', 'trade_id', 'is_active', 'sort_order'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('tenant_request_subcategory');
    }
}
