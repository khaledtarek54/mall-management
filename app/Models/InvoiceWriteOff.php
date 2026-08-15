<?php

namespace App\Models;

use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PropertyOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * إعدام دين — accepting that a receivable will not be paid.
 *
 * Its own accounting document, posted `Dr Bad Debt Expense / Cr Accounts Receivable` and dated at
 * the write-off DECISION (see `InvoiceWriteOffJournalizer`). Revenue stays recognised in the
 * period it was earned — which is the whole point, and what cancelling the invoice destroys.
 *
 * Reversal = soft-delete (the debt was recovered after all); the ledger sweep then voids the
 * entry. Created only by `WriteOffInvoiceService`; never edited — a wrong write-off is reversed
 * and re-made, so the trail shows both decisions.
 */
#[DeletionAllowed(reason: 'parent-managed: soft-deleted to reverse a bad-debt write-off (WriteOffInvoiceService::reverse), which voids the GL entry and re-opens the invoice. NEVER_DELETABLE would have broken that recovery path — the exact trap CLAUDE.md warns about before adding a model to NEVER')]
#[PropertyOwned(via: 'asset')]
class InvoiceWriteOff extends Model
{
    use HasFactory, SoftDeletes;

    /** Why a debt was accepted as uncollectible. Strings, not a DB enum (project convention). */
    public const REASONS = ['tenant_insolvent', 'tenant_absconded', 'legally_unrecoverable', 'uneconomic_to_pursue', 'settled_short', 'other'];

    protected $fillable = [
        'invoice_id', 'tenant_id', 'asset_id', 'amount', 'entry_date', 'reason', 'notes', 'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'entry_date' => 'date',
    ];

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
