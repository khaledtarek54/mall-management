<?php

namespace App\Models;

use App\Support\DocumentNumbering;
use App\Models\Concerns\HasSearchText;
use App\Models\Concerns\RefusesDeletionOfCommittedRecords;
use App\Models\Concerns\GuardsPostingDate;
use App\Services\CreditNoteService;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Invoice extends Model
{
    use RefusesDeletionOfCommittedRecords, \App\Models\Concerns\AllocatesDocumentNumber;

    use GuardsPostingDate, HasFactory, HasSearchText, LogsActivity, SoftDeletes;

    /**
     * The invoice number, and nothing else. Everything an operator might otherwise search
     * (tenant, unit, lease) belongs to another record and is reached by relation search.
     *
     * @return array<int, string|int|float|null>
     */
    public function searchTextSources(): array
    {
        return [
            $this->number,
        ];
    }

    /**
     * The column this invoice's GL entry is dated from (LedgerRealtimeSync::SOURCE_DATE_COLUMNS).
     *
     * The finalisation guard below already freezes issue_date once an invoice is ISSUED, which
     * covered the obvious hole — but not the one that remained: a DRAFT could be created with a
     * back-dated issue_date and then issued, posting AR into a sealed month.
     */
    public static function postingDateColumn(): string
    {
        return 'issue_date';
    }

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
        'is_opening_balance',
        'late_fee_invoice_id',
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
        'tenant_overdue_notified_at',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'due_date' => 'date',
        'period_start' => 'date',
        'period_end' => 'date',
        'eta_submitted_at' => 'datetime',
        'owner_overdue_notified_at' => 'datetime',
        'tenant_overdue_notified_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'credit_applied_amount' => 'decimal:2',
        'balance' => 'decimal:2',
        'is_opening_balance' => 'boolean',
        'eta_response' => 'array',
    ];

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

    /** @return HasMany<InvoiceItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /** @return HasMany<InvoiceWriteOff, $this> */
    public function writeOffs(): HasMany
    {
        return $this->hasMany(InvoiceWriteOff::class);
    }

    /**
     * The late-fee invoice raised because THIS invoice went unpaid (story MF-08).
     *
     * A late fee used to be a line appended to the overdue invoice, which restated an issued
     * document and — since the entry is dated from `issue_date` — booked April's penalty as January
     * revenue. It is now its own dated invoice, and this is the link that makes charging it
     * idempotent under a concurrent sweep.
     *
     * @return BelongsTo<Invoice, $this>
     */
    public function lateFeeInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'late_fee_invoice_id');
    }

    /**
     * Has a late fee already been charged for this invoice, and does it still stand?
     *
     * A CANCELLED fee invoice does not count: its GL entry is voided and the tenant owes nothing on
     * it, so the operator may charge again. Same rule as `BillViolationFineService`.
     */
    public function hasLiveLateFee(): bool
    {
        $fee = $this->lateFeeInvoice;

        return $fee instanceof self && $fee->status !== 'cancelled';
    }

    /**
     * Bad debt accepted against this invoice so far.
     *
     * **NOT a settlement channel, and deliberately not folded into `paid_amount`.** A write-off is
     * not money arriving; `recomputeTotals()` stays the single source of truth for the four
     * channels, and `balance` keeps recording what was owed. But two consumers do need this
     * number: the write-off cap (so repeated partials cannot exceed the debt) and the AR tie-out
     * (so a partial write-off's GL relief has a matching sub-ledger expectation).
     *
     * Reversed write-offs are soft-deleted, so the default scope drops them — a recovered debt
     * correctly stops counting.
     */
    public function writtenOffAmount(): float
    {
        return round((float) $this->writeOffs()->sum('amount'), 2);
    }

    public function payments(): BelongsToMany
    {
        return $this->belongsToMany(Payment::class)
            ->withPivot('allocated_amount')
            ->withTimestamps();
    }

    /**
     * Only the payments whose money is actually on the books.
     *
     * The same `RECEIVED_STATUSES` filter `recomputeTotals()` applies — anything that offers to
     * settle an invoice LINE (story MF-06) must not offer a payment this invoice does not count.
     */
    public function receivedPayments(): BelongsToMany
    {
        return $this->payments()->whereIn('payments.status', Payment::RECEIVED_STATUSES);
    }

    // ============ Online payment link ============

    /**
     * Stable, unguessable token behind the public pay link. Lazily generated +
     * persisted on first access, so existing invoices get one on demand.
     */
    public function paymentLinkToken(): string
    {
        if (blank($this->payment_link_token)) {
            $this->forceFill(['payment_link_token' => Str::random(48)])->save();
        }

        return $this->payment_link_token;
    }

    /**
     * Mint a NEW pay token, killing every URL previously issued for this invoice.
     *
     * The pay link is a bearer credential: whoever holds the URL can read the
     * tenant's name, the line items and the amounts, with no login and no expiry.
     * That is fine while it sits in the addressee's inbox and useless afterwards —
     * except links leak. They get forwarded, land in shared or wrong inboxes, sit
     * in browser history on a shop-floor PC, and survive in screenshots.
     *
     * Without this there is no remedy for that: the operator cannot take the link
     * back. Rotation is the remedy — and the reason it is not an expiry is that an
     * expiry would silently kill legitimate links in already-sent emails, turning
     * every late payer into a support call. Rotation is deliberate and per-invoice.
     *
     * Safe mid-payment: an in-flight Paymob session is keyed by the gateway's
     * order id, not by this token, so rotating never strands a payment that is
     * already at the gateway — the browser return resolves the CURRENT token.
     */
    public function rotatePaymentLinkToken(): string
    {
        // forceFill + save, matching paymentLinkToken(): the column is guarded, and
        // this must persist even on an issued invoice (the immutability guard covers
        // GL-identity fields, not the pay token).
        $this->forceFill(['payment_link_token' => Str::random(48)])->save();

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
        $renderer = new ImageRenderer(
            new RendererStyle($size, 2),
            new SvgImageBackEnd,
        );

        $svg = (new Writer($renderer))->writeString($this->paymentLinkUrl());

        // Strip the XML prolog so the SVG embeds cleanly inside HTML.
        return (string) preg_replace('/^<\?xml.*?\?>\s*/s', '', $svg);
    }

    /** Whether there is still a balance that can be collected online. */
    public function isPayable(): bool
    {
        return ! in_array($this->status, ['cancelled', 'credited', 'written_off'], true)
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

    /**
     * The number prefix for this document's sequence — ONE definition, used by generateNumber()
     * and by the allocation lock key (see AllocatesDocumentNumber). Two copies would drift, and a
     * lock keyed on a prefix that no longer matches the sequence it guards protects nothing.
     */
    public static function numberPrefix(string $assetCode = 'AW', ?\DateTimeInterface $issueDate = null): string
    {
        $issueDate = $issueDate ? Carbon::instance($issueDate) : now();

        return sprintf('%s-%s-%s-', DocumentNumbering::prefixFor('invoice'), $assetCode, $issueDate->format('Ym'));
    }

    public static function generateNumber(string $assetCode = 'AW', ?\DateTimeInterface $issueDate = null): string
    {
        $prefix = static::numberPrefix($assetCode, $issueDate);

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
            $prefix = sprintf('%s-%s-%s-', DocumentNumbering::prefixFor('invoice'), $assetCode, $issue->format('Ym'));
            $n = ((int) substr($candidate, strlen($prefix))) + 1;
            $candidate = sprintf('%s%04d', $prefix, $n);
        }

        return $candidate;
    }

    /**
     * Re-entrancy guard for the auto-apply hook below. Applying a credit SAVES the invoice (its
     * balance drops), which would fire the same hook again.
     */
    protected static bool $applyingCredit = false;

    protected static function booted(): void
    {
        // ── Apply an on-account credit automatically (Voyager behaviour) ───────────────────────
        // Yardi applies open credit to the next charge without being asked. Hooked on the MODEL
        // rather than in the billing service because an invoice is raised from six different paths
        // (monthly run, CAM recovery, percentage-rent overage, violation fine, NSF fee, manual) and
        // a hook per path is the arrangement where one gets forgotten — the same reasoning as the
        // marketing-feed cache bust.
        //
        // The accounting is unchanged: `ApplyTenantCreditService` still posts its own dated
        // Dr Unearned / Cr AR document, still row-locks the tenant, still caps at the lesser of the
        // credit and the balance. Only the trigger is new.
        static::saved(function (self $invoice) {
            if (static::$applyingCredit) {
                return;
            }
            if ($invoice->status !== 'issued' || round((float) $invoice->balance, 2) <= 0) {
                return;
            }
            if (! app(\App\Settings\BillingSettings::class)->auto_apply_tenant_credit) {
                return;
            }

            static::$applyingCredit = true;

            try {
                app(\App\Services\ApplyTenantCreditService::class)->applyToInvoice($invoice);
            } catch (\DomainException $e) {
                // "This tenant has no credit to apply" is the ORDINARY case — most invoices have
                // none — and a DomainException is a refusal, not a fault (bootstrap/app.php treats
                // it as one everywhere else). Logging it would put an error line under every
                // invoice the system raises.
            } catch (\Throwable $e) {
                // Anything else IS worth hearing about, but must never cost the operator the
                // invoice: the credit stays on account and can be applied by hand.
                \App\Support\OpsLog::error('invoice.auto_apply_credit_failed', [
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);
            } finally {
                static::$applyingCredit = false;
            }
        });

        // The header can never be moved away from the line items — see syncTotalsFromItems().
        // Gated on "an existing invoice whose header is being written" so an ordinary save (a
        // status flip, a note) costs nothing: on CREATE there are no items yet (the repeater and
        // every billing service write them after the header), and the item hook re-derives the
        // moment they land. `readOnly()` on the form is the UX; this is the rule.
        static::saving(function (self $invoice) {
            if (! $invoice->exists) {
                return;
            }

            $headerTouched = $invoice->isDirty(['subtotal', 'vat_amount', 'total']);
            $settlementTouched = $invoice->isDirty(['balance', 'paid_amount']);

            if (! $headerTouched && ! $settlementTouched) {
                return;
            }

            if ($headerTouched) {
                $invoice->syncTotalsFromItems(persist: false);
            }

            // ── `paid_amount` is not writable from here, by anyone ─────────────────────────────
            // It is derived from FOUR settlement channels and `recomputeTotals()` is the single
            // source of truth for it — which persists through `saveQuietly()`, so the legitimate
            // write never reaches this hook at all. Anything arriving here dirtying it is therefore
            // a client payload by construction, and the honest response is to discard it.
            //
            // Reverted rather than refused: the form submits this field on every save (it is
            // `readOnly()->dehydrated()`), so throwing would break ordinary edits that changed
            // something else entirely.
            if ($invoice->isDirty('paid_amount')) {
                $invoice->paid_amount = $invoice->getOriginal('paid_amount');
            }

            // Balance follows the (possibly corrected) total in the same write — mirrors the
            // `creating` branch below. Status is left to the next recomputeTotals(), as today.
            //
            // **Recomputed whenever EITHER group is dirty, which is the fix.** This used to
            // short-circuit unless the header moved, so a payload changing `balance` alone returned
            // before reaching it and the tampered value persisted: the invoice read settled in the
            // portal, in AR aging (which filters `balance > 0`), in the overdue scan and on every
            // collections screen, while the GL still carried the AR debit.
            $invoice->balance = $invoice->status === 'cancelled'
                ? 0
                : round(max(0, (float) $invoice->total - (float) $invoice->paid_amount), 2);
        });

        static::creating(function (self $invoice) {
            // Always (re)generate at save time so we never persist a stale
            // form-cached number that could collide with another record. The
            // prefix is the property's code (INV-AW-…), derived from the linked
            // lease's unit; falls back to AW when no lease is attached.
            $assetCode = $invoice->lease?->unit?->asset?->code ?: 'AW';

            // A migrated OPENING ITEM keeps the operator's own number.
            //
            // The always-regenerate rule above is right for an invoice this system issues —
            // nothing legitimately supplies one, so a stale form-cached number can only be wrong.
            // An opening item is the exception that proves it: its number is the one printed on
            // the paperwork the retailer already holds, and the reason to load open items rather
            // than a lump-sum balance is precisely so an operator can quote it on a collections
            // call. Renumbering it would make that call unanswerable.
            $keepsItsOwnNumber = $invoice->is_opening_balance && filled($invoice->number);

            if (! $keepsItsOwnNumber) {
                $invoice->number = $invoice->allocateDocumentNumber(
                    static::numberPrefix($assetCode, $invoice->issue_date),
                    fn (): string => static::generateUniqueNumber($assetCode, $invoice->issue_date),
                );
            }

            if (empty($invoice->currency)) {
                $invoice->currency = 'EGP';
            }
            if ($invoice->balance === null) {
                $invoice->balance = (float) ($invoice->total ?? 0) - (float) ($invoice->paid_amount ?? 0);
            }
            // Pre-generate the public pay-link token so the API/admin/portal never
            // write during a read. Existing invoices get one lazily (paymentLinkToken).
            if (blank($invoice->payment_link_token)) {
                $invoice->payment_link_token = Str::random(48);
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
            // Captured CASH blocks a cancel — on EVERY path, not just VoidInvoiceService, and
            // in `updating` so the write is refused rather than merely reported.
            //
            // The service has always refused this; the status Select on the invoice form offered
            // `cancelled` as a plain option and walked straight past it. Measured: an invoice with
            // a captured 10,000 payment cancelled cleanly from the form — status=cancelled, balance
            // forced to 0, payment still captured and still allocated 10,000, tenant credit 0. The
            // money was neither receivable nor owed back; it had vanished from every
            // operator-visible surface while the cash sat in the GL.
            //
            // (First written in the `updated` hook, which fires AFTER the row is persisted: the
            // exception surfaced but the cancel still happened. The regression test caught it.)
            //
            // capturedCashPaid() is the same predicate VoidInvoiceService uses — paid, net of
            // credit notes and applied tenant credit — named once so the two cannot drift.
            // Reversible non-cash settlement nets to zero here and is allowed; only real cash
            // refuses, and the remedy is to void/refund the payment first.
            if ($invoice->status === 'cancelled'
                && $invoice->getOriginal('status') !== 'cancelled'
                && $invoice->capturedCashPaid() > 0) {
                throw new \DomainException(__('admin.actions.cancel_blocked_captured_cash'));
            }

            if ($invoice->getOriginal('status') === 'draft') {
                return; // draft is freely editable (and draft→issued must be allowed)
            }
            if ($invoice->status === 'draft') {
                throw new \DomainException('An issued invoice cannot be returned to draft — void it or issue a credit note instead.');
            }
            // `number` joined this list 2026-08-12. It identifies the tax invoice the tenant is
            // holding — and that the ETA may have seen — so rewriting it silently re-labels a
            // document that exists outside this system. `ChangeImpact` classified it DESCRIPTIVE on
            // the stated grounds that "AllocatesDocumentNumber assigns it in `creating` and nothing
            // rewrites it", which was true of the code and not of the form: `InvoiceForm` renders it
            // `disabled()->dehydrated()`, and `disabled` is an HTML attribute while `dehydrated()`
            // is an explicit opt-IN to the submitted payload.
            foreach (['issue_date', 'tenant_id', 'lease_id', 'number'] as $field) {
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
                    app(CreditNoteService::class)->reverseAppliedCredit($invoice->fresh());
                }
                // Applied tenant CREDIT (on-account draw-downs) likewise returns to the tenant — soft-delete
                // the applications so their Dr Unearned / Cr AR entries void and the credit frees up again.
                // Fires on ANY cancel path, not just VoidInvoiceService, so a credit can never strand on a
                // voided invoice (else AR/Unearned would be left holding a reversed-but-still-applied credit).
                foreach (TenantCreditApplication::where('invoice_id', $invoice->id)->get() as $app) {
                    $app->delete();
                }

                // A netted SECURITY DEPOSIT returns the same way (MF-03). Without this the deposit
                // would stay spent on an invoice that no longer claims any AR — the tenant's
                // refund permanently short by the amount, and Deposits Held holding a balance
                // against a receivable that left the books.
                foreach (\App\Models\DepositApplication::where('invoice_id', $invoice->id)->get() as $app) {
                    $app->delete();
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
     * Re-derive the header (subtotal / vat_amount / total) from the line items. **The single
     * implementation of "an invoice's items sum to its header"** — every writer goes through it.
     *
     * The rule is load-bearing, not cosmetic: `InvoiceJournalizer` debits AR with the HEADER
     * total and credits revenue from the ITEM amounts (split by charge type) plus item VAT, so a
     * divergence computes the two sides of one journal entry from two different numbers. And
     * `recomputeTotals()` derives `balance` from the same header, so it is also the amount the
     * tenant is chased for.
     *
     * It used to live at the FORM layer only (`InvoiceForm` recomputes in `afterStateUpdated` and
     * renders the three fields `readOnly()`) — but `readOnly` is an HTML attribute, the fields are
     * `dehydrated()`, and nothing re-derived server-side: a tampered Livewire payload persisted a
     * header of 1 against 12,280 of items, and a direct item write (API / import / console / a
     * service that forgot) moved the items without moving the header at all. Promoted to the model
     * by the 2026-08-11 validation sweep; the form keeps its live recompute purely for the inline UX.
     *
     * An invoice with NO items is deliberately left alone — a legacy / opening-balance row carries
     * a header and has nothing to derive it from, so zeroing it would erase real AR.
     *
     * @param  bool  $persist  false = mutate the attributes in flight only (for the `saving` hook,
     *                         which must not save inside a save).
     */
    public function syncTotalsFromItems(bool $persist = true): void
    {
        if (! $this->exists) {
            return;
        }

        $items = $this->items()->get(['amount', 'vat_amount']);
        if ($items->isEmpty()) {
            return;
        }

        $subtotal = round((float) $items->sum('amount'), 2);
        $vat = round((float) $items->sum('vat_amount'), 2);

        $this->subtotal = $subtotal;
        $this->vat_amount = $vat;
        $this->total = round($subtotal + $vat, 2);

        if ($persist) {
            // recomputeTotals() saveQuietly()s, so it re-derives balance/status off the new
            // total and cannot re-enter the `saving` hook below.
            $this->recomputeTotals();
        }
    }

    /**
     * Recompute paid_amount / balance / status. **The single source of truth for AR balances.**
     *
     * `paid_amount` is the sum of FOUR channels — captured payments, applied credit notes, applied
     * tenant credit, and a security deposit netted at move-out. Nothing else may write it.
     */
    public function recomputeTotals(): void
    {
        $paid = (float) $this->payments()
            ->whereIn('payments.status', Payment::RECEIVED_STATUSES)
            ->sum('invoice_payment.allocated_amount');

        // Applied credit notes settle AR too (they bump credit_applied_amount,
        // not the payments pivot) — include them so a later payment recompute
        // doesn't erase the credit.
        $paid += (float) $this->credit_applied_amount;

        // Applied tenant CREDIT (an on-account advance drawn onto this invoice) also settles AR.
        // It is its own document (Dr Unearned / Cr AR); soft-deleted (reversed) rows are excluded,
        // so reversing an application re-opens the AR here on the next recompute.
        $paid += (float) TenantCreditApplication::where('invoice_id', $this->id)->sum('amount');

        // A SECURITY DEPOSIT netted against this invoice at move-out (story MF-03) — the fourth
        // and last channel. Its own document too (Dr Deposits Held / Cr AR), soft-deleted on
        // reversal, so the AR re-opens and the deposit balance returns on the next recompute.
        //
        // Every calculation that decides "how much of this invoice is settled" must count all four.
        // Three of them were added one at a time and each time something downstream had to learn
        // about it; if a fifth is ever needed, grep for this comment first.
        $paid += (float) DepositApplication::where('invoice_id', $this->id)->sum('amount');

        $this->paid_amount = round($paid, 2);
        $this->balance = round(max(0, (float) $this->total - $this->paid_amount), 2);

        // A cancelled invoice claims no AR — force balance to 0 (it left the books).
        // Also prevents a phantom 'total' balance after a cancel-reversal zeroes the
        // applied credit (paid→0 would otherwise re-derive balance = total).
        if ($this->status === 'cancelled') {
            $this->balance = 0;
        }

        // Auto-status: don't override manual overrides like 'cancelled' / 'credited' / 'disputed'.
        // 'written_off' joins the manual overrides: a debt accepted as uncollectible must not be
        // dragged back to 'overdue' by the next recompute. Reversing a write-off is what re-opens
        // it (WriteOffInvoiceService::reverse), not a side effect of recalculating a balance.
        if (! in_array($this->status, ['cancelled', 'credited', 'disputed', 'written_off'])) {
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

    /**
     * The portion of paid_amount that is real captured CASH — i.e. excluding the reversible,
     * non-cash settlements (applied credit notes, applied tenant credit, netted security deposit).
     * This is what must be refunded / reversed before an invoice can be voided; a voidable invoice
     * has captured cash ≤ 0. Named here once so the void guard (service + Filament visible()) can't
     * drift on how they are treated.
     *
     * A netted deposit belongs on the exclusion list for the same reason an applied credit does: no
     * cash arrived, and the settlement reverses by soft-deleting its own document. Counting it as
     * cash would refuse to void an invoice that has nothing to refund.
     */
    public function capturedCashPaid(): float
    {
        return round(
            (float) $this->paid_amount
            - (float) $this->credit_applied_amount
            - (float) TenantCreditApplication::where('invoice_id', $this->id)->sum('amount')
            - (float) \App\Models\DepositApplication::where('invoice_id', $this->id)->sum('amount'),
            2,
        );
    }

    /** A draft invoice has not been issued to anyone; anything past draft is on the books. */
    public function isCommittedForDeletionPurposes(): bool
    {
        return $this->status !== 'draft';
    }

}
