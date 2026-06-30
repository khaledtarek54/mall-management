<?php

namespace App\Models;

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
class LedgerAccount extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    public const TYPES = ['asset', 'liability', 'equity', 'revenue', 'expense'];

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

    /** The debit/credit side an account of this nature increases on. */
    public static function normalBalanceFor(string $type): string
    {
        return in_array($type, ['asset', 'expense'], true) ? 'debit' : 'credit';
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
        });
    }
}
