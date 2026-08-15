<?php

namespace App\Models;

use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PortfolioShared;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[DeletionAllowed(reason: 'operational: a contact person')]
// belongs to the shared Vendor
#[PortfolioShared]
class VendorContact extends Model
{
    protected $fillable = [
        'vendor_id',
        'name',
        'role',
        'email',
        'phone',
        'is_primary',
        'notes',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
