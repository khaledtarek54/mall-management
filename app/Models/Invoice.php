<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Invoice extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['number', 'status', 'issue_date', 'due_date', 'total', 'paid_amount', 'balance', 'tenant_id', 'lease_id'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('invoice');
    }

    protected $fillable = [
        'number',
        'lease_id',
        'tenant_id',
        'status',
        'issue_date',
        'due_date',
        'period_start',
        'period_end',
        'subtotal',
        'vat_amount',
        'total',
        'paid_amount',
        'balance',
        'currency',
        'eta_submission_id',
        'eta_submitted_at',
        'eta_response',
        'eta_status',
        'eta_long_id',
        'notes',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'due_date' => 'date',
        'period_start' => 'date',
        'period_end' => 'date',
        'eta_submitted_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'balance' => 'decimal:2',
        'eta_response' => 'array',
    ];

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): BelongsToMany
    {
        return $this->belongsToMany(Payment::class)
            ->withPivot('allocated_amount')
            ->withTimestamps();
    }

    // ============ Status helpers ============

    public function isOverdue(): bool
    {
        return $this->status === 'overdue' ||
               (in_array($this->status, ['issued', 'partially_paid']) && $this->due_date->isPast());
    }

    public function daysOverdue(): int
    {
        if (! $this->isOverdue()) {
            return 0;
        }

        return (int) $this->due_date->diffInDays(now());
    }

    public function recalculateBalance(): void
    {
        $this->balance = $this->total - $this->paid_amount;
        if ($this->balance <= 0) {
            $this->status = 'paid';
        } elseif ($this->paid_amount > 0) {
            $this->status = 'partially_paid';
        }
        $this->save();
    }

    public static function generateNumber(string $assetCode = 'AW', ?\DateTimeInterface $issueDate = null): string
    {
        $issueDate = $issueDate ? Carbon::instance($issueDate) : now();
        $prefix = sprintf('INV-%s-%s-', $assetCode, $issueDate->format('Ym'));

        $last = static::withTrashed()
            ->where('number', 'like', $prefix.'%')
            ->orderByDesc('number')
            ->value('number');

        $next = $last
            ? ((int) substr($last, strlen($prefix))) + 1
            : 1;

        return sprintf('%s%04d', $prefix, $next);
    }

    protected static function generateUniqueNumber(string $assetCode = 'AW', ?\DateTimeInterface $issueDate = null): string
    {
        $candidate = static::generateNumber($assetCode, $issueDate);

        $attempts = 0;
        while (static::withTrashed()->where('number', $candidate)->exists()) {
            $attempts++;
            if ($attempts > 100) {
                throw new \RuntimeException('Unable to allocate a unique invoice number after 100 attempts.');
            }
            $issue = $issueDate ? Carbon::instance($issueDate) : now();
            $prefix = sprintf('INV-%s-%s-', $assetCode, $issue->format('Ym'));
            $n = ((int) substr($candidate, strlen($prefix))) + 1;
            $candidate = sprintf('%s%04d', $prefix, $n);
        }

        return $candidate;
    }

    protected static function booted(): void
    {
        static::creating(function (self $invoice) {
            // Always (re)generate at save time so we never persist a stale
            // form-cached number that could collide with another record. The
            // prefix is the property's code (INV-AW-…), derived from the linked
            // lease's unit; falls back to AW when no lease is attached.
            $assetCode = $invoice->lease?->unit?->asset?->code ?: 'AW';
            $invoice->number = static::generateUniqueNumber($assetCode, $invoice->issue_date);

            if (empty($invoice->currency)) {
                $invoice->currency = 'EGP';
            }
            if ($invoice->balance === null) {
                $invoice->balance = (float) ($invoice->total ?? 0) - (float) ($invoice->paid_amount ?? 0);
            }
        });
    }

    /**
     * Recompute paid_amount / balance / status from the allocated payments pivot.
     * This is the single source of truth for AR balances.
     */
    public function recomputeTotals(): void
    {
        $paid = (float) $this->payments()
            ->where('payments.status', 'captured')
            ->sum('invoice_payment.allocated_amount');

        $this->paid_amount = round($paid, 2);
        $this->balance = round(max(0, (float) $this->total - $this->paid_amount), 2);

        // Auto-status: don't override manual overrides like 'cancelled' / 'credited' / 'disputed'.
        if (! in_array($this->status, ['cancelled', 'credited', 'disputed'])) {
            if ($this->balance <= 0 && $this->paid_amount > 0) {
                $this->status = 'paid';
            } elseif ($this->paid_amount > 0) {
                $this->status = 'partially_paid';
            } elseif ($this->due_date && Carbon::parse($this->due_date)->isPast()) {
                $this->status = 'overdue';
            } else {
                $this->status = 'issued';
            }
        }

        $this->saveQuietly();
    }
}
