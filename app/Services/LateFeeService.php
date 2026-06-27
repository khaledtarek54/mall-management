<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
                Log::error('Late fee application failed', [
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Late fee batch complete', $stats);

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

            if (! $locked || $locked->items()->where('type', 'late_fee')->exists()) {
                return false;
            }

            $fee = max($min, round((float) $locked->balance * $percent / 100, 2));

            InvoiceItem::create([
                'invoice_id' => $locked->id,
                'description' => __('admin.enums.invoice_item_type.late_fee'),
                'type' => 'late_fee',
                'amount' => $fee,
                'vat_rate' => 0,
                'vat_amount' => 0,
                'total' => $fee,
            ]);

            $locked->subtotal = (float) $locked->subtotal + $fee;
            $locked->total = (float) $locked->total + $fee;
            $locked->balance = (float) $locked->balance + $fee;
            $locked->status = 'overdue';
            $locked->save();

            return true;
        });
    }
}
