<?php

namespace App\Models;

use App\Support\PostingRoles;
use DomainException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * ربط الحسابات — maps a semantic posting role (key) to a chart account.
 * Resolved by AccountResolver. A null asset_id row is the global default;
 * a row with asset_id overrides it for that property.
 */
class AccountMapping extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'key',
        'ledger_account_id',
        'asset_id',
    ];

    /**
     * Logged because "who re-pointed rent revenue, and when?" is an audit question with real money
     * behind it — every invoice issued after the change lands somewhere new, and the entries
     * themselves record only the account they used, never the decision that sent them there.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['key', 'ledger_account_id', 'asset_id'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('account_mapping');
    }

    /**
     * Refuse a second mapping for the same role in the same scope.
     *
     * The table's `unique(['key','asset_id'])` covers per-property overrides and CANNOT cover the
     * global defaults, because SQL treats every NULL as distinct — two `('rent_revenue', NULL)` rows
     * are perfectly legal to the database. That was survivable while the only writer was a seeder
     * calling `firstOrCreate()`; it stopped being survivable the moment an operator got a form.
     *
     * A duplicate is worse than a validation error, because nothing breaks: `AccountResolver` orders
     * by id and quietly takes the older row, so the accountant re-points rent revenue, sees their row
     * saved, and every invoice keeps posting to the old account. Refused here rather than in the
     * form, so an import or a console call cannot get round it either.
     */
    protected static function booted(): void
    {
        static::saving(function (self $mapping) {
            $clash = static::query()
                ->where('key', $mapping->key)
                ->when(
                    $mapping->asset_id === null,
                    fn ($q) => $q->whereNull('asset_id'),
                    fn ($q) => $q->where('asset_id', $mapping->asset_id),
                )
                ->when($mapping->exists, fn ($q) => $q->whereKeyNot($mapping->getKey()))
                ->exists();

            if ($clash) {
                throw new DomainException(__('admin.errors.account_mapping_duplicate', [
                    'role' => PostingRoles::label($mapping->key),
                ]));
            }
        });

        // An override is safe to remove — the role falls back to its global default, which is the
        // point of having one. A global default has nothing behind it: remove it and every posting
        // that asks for the role starts throwing "No account mapping for role …", which for a role
        // like `accounts_receivable` is every invoice in the system. Re-point it instead.
        //
        // A key no longer in `PostingRoles` is legacy data nothing resolves, so clearing it out is
        // exactly what an operator should be able to do.
        static::deleting(function (self $mapping) {
            if ($mapping->asset_id === null && PostingRoles::group((string) $mapping->key) !== null) {
                throw new DomainException(__('admin.errors.account_mapping_global_undeletable', [
                    'role' => PostingRoles::label($mapping->key),
                ]));
            }
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
