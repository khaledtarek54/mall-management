<?php

namespace App\Models;

use App\Support\Attributes\DeletionAllowed;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One application of a credit note against one invoice — the link that makes an application
 * reversible (see the migration). Created only by CreditNoteService::applyToInvoice(); un-applied
 * (soft-deleted) by reverseApplication() / the invoice-cancel un-apply. Not a GL source.
 */
#[DeletionAllowed(reason: 'parent-managed: deleted to UN-APPLY a credit note')]
class CreditNoteApplication extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'credit_note_id',
        'invoice_id',
        'amount',
        'applied_at',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'applied_at' => 'datetime',
    ];

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
