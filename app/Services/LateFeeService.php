<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Tenant;
use App\Notifications\LateFeeAppliedNotification;
use App\Support\OpsLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class LateFeeService
{
    /**
     * Apply late fees to all invoices that are past (due_date + grace_days) and
     * not yet fully paid. Idempotent — invoices that already carry a `late_fee`
     * line item are skipped.
     *
     * @return array{considered:int, applied:int, skipped:int, failed:int}
     */
    public function runForToday(?CarbonImmutable $today = null): array
    {
        $today = $today ?? CarbonImmutable::now()->startOfDay();
        $graceDays = (int) config('billing.late_fee_grace_days', 7);
        $cutoff = $today->subDays($graceDays);

        $invoices = Invoice::query()
            ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
            ->where('balance', '>', 0)
            ->whereDate('due_date', '<=', $cutoff)
            ->get();

        $stats = ['considered' => $invoices->count(), 'applied' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($invoices as $invoice) {
            try {
                $applied = $this->applyTo($invoice);
                if ($applied) {
                    $stats['applied']++;
                } else {
                    $stats['skipped']++;
                }
            } catch (\Throwable $e) {
                $stats['failed']++;
                OpsLog::error('Late fee application failed', [
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        OpsLog::info('Late fee batch complete', $stats);

        return $stats;
    }

    /**
     * Apply a late fee to one invoice. Returns true if a fee was added,
     * false if already applied (idempotent guard).
     */
    public function applyTo(Invoice $invoice): bool
    {
        $percent = (float) config('billing.late_fee_percent', 2.0);
        $min = (float) config('billing.late_fee_minimum', 50.0);

        return DB::transaction(function () use ($invoice, $percent, $min) {
            // Lock the invoice row and re-check the idempotency guard INSIDE the
            // transaction, so two concurrent late-fee runs can't both pass the
            // "no late_fee yet" check and double-charge the same invoice.
            $locked = Invoice::query()->lockForUpdate()->find($invoice->id);

            // Re-check the FULL precondition inside the lock, not just the late_fee
            // idempotency stamp: the outer query snapshotted this invoice as overdue
            // with balance > 0, but a payment captured between the snapshot and this
            // lock may have settled it. Charging a late fee on a now-paid invoice
            // would be wrong (and would still bill the minimum fee off a zero balance).
            if (! $locked
                || $locked->items()->where('type', 'late_fee')->exists()
                || (float) $locked->balance <= 0
                || ! in_array($locked->status, ['issued', 'partially_paid', 'overdue'], true)) {
                return false;
            }

            $fee = max($min, round((float) $locked->balance * $percent / 100, 2));

            InvoiceItem::create([
                'invoice_id' => $locked->id,
                // Spell out the basis so the operator (and the tenant on the invoice/PDF) can verify
                // the charge instead of seeing a bare "Late Fee" amount.
                'description' => __('admin.actions.late_fee_line_description', [
                    'percent' => rtrim(rtrim(number_format($percent, 2), '0'), '.'),
                    'balance' => 'EGP '.number_format((float) $locked->balance, 2),
                    'min' => 'EGP '.number_format((float) $min, 2),
                ]),
                'type' => 'late_fee',
                'amount' => $fee,
                'vat_rate' => 0,
                'vat_amount' => 0,
                'total' => $fee,
            ]);

            // Bump only the non-derived header amounts, then let the single source
            // of truth re-derive balance from total − paid (was writing balance
            // directly, bypassing recomputeTotals — invariant smell).
            $locked->subtotal = (float) $locked->subtotal + $fee;
            $locked->total = (float) $locked->total + $fee;
            $locked->status = 'overdue';
            $locked->recomputeTotals();

            // Notify the tenant from INSIDE the transaction so the (queued) delivery
            // commits atomically with the fee — a crash or rollback loses both, so
            // the tenant can never be charged a late fee without being told. The
            // notification is ShouldQueue on the database queue, so this only writes
            // a job row here (no SMTP under the row lock).
            /** @var Tenant|null $tenant */
            $tenant = $locked->tenant;
            $tenant?->notifyPortal(new LateFeeAppliedNotification($locked));

            return true;
        });
    }
}
