<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Netting a security deposit against one of the tenant's invoices — its own accounting document,
 * posted Dr Deposits Held / Cr Accounts Receivable dated at application time (see the migration and
 * DepositApplicationJournalizer).
 *
 * **Reversal = soft-delete**, exactly as `TenantCreditApplication` works: the AR re-opens and the
 * deposit balance returns on the next recompute, and `LedgerPoster::sync` voids the entry. Created
 * only by `ApplyDepositToInvoiceService`; there is no Filament resource, because netting a deposit
 * is part of settling a move-out rather than a thing to do on its own.
 */
class DepositApplication extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'lease_id',
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

    /** The column this document's GL entry is dated from (LedgerRealtimeSync::SOURCE_DATE_COLUMNS). */
    public static function postingDateColumn(): string
    {
        return 'entry_date';
    }

    /** @return BelongsTo<Lease, $this> */
    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
