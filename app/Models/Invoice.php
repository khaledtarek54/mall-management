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
        'credit_applied_amount',
        'balance',
        'currency',
        'eta_submission_id',
        'eta_submitted_at',
        'eta_response',
        'eta_status',
        'eta_long_id',
        'notes',
        'owner_overdue_notified_at',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'due_date' => 'date',
        'period_start' => 'date',
        'period_end' => 'date',
        'eta_submitted_at' => 'datetime',
        'owner_overdue_notified_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'credit_applied_amount' => 'decimal:2',
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

    // ============ Online payment link ============

    /**
     * Stable, unguessable token behind the public pay link. Lazily generated +
     * persisted on first access, so existing invoices get one on demand.
     */
    public function paymentLinkToken(): string
    {
        if (blank($this->payment_link_token)) {
            $this->forceFill(['payment_link_token' => \Illuminate\Support\Str::random(48)])->save();
        }

        return $this->payment_link_token;
    }

    /** Public, no-login URL a client can open to pay this invoice. */
    public function paymentLinkUrl(): string
    {
        return route('pay.show', ['token' => $this->paymentLinkToken()]);
    }

    /** Inline SVG QR code of the pay link, for scan-to-pay (no GD/imagick needed). */
    public function paymentLinkQrSvg(int $size = 170): string
    {
        $renderer = new \BaconQrCode\Renderer\ImageRenderer(
            new \BaconQrCode\Renderer\RendererStyle\RendererStyle($size, 2),
            new \BaconQrCode\Renderer\Image\SvgImageBackEnd(),
        );

        $svg = (new \BaconQrCode\Writer($renderer))->writeString($this->paymentLinkUrl());

        // Strip the XML prolog so the SVG embeds cleanly inside HTML.
        return (string) preg_replace('/^<\?xml.*?\?>\s*/s', '', $svg);
    }

    /** Whether there is still a balance that can be collected online. */
    public function isPayable(): bool
    {
        return ! in_array($this->status, ['cancelled', 'credited'], true)
            && round((float) $this->balance, 2) > 0;
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
            // Pre-generate the public pay-link token so the API/admin/portal never
            // write during a read. Existing invoices get one lazily (paymentLinkToken).
            if (blank($invoice->payment_link_token)) {
                $invoice->payment_link_token = \Illuminate\Support\Str::random(48);
            }
        });

        // Finalized (issued+) invoice immutability guard (GL integrity — Phase 1).
        // A draft is freely editable; once issued the invoice is a live AR/GL document:
        //   1. it cannot be reverted to draft (that would re-open the form-locked fields);
        //   2. its GL-identity fields (issue_date = period, tenant/lease = AR dimension)
        //      are immutable — no system path rewrites them (LateFeeService/CAM touch only
        //      subtotal/total/items, which stay writable, and via saveQuietly so they skip
        //      this event anyway).
        // Defense-in-depth behind the form lock — closes the JS-tamper / API / tinker path.
        static::updating(function (self $invoice) {
            if ($invoice->getOriginal('status') === 'draft') {
                return; // draft is freely editable (and draft→issued must be allowed)
            }
            if ($invoice->status === 'draft') {
                throw new \DomainException('An issued invoice cannot be returned to draft — void it or issue a credit note instead.');
            }
            foreach (['issue_date', 'tenant_id', 'lease_id'] as $field) {
                if ($invoice->isDirty($field)) {
                    throw new \DomainException("A finalized invoice's {$field} is immutable — void and re-issue instead.");
                }
            }
        });

        // Cancelling/un-cancelling an invoice changes whether its marketing levy
        // counts toward the fund (recomputeAccrued excludes cancelled). The item
        // hook doesn't fire on a status-only change, so re-derive here.
        static::updated(function (self $invoice) {
            if (! $invoice->wasChanged('status')) {
                return;
            }

            // CANCELLING an invoice that consumed credit would lose that credit
            // against a row that leaves the books — return it to the tenant as an
            // offsetting credit note. NOT 'credited': that is the terminal
            // paid-BY-credit-note state (it STAYS on the books, revenue recognised),
            // so its credit is the intended settlement and must stay consumed —
            // reversing it there would double-refund + drive net AR negative.
            // Read the PERSISTED credit_applied_amount (the in-memory instance may
            // be stale — credit was applied to a separately-locked copy) so the
            // reversal can't be silently skipped. (saveQuietly inside → no recursion.)
            if ($invoice->status === 'cancelled') {
                $appliedCredit = (float) static::whereKey($invoice->id)->value('credit_applied_amount');
                if ($appliedCredit > 0) {
                    app(\App\Services\CreditNoteService::class)->reverseAppliedCredit($invoice->fresh());
                }
            }

            if ($invoice->status !== 'cancelled' && $invoice->getOriginal('status') !== 'cancelled') {
                return; // neither old nor new status is cancelled — accrual unaffected
            }
            $assetId = $invoice->lease?->unit?->asset_id;
            $year = optional($invoice->issue_date)->year;
            if ($assetId && $year && $invoice->items()->where('type', 'marketing')->exists()) {
                MarketingBudget::forPeriod($assetId, (int) $year)->recomputeAccrued();
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

        // Applied credit notes settle AR too (they bump credit_applied_amount,
        // not the payments pivot) — include them so a later payment recompute
        // doesn't erase the credit.
        $paid += (float) $this->credit_applied_amount;

        $this->paid_amount = round($paid, 2);
        $this->balance = round(max(0, (float) $this->total - $this->paid_amount), 2);

        // A cancelled invoice claims no AR — force balance to 0 (it left the books).
        // Also prevents a phantom 'total' balance after a cancel-reversal zeroes the
        // applied credit (paid→0 would otherwise re-derive balance = total).
        if ($this->status === 'cancelled') {
            $this->balance = 0;
        }

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
