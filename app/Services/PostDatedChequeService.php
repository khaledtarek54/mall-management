<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PostDatedCheque;
use App\Models\User;
use App\Support\PostingDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The post-dated-cheque lifecycle: deposit → clear (or bounce), and cancel. v1 is register-only:
 * CLEARING is the only step that touches money — it records a normal cheque Payment through the
 * existing payment flow (allocating to the linked invoice, capped at its balance) so
 * Invoice::recomputeTotals() stays the AR single source of truth. Everything else just moves the
 * cheque's status. Lock-safe + idempotent (row-lock + re-check under the lock).
 */
class PostDatedChequeService
{
    public function deposit(PostDatedCheque $cheque): PostDatedCheque
    {
        return DB::transaction(function () use ($cheque) {
            $cheque = PostDatedCheque::whereKey($cheque->id)->lockForUpdate()->firstOrFail();

            if (! in_array($cheque->status, [PostDatedCheque::STATUS_HELD, PostDatedCheque::STATUS_BOUNCED], true)) {
                throw new \DomainException('Only a held (or re-presented, bounced) cheque can be deposited.');
            }

            $cheque->update(['status' => PostDatedCheque::STATUS_DEPOSITED]);

            return $cheque;
        });
    }

    /** Clear a cheque: record a captured cheque Payment against its invoice, then flip to cleared. */
    public function clear(PostDatedCheque $cheque, User $actor, ?string $clearedOn = null): PostDatedCheque
    {
        return DB::transaction(function () use ($cheque, $actor, $clearedOn) {
            $cheque = PostDatedCheque::whereKey($cheque->id)->lockForUpdate()->firstOrFail();

            if (! in_array($cheque->status, [PostDatedCheque::STATUS_HELD, PostDatedCheque::STATUS_DEPOSITED], true)) {
                throw new \DomainException('Only a held or deposited cheque can be cleared.');
            }

            // Money moved: not future, and the period must be open (the Payment will post to the GL).
            $date = PostingDate::assertNotFuture($clearedOn ?? now()->toDateString(), 'cleared_on');

            $payment = Payment::create([
                'reference' => Payment::generateReference(),
                'tenant_id' => $cheque->tenant_id,
                'amount' => round((float) $cheque->amount, 2),
                'currency' => $cheque->currency,
                'method' => 'cheque',
                'status' => 'captured',
                'payment_date' => $date->toDateString(),
                'cheque_number' => $cheque->cheque_number,
                'received_by' => $actor->id,
                'notes' => "Cleared post-dated cheque {$cheque->reference}",
            ]);

            // Allocate to the linked invoice, capped at its balance (the surplus stays as an
            // on-account credit rather than over-paying the invoice).
            if ($cheque->invoice_id) {
                // Lock the INVOICE (not just the cheque): two cheques clearing against the same
                // invoice concurrently would each read the pre-settlement balance and both fit
                // `min(amount, balance)`, together over-allocating it (paid_amount > total → AR
                // credited past the receivable, negative AR in the GL). The lock serialises them,
                // mirroring every other payment path (audit M33 F-1).
                $invoice = Invoice::whereKey($cheque->invoice_id)->lockForUpdate()->first();
                $allocate = $invoice ? min(round((float) $cheque->amount, 2), round((float) $invoice->balance, 2)) : 0.0;
                if ($allocate > 0) {
                    // The cheque's payment settles the tenant's OWN invoice — never another
                    // tenant's, even in the same property. Belt to the model's link-time guard
                    // (audit M33 F-2).
                    $payment->assertInvoicesShareTenant([$cheque->invoice_id]);
                    $payment->invoices()->sync([$cheque->invoice_id => ['allocated_amount' => $allocate]]);
                    $payment->recomputeAllocatedInvoices();
                    // Concurrency backstop: re-check under the invoice lock that captured
                    // allocations + applied credits don't exceed the total; a racing second clear
                    // rolls back here rather than silently over-settling.
                    $payment->assertInvoicesNotOverAllocated([$cheque->invoice_id]);
                }
            }

            $cheque->update([
                'status' => PostDatedCheque::STATUS_CLEARED,
                'cleared_payment_id' => $payment->id,
            ]);

            return $cheque;
        });
    }

