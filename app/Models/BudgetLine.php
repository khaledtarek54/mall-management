<?php

namespace App\Models;

use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PropertyOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One account's budgeted amount for one month, in one property.
 *
 * **Deletable, unlike almost everything else in this schema — and deliberately.** A budget is a
 * PLAN, not a transaction: nothing posts from it, no report derives a balance from it, and removing
 * a line changes no result that was ever reported to anybody. The deletion policy refuses money
 * records because the damage lands on the audit trail that referenced them; a budget line has no
 * such trail behind it. Re-importing simply replaces the plan, which is what revising a budget is.
 */
#[DeletionAllowed(reason: 'a budget is a plan, not a transaction — nothing posts from it and no reported figure derives from it, so revising it destroys no evidence')]
#[PropertyOwned]
class BudgetLine extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'asset_id',
        'ledger_account_id',
        'fiscal_year',
        'month',
        'amount',
    ];

    protected $casts = [
        'fiscal_year' => 'integer',
        'month' => 'integer',
        'amount' => 'decimal:2',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }
}
