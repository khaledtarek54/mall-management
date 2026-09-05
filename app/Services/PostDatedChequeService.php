<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PostDatedCheque;
use App\Models\User;
use App\Support\InvoiceSettlement;
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
    /**
     * Lodge the cheque with a bank for collection — and RECORD WHICH ONE.
     *
     * **The bank is captured here, not at clearing, and that is Yardi's shape.** Voyager deposits a
     * PDC to a named bank account and treats clearing as the confirmation of that deposit. The bank
     * belongs to the physical act — one piece of paper, one branch — and it is known on the day.
     * Atriom used to infer it months later from whichever property was on screen when somebody
     * pressed Clear, which is right most of the time and a guess every time. It is not cosmetic:
     * `MatchBankStatementLineService::candidatesFor()` finds candidates by the chart account behind
     * the bank, so a cheque banked at NBE and cleared under CIB becomes a CIB candidate and the
     * operator matches money against a statement it never appeared on.
     *
     * **Optional, on the same terms as every other bank account in this system.** An install that
     * has not registered one still lodges cheques exactly as before; the account simply stays null
     * and clearing falls back to the rail. `RecordsBankAccount` refuses another mall's account.
     *
     * **Re-presenting a bounced cheque re-asks.** A cheque returned unpaid is commonly re-presented
     * somewhere else, so the new answer replaces the old rather than the first lodgement standing
     * for the life of the instrument. Passing nothing on a re-present keeps what was there, because
     * "I did not say" must not erase "it went to CIB".
     */
    public function deposit(PostDatedCheque $cheque, ?int $bankAccountId = null, ?string $depositedOn = null): PostDatedCheque
    {
        return DB::transaction(function () use ($cheque, $bankAccountId, $depositedOn) {
            $cheque = PostDatedCheque::whereKey($cheque->id)->lockForUpdate()->firstOrFail();

            if (! in_array($cheque->status, [PostDatedCheque::STATUS_HELD, PostDatedCheque::STATUS_BOUNCED], true)) {
                throw new \DomainException(__('admin.refusals.cheque_deposit_state'));
            }

            // A lodgement cannot be in the future — you either handed the cheque over or you did
            // not. Nothing posts here, so the period is not consulted: this is a fact about paper,
            // not an entry. (`PostingDate::assertNotFuture` is for dates that become an entry_date.)
            $on = $depositedOn !== null ? Carbon::parse($depositedOn) : Carbon::now();

            if ($on->isAfter(Carbon::now()->endOfDay())) {
                throw new \DomainException(__('admin.refusals.cheque_deposit_future'));
            }

            $cheque->update([
                'status' => PostDatedCheque::STATUS_DEPOSITED,
                'deposited_on' => $on->toDateString(),
                // Coalesce, never overwrite with null — see the docblock.
                'bank_account_id' => $bankAccountId ?? $cheque->bank_account_id,
            ]);

            return $cheque;
        });
    }

    /** Clear a cheque: record a captured cheque Payment against its invoice, then flip to cleared. */
    public function clear(PostDatedCheque $cheque, User $actor, ?string $clearedOn = null): PostDatedCheque
    {
        return DB::transaction(function () use ($cheque, $actor, $clearedOn) {
            $cheque = PostDatedCheque::whereKey($cheque->id)->lockForUpdate()->firstOrFail();

            if (! in_array($cheque->status, [PostDatedCheque::STATUS_HELD, PostDatedCheque::STATUS_DEPOSITED], true)) {
                throw new \DomainException(__('admin.refusals.cheque_clear_state'));
            }

            // Money moved: not future, and the period must be open (the Payment will post to the GL).
            $date = PostingDate::assertNotFuture($clearedOn ?? now()->toDateString(), 'cleared_on');

            $payment = Payment::create([
                'reference' => Payment::generateReference(),
                'tenant_id' => $cheque->tenant_id,
                'amount' => round((float) $cheque->amount, 2),
                'currency' => $cheque->currency,
                'method' => 'cheque',
                // WHERE the money landed, taken from the lodgement rather than from whatever
                // property is on screen. Null when the cheque was never lodged against a named
                // account, which falls back to the rail exactly as it did before.
                'bank_account_id' => $cheque->bank_account_id,
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
                // Capped by `InvoiceSettlement`, not by `balance`. The link is checked when the
                // cheque is LODGED and never re-asked when it CLEARS, and months pass in between —
                // so a link-time filter cannot answer a clear-time question. A write-off deliberately
                // leaves `balance` standing, so this cap saw nothing: clearing a cheque against an
                // invoice written off in the meantime relieved AR a second time, leaving AR at
                // −11,400 for one debt with the bad-debt expense standing for money that was in fact
                // collected — and `billing:reconcile --deep` permanently red, which blocks the next
                // deploy. `cancelled` was safe here only by accident (its balance is forced to 0).
                $allocate = $invoice ? min(round((float) $cheque->amount, 2), InvoiceSettlement::settleableAmount($invoice)) : 0.0;
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
            } else {
                $this->settleOpenInvoices($cheque, $payment);
            }

            $cheque->update([
                'status' => PostDatedCheque::STATUS_CLEARED,
                'cleared_payment_id' => $payment->id,
            ]);

            return $cheque;
        });
    }

    /**
     * A SERIES cheque names no invoice, and that is the Egyptian norm — a tenant hands over a year
     * of monthly cheques before most of those invoices exist. `lodgeSeries()` has always promised in
     * writing that "each cheque settles whatever is open when it clears, through the normal clear()
     * flow", and until 2026-08-24 `clear()` did not keep that promise: with no `invoice_id` it
     * captured a Payment with ZERO allocations, and a wholly unallocated receipt belongs to no
     * property — `Tenant::creditBalance([$assetId])` attributes credit through the invoices a
     * payment settles, so the per-property term was 0, `ApplyTenantCreditService` refused every draw,
     * `Invoice::saved`'s auto-apply hook swallowed that refusal as the ordinary case, and the month's
     * invoice stayed open. The overdue sweep and `LateFeeService` both read an open balance: the
     * tenant was chased, and could be charged a late fee, while the mall held their cleared cash.
     * `PaymentForm` already REFUSES creating a zero-allocation receipt for exactly this orphaning
     * reason — the cheque-clear path was minting the record that guard exists to prevent.
     *
     * So the receipt settles the tenant's own OPEN invoices in the CHEQUE'S property, oldest due
     * first. Deliberately NOT scoped to `lease_id`: Voyager applies a receipt at the customer record
     * rather than per lease, and one cheque legitimately covers whatever that tenant owes in that
     * mall. Any surplus stays on account and is still drawable, because `creditBalance()` falls back
     * to the cheque's own property for a receipt with no allocations at all.
     */
    private function settleOpenInvoices(PostDatedCheque $cheque, Payment $payment): void
    {
        // A LOCKING read, not a plain one. Under MySQL REPEATABLE READ a plain select inside this
        // transaction answers from the snapshot taken before we waited on the cheque's own lock, so
        // an invoice another writer settled while we waited would still read as open and this
        // receipt would over-allocate it (the class of bug F-09 fixed across the other guards).
        $open = Invoice::query()
            ->where('tenant_id', $cheque->tenant_id)
            ->where('asset_id', $cheque->asset_id)
            ->acceptingSettlement()
            ->where('balance', '>', 0)
            ->orderBy('due_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $remaining = round((float) $cheque->amount, 2);
        $allocations = [];

        foreach ($open as $invoice) {
            if ($remaining <= 0) {
                break;
            }

            // `settleableAmount()`, not the raw balance. On a PARTIAL write-off the invoice stays
            // LIVE with its whole balance standing, so allocating the balance would relieve the
            // forgiven slice a second time — and would then be REFUSED by
            // assertInvoicesNotOverAllocated() below, rolling the entire clearing back. A series
            // cheque offers the operator no way to choose a smaller allocation, so that refusal
            // would make a legitimate cheque impossible to clear.
            $allocate = round(min($remaining, InvoiceSettlement::settleableAmount($invoice)), 2);

            if ($allocate <= 0) {
                continue;
            }

            $allocations[$invoice->id] = ['allocated_amount' => $allocate];
            $remaining = round($remaining - $allocate, 2);
        }

        // Nothing open is a genuine advance — the tenant paid before being billed. The receipt stays
        // wholly unallocated on purpose and is drawable as on-account credit against next month.
        if ($allocations === []) {
            return;
        }

        $ids = array_keys($allocations);

        // The cheque's payment settles the tenant's OWN invoices — belt to the model's link-time
        // guard, exactly as the linked-invoice branch does.
        $payment->assertInvoicesShareTenant($ids);
        $payment->invoices()->sync($allocations);
        $payment->recomputeAllocatedInvoices();
        $payment->assertInvoicesNotOverAllocated($ids);
    }

    public function bounce(PostDatedCheque $cheque): PostDatedCheque
    {
        return DB::transaction(function () use ($cheque) {
            $cheque = PostDatedCheque::whereKey($cheque->id)->lockForUpdate()->firstOrFail();

            if (! in_array($cheque->status, [PostDatedCheque::STATUS_HELD, PostDatedCheque::STATUS_DEPOSITED], true)) {
                throw new \DomainException(__('admin.refusals.cheque_bounce_state'));
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
                throw new \DomainException(__('admin.refusals.cheque_cleared_cancel'));
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

        $this->assertSeriesNumbersFit((string) $data['first_cheque_number'], $count);

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

    /**
     * Every number this series will mint has to fit `cheque_number` — varchar(100) NOT NULL — and
     * the check is on the LAST one, not the first.
     *
     * Two ways a bounded field alone is not enough, both measured (2026-09-05):
     *
     *  1. The non-numeric branch of {@see nextChequeNumber()} appends `-N`, so a 98-character
     *     number inside the bound becomes 101 characters on the tenth cheque of a series — and the
     *     failure lands mid-`DB::transaction`, as an unhandled `QueryException` rather than as a
     *     refusal anybody can read.
     *  2. A numeric tail longer than PHP's integer can hold overflows on the `(int)` cast, so
     *     `+1` and `+2` produce the SAME string and the series is refused by the unique index
     *     with a duplicate-cheque message that describes nothing the operator did.
     *
     * Refused up front, in the operator's own words, rather than discovered by the database.
     */
    private function assertSeriesNumbersFit(string $first, int $count): void
    {
        if (mb_strlen($first) > PostDatedCheque::MAX_NUMBER_LENGTH) {
            throw new \DomainException(__('admin.post_dated_cheques.errors.series_number_too_long', [
                'max' => PostDatedCheque::MAX_NUMBER_LENGTH,
            ]));
        }

        // 19 digits is where a decimal string stops fitting a 64-bit int.
        if (preg_match('/^(\D*)(\d+)$/', $first, $m) === 1 && strlen($m[2]) > 18) {
            throw new \DomainException(__('admin.post_dated_cheques.errors.series_number_not_countable'));
        }

        $last = $this->nextChequeNumber($first, $count - 1);

        if (mb_strlen($last) > PostDatedCheque::MAX_NUMBER_LENGTH) {
            throw new \DomainException(__('admin.post_dated_cheques.errors.series_number_grows_too_long', [
                'last' => $last,
                'max' => PostDatedCheque::MAX_NUMBER_LENGTH,
            ]));
        }
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
