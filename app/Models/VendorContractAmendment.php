<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A change order against a vendor contract (module 12b).
 *
 * Without these, `VendorContract::isOverCommitted()` could not distinguish an APPROVED uplift from
 * an uncontrolled over-run — both simply showed red, which trains the operator to ignore the flag.
 * A signed `value_delta` moves the commitment with a dated, attributed, stated reason behind it.
 */
class VendorContractAmendment extends Model
{
    use LogsActivity;

    protected $fillable = [
        'vendor_contract_id',
        'reference',
        'value_delta',
        'effective_on',
        'reason',
        'created_by_user_id',
    ];

    protected $casts = [
        'value_delta' => 'decimal:2',
        'effective_on' => 'date',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['reference', 'value_delta', 'effective_on', 'reason'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('vendor_contract_amendment');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(VendorContract::class, 'vendor_contract_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
