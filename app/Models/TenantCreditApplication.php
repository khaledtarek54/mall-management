<?php

namespace App\Models;

use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PropertyOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Moving a tenant's on-account credit onto one of their invoices — its own accounting document,
 * posted Dr Unearned Revenue / Cr Accounts Receivable dated at application time (see the migration
 * and TenantCreditApplicationJournalizer). Reversal = soft-delete. Created only by
 * ApplyTenantCreditService (there is no direct Filament resource).
 */
#[DeletionAllowed(reason: 'parent-managed: soft-deleted to reverse an applied tenant credit')]
// applying on-account credit to an invoice; asset = the invoice's property; service-created, no Filament resource
#[PropertyOwned]
class TenantCreditApplication extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'invoice_id',
        'asset_id',
        'amount',
        'entry_date',
        'created_by',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'entry_date' => 'date',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
