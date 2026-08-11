<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * طرف القيد — one debit (مدين) or credit (دائن) line of a journal entry.
 * Exactly one of debit/credit is > 0. Carries optional analytical dimensions.
 */
class JournalLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'journal_entry_id',
        'ledger_account_id',
        'debit',
        'credit',
        'description',
        'asset_id',
        'tenant_id',
        'lease_id',
    ];

    protected $casts = [
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        // debit/credit are NOT-NULL with a default of 0; a cleared form field can
        // send '' / null. Coerce to 0 so a blank line never violates the constraint
        // (the meter_readings.cost / leases.has_percentage_rent bug class).
        static::saving(function (self $line) {
            foreach (['debit', 'credit'] as $column) {
                $value = $line->getAttribute($column);
                if ($value === null || $value === '') {
                    $line->setAttribute($column, 0);
                }
            }
        });
    }

    /**
     * The bank-reconciliation match, if this posting has been explained by a statement line.
     * At most one — matching a book posting twice would report the same money verified twice.
     */
    public function bankMatch(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(BankMatch::class, 'journal_line_id');
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }
}
