<?php

namespace App\Models;

use App\Models\Concerns\HasSearchText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A bank account the operator actually holds — slice 1 of bank reconciliation.
 *
 * `bank`/`cash` are posting ROLES; this is the account itself. Nothing posts through it yet: the
 * ledger still resolves the role exactly as before, so this register changes no balance and no
 * entry. It exists because a reconciliation is always OF one account, and the roles cannot name one
 * once a property banks in two places.
 *
 * @see docs/accounting/BANK-RECONCILIATION-PLAN.md
 */
class BankAccount extends Model
{
    use HasFactory, HasSearchText, LogsActivity, SoftDeletes;

    protected $fillable = [
        'asset_id',
        'name',
        'bank_name',
        'account_number',
        'iban',
        'currency',
        'ledger_account_id',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Found by what an operator would type: the account's own name, its bank, and the number a
     * statement quotes. Own attributes only — never reached through a relation (the blob is a pure
     * function of this row, or renaming a ledger account would strand every blob quoting it).
     *
     * @return array<int, string|int|float|null>
     */
    public function searchTextSources(): array
    {
        return [
            $this->name,
            $this->bank_name,
            $this->account_number,
            $this->iban,
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'bank_name', 'account_number', 'iban', 'ledger_account_id', 'is_active', 'asset_id'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('bank_account');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /** The GL account this bank is, when the accountant has said which. */
    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** "CIB — current ···4821", which is how an operator recognises it without exposing the number. */
    public function displayName(): string
    {
        $masked = $this->maskedNumber();

        return trim($this->name.($masked ? ' '.$masked : ''));
    }

    /** Last four only. The whole number is stored (a statement quotes it) but rarely worth showing. */
    public function maskedNumber(): ?string
    {
        $number = preg_replace('/\s+/', '', (string) $this->account_number);

        return $number === '' || $number === null ? null : '···'.mb_substr($number, -4);
    }
}
