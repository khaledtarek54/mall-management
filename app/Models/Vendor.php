<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Vendor extends Model
{
    use LogsActivity, SoftDeletes;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'type', 'status', 'email', 'phone', 'tax_id'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('vendor');
    }

    protected $fillable = [
        'name',
        'slug',
        'type',
        'status',
        'legal_name',
        'tax_id',
        'email',
        'phone',
        'address',
        'city',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function contacts(): HasMany
    {
        return $this->hasMany(VendorContact::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(VendorContract::class);
    }

    public function maintenanceRequests(): HasMany
    {
        return $this->hasMany(TenantRequest::class, 'assigned_to_vendor_id');
    }

    public function primaryContact(): ?VendorContact
    {
        return $this->contacts()->where('is_primary', true)->first()
            ?? $this->contacts()->oldest()->first();
    }

    public function activeContractsCount(): int
    {
        return $this->contracts()->where('status', 'active')->count();
    }

    protected static function booted(): void
    {
        static::creating(function (self $vendor) {
            if (empty($vendor->slug)) {
                $base = Str::slug($vendor->name ?? 'vendor');
                $slug = $base;
                $suffix = 1;
                while (static::withTrashed()->where('slug', $slug)->exists()) {
                    $slug = $base . '-' . (++$suffix);
                }
                $vendor->slug = $slug;
            }
        });
    }
}
