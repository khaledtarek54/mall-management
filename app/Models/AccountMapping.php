<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ربط الحسابات — maps a semantic posting role (key) to a chart account.
 * Resolved by AccountResolver. A null asset_id row is the global default;
 * a row with asset_id overrides it for that property.
 */
class AccountMapping extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'ledger_account_id',
        'asset_id',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