    public function bounce(PostDatedCheque $cheque): PostDatedCheque
    {
        return DB::transaction(function () use ($cheque) {
            $cheque = PostDatedCheque::whereKey($cheque->id)->lockForUpdate()->firstOrFail();

            if (! in_array($cheque->status, [PostDatedCheque::STATUS_HELD, PostDatedCheque::STATUS_DEPOSITED], true)) {
                throw new \DomainException('Only a held or deposited cheque can bounce.');
            }

            // No Payment was made before clearing, so a bounce reverses nothing — the tenant's
            // invoice was never reduced. The cheque can be re-presented (deposited) or cancelled.
            $cheque->update(['status' => PostDatedCheque::STATUS_BOUNCED]);

            return $cheque;
        });
    }

    public function cancel(PostDatedCheque $cheque): PostDatedCheque
    {
        return DB::transaction(function () use ($cheque) {
            $cheque = PostDatedCheque::whereKey($cheque->id)->lockForUpdate()->firstOrFail();

            if ($cheque->status === PostDatedCheque::STATUS_CLEARED) {
                throw new \DomainException('A cleared cheque cannot be cancelled (void its payment instead).');
            }
            if ($cheque->status === PostDatedCheque::STATUS_CANCELLED) {
                return $cheque;
            }

            $cheque->update(['status' => PostDatedCheque::STATUS_CANCELLED]);

            return $cheque;
        });
    }

    /**
     * Lodge a SERIES of cheques in one act (the Egyptian norm — a tenant hands over a year of monthly
     * post-dated cheques up front). Entering them one at a time is slow and error-prone; this creates
     * `count` cheques with sequential numbers and maturities `intervalMonths` apart.
     *
     * A series is deliberately NOT pre-linked to an invoice — the month's invoice may not exist yet;
     * each cheque settles whatever is open when it clears, through the normal clear() flow.
     *
     * @param  array{asset_id:int, tenant_id:int, lease_id?:int|null, bank_name?:string|null,
     *     first_cheque_number:string, amount:float, count:int, first_cheque_date:string,
     *     received_date?:string|null, interval_months?:int, notes?:string|null}  $data
     * @return Collection<int, PostDatedCheque>
     *
     * @throws \DomainException
     */
    public function lodgeSeries(array $data): Collection
    {
        $count = (int) $data['count'];
        $amount = round((float) $data['amount'], 2);
        $interval = max(1, (int) ($data['interval_months'] ?? 1));

        if ($count < 1 || $count > 60) {
            throw new \DomainException(__('admin.post_dated_cheques.errors.series_count'));
        }
        if ($amount <= 0) {
            throw new \DomainException(__('admin.post_dated_cheques.errors.series_amount'));
        }

        $firstMaturity = Carbon::parse($data['first_cheque_date']);
        $received = isset($data['received_date'])
            ? Carbon::parse($data['received_date'])->toDateString()
            : now()->toDateString();

        return DB::transaction(function () use ($data, $count, $amount, $interval, $firstMaturity, $received) {
            $created = collect();

            for ($i = 0; $i < $count; $i++) {
                $created->push(PostDatedCheque::create([
                    // Each cheque is its own register entry with its own PDC reference. Generated
                    // per-row (the model has no auto-creating hook — the create page sets it too).
                    'reference' => PostDatedCheque::generateReference(),
                    'asset_id' => $data['asset_id'],
                    'tenant_id' => $data['tenant_id'],
                    'lease_id' => $data['lease_id'] ?? null,
                    'bank_name' => $data['bank_name'] ?? null,
                    // Sequential numbers: increment the numeric tail, preserving any zero-padding
                    // ("100123" → "100124"); a non-numeric number falls back to a "-N" suffix.
                    'cheque_number' => $this->nextChequeNumber((string) $data['first_cheque_number'], $i),
                    'amount' => $amount,
                    'currency' => 'EGP',
                    // Maturities march forward one interval at a time (monthly by default).
                    'cheque_date' => $firstMaturity->copy()->addMonthsNoOverflow($i * $interval)->toDateString(),
                    'received_date' => $received,
                    'status' => PostDatedCheque::STATUS_HELD,
                    'notes' => $data['notes'] ?? null,
                ]));
            }

            return $created;
        });
    }

    /** The `$offset`-th cheque number after `$first`, incrementing its numeric tail with zero-pad kept. */
    private function nextChequeNumber(string $first, int $offset): string
    {
        if ($offset === 0) {
            return $first;
        }

        if (preg_match('/^(\D*)(\d+)$/', $first, $m) === 1) {
            $width = strlen($m[2]);

            return $m[1].str_pad((string) ((int) $m[2] + $offset), $width, '0', STR_PAD_LEFT);
        }

        // No numeric tail to increment — keep the base and disambiguate with the sequence index.
        return $first.'-'.($offset + 1);
    }
}
