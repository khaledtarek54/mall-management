<?php

namespace App\Models;

use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PostingDateGuardedBy;
use App\Support\Attributes\PropertyOwned;
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
#[DeletionAllowed(reason: 'parent-managed: soft-deleted to reverse a deposit netted against an invoice (ApplyDepositToInvoiceService::reverse), which re-opens the AR and returns the deposit balance')]
// netting a deposit against an invoice; asset = the invoice's property; service-created, no Filament resource
#[PropertyOwned]
// CORRECTED 2026-08-11. This said `system:` — "entry_date is stamped at application time
// and is not operator-typable" — and it was factually false. `ApplyDepositToInvoiceService`
// stamps `$on`, a PARAMETER, and `SettleMoveOutService` passes the operator's
// `settlement_date` straight off an unconstrained DatePicker on the Lease resource.
//
// A `system:` exemption asserting a safety property that does not hold is worse than no
// entry at all: the gate reports coverage. The gate could not catch this either, because it
// checks the registry's own declarations, and the offending field lives on a different
// resource under a different name. Back-dating a settlement into a closed March netted
// 120,000 of arrears off the deposit, closed the AR, showed "Saved ✓" — and the post was
// refused inside the best-effort sync job, leaving a tie-out gap of exactly that much.
//
// Guarded in BOTH services now: the one that stamps the date onto the row, and the one that
// takes it from the operator (so a refusal arrives before the first side effect rather than
// half way through a final account).
#[PostingDateGuardedBy(guard: \App\Services\ApplyDepositToInvoiceService::class)]
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
