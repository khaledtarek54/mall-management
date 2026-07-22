<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class VendorContract extends Model
{
    use LogsActivity, SoftDeletes;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'status', 'value', 'start_date', 'end_date'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('vendor_contract');
    }

    protected $fillable = [
        'vendor_id',
        'asset_id',
        'reference',
        'name',
        'status',
        'start_date',
        'end_date',
        'value',
        'sla_penalty_basis',
        'sla_penalty_rate',
        'currency',
        'scope',
        'notes',
    ];

    protected $casts = [
        'sla_penalty_rate' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'value' => 'decimal:2',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<VendorBill, $this> */
    public function bills(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(VendorBill::class);
    }

    // ============ Commitment tracking (committed vs actual) ============
    //
    // `value` is what was committed when the contract was signed. Without these, it was a
    // decorative number: nothing compared it to what the vendor has actually invoiced, so a
    // EGP 500k contract could quietly absorb EGP 5m of bills. Cancelled bills don't consume
    // the commitment — they were withdrawn, not incurred.

    /** Total invoiced against this contract to date (gross, excluding cancelled bills). */
    public function billedToDate(): float
    {
        return (float) $this->bills()->where('status', '!=', 'cancelled')->sum('total');
    }

    /** Commitment left before the contract is fully drawn; negative once it is over-run. */
    public function remainingValue(): float
    {
        return round((float) $this->value - $this->billedToDate(), 2);
    }

    /** Has the vendor invoiced more than the contract committed? A flag to investigate, not a block. */
    public function isOverCommitted(): bool
    {
        return (float) $this->value > 0 && $this->remainingValue() < 0;
    }
}
