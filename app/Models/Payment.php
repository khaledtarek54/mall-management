<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Payment extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['reference', 'tenant_id', 'amount', 'method', 'status', 'payment_date'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('payment');
    }

    protected $fillable = [
        'reference',
        'tenant_id',
        'amount',
        'currency',
        'method',
        'status',
        'payment_date',
        'gateway',
        'gateway_transaction_id',
        'gateway_response',
        'cheque_number',
        'cheque_clearance_date',
        'notes',
        'received_by',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'cheque_clearance_date' => 'date',
        'amount' => 'decimal:2',
        'gateway_response' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function invoices(): BelongsToMany
    {
        return $this->belongsToMany(Invoice::class)
                    ->withPivot('allocated_amount')
                    ->withTimestamps();
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'received_by');
    }

    public static function generateReference(): string
    {
        $yearMonth = now()->format('Ym');
        $prefix = "PAY-{$yearMonth}-";

        $last = static::withTrashed()
            ->where('reference', 'like', $prefix.'%')
            ->orderByDesc('reference')
            ->value('reference');

        $next = $last
            ? ((int) substr($last, strlen($prefix))) + 1
            : 1;

        return sprintf('%s%04d', $prefix, $next);
    }

    /**
     * Recompute every invoice this payment is allocated to.
     * Call this after attaching, detaching, syncing pivot rows, or changing the payment status.
     */
    public function recomputeAllocatedInvoices(): void
    {
        $this->invoices()->get()->each->recomputeTotals();
    }

    /**
     * Throw if any of the given invoice IDs belongs to a tenant different
     * from this payment. Used by the admin Create/Edit pages before they
     * sync the invoice_payment pivot — the form's tenant filter already
     * prevents cross-tenant picks in normal use, but a stale repeater row
     * or an API client could bypass that, so we guard at the model layer
     * too (audit M06 F-26 / D-19).
     *
     * @param  array<int>  $invoiceIds
     *
     * @throws \DomainException
     */
    public function assertInvoicesShareTenant(array $invoiceIds): void
    {
        if (empty($invoiceIds)) {
            return;
        }

        $offending = Invoice::whereIn('id', $invoiceIds)
            ->where('tenant_id', '!=', $this->tenant_id)
            ->first();

        if ($offending) {
            throw new \DomainException(
                __('admin.payment.cross_tenant_allocation', ['invoice' => $offending->number])
            );
        }
    }

    protected static function booted(): void
    {
        static::creating(function (self $payment) {
            // Always (re)generate at save time to avoid stale references cached
            // in form state by the time the request reaches the DB.
            $payment->reference = static::generateUniqueReference();

            if (empty($payment->currency)) {
                $payment->currency = 'EGP';
            }
        });

        static::saved(function (self $payment) {
            // Status change (e.g. captured ↔ failed) must roll forward to invoices.
            $payment->recomputeAllocatedInvoices();
        });

        static::deleted(function (self $payment) {
            $payment->recomputeAllocatedInvoices();
        });
    }

    /**
     * Generate a reference that is guaranteed unique at the moment of save.
     * Falls back to incrementing if a race created a sibling record between
     * lookup and insert.
     */
    protected static function generateUniqueReference(): string
    {
        $candidate = static::generateReference();

        $attempts = 0;
        while (static::withTrashed()->where('reference', $candidate)->exists()) {
            $attempts++;
            if ($attempts > 100) {
                throw new \RuntimeException('Unable to allocate a unique payment reference after 100 attempts.');
            }
            $yearMonth = now()->format('Ym');
            $prefix = "PAY-{$yearMonth}-";
            $n = ((int) substr($candidate, strlen($prefix))) + 1;
            $candidate = sprintf('%s%04d', $prefix, $n);
        }

        return $candidate;
    }
}
