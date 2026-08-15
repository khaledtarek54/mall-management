<?php

namespace App\Models;

use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PropertyOwned;
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
#[DeletionAllowed(reason: 'parent-managed: append-only in practice, removable while unsent')]
// A change order reaches its property through the contract it varies. No Filament
// resource of its own — recorded via the "Add change order" action on the vendor's
// contracts list, which is already property-scoped.
#[PropertyOwned(via: 'contract')]
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
