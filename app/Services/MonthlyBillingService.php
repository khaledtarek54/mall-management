<?php

namespace App\Services;

use App\Mail\InvoiceIssued;
use App\Models\Charge;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Lease;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class MonthlyBillingService
{
    /**
     * Generate invoices for every active lease for the given month.
     *
     * Idempotent: a lease that already has an invoice covering $period is skipped.
     * Each lease is processed in its own transaction so one failure does not abort the run.
     *
     * @return array{period:string, leases_considered:int, created:int, skipped:int, failed:int, failed_lease_ids:int[]}
     */
    public function runForPeriod(?CarbonImmutable $period = null): array
    {
        $period = ($period ?? CarbonImmutable::now())->startOfMonth();
        $periodStart = $period;
        $periodEnd = $period->endOfMonth();

        $stats = [
            'period' => $period->format('Y-m'),
            'leases_considered' => 0,
            'created' => 0,
            'skipped' => 0,
            'failed' => 0,
            'failed_lease_ids' => [],
        ];

        Lease::query()
            ->where('status', 'active')
            ->where('commencement_date', '<=', $periodEnd)
            ->where(function ($q) use ($periodStart) {
                $q->whereNull('expiry_date')->orWhere('expiry_date', '>=', $periodStart);
            })
            ->with(['charges' => fn ($q) => $q->where('is_active', true)])
            ->chunkById(100, function ($leases) use (&$stats, $periodStart, $periodEnd) {
                foreach ($leases as $lease) {
                    $stats['leases_considered']++;

                    $alreadyBilled = Invoice::where('lease_id', $lease->id)
                        ->whereDate('period_start', $periodStart->toDateString())
                        ->exists();

                    if ($alreadyBilled) {
                        $stats['skipped']++;
                        continue;
                    }

                    try {
                        $invoice = DB::transaction(function () use ($lease, $periodStart, $periodEnd) {
                            return $this->generateInvoiceForLease($lease, $periodStart, $periodEnd);
                        });
                        $stats['created']++;
                        if ($invoice) {
                            $this->notifyInvoiceIssued($invoice);
                        }
                    } catch (Throwable $e) {
                        $stats['failed']++;
                        $stats['failed_lease_ids'][] = $lease->id;
                        Log::error('Monthly billing failed for lease', [
                            'lease_id' => $lease->id,
                            'period' => $periodStart->format('Y-m'),
                            'exception' => $e,
                        ]);
                    }
                }
            });

        return $stats;
    }

    /**
     * Generate a single invoice for one lease for a given period.
     *
     * Idempotent (same skip-rule as runForPeriod). Returns a status array so
     * the UI can render a friendly notification.
     *
     * @return array{status:'created'|'skipped'|'failed', reason?:string, invoice:?Invoice}
     */
    public function generateForLease(Lease $lease, ?CarbonImmutable $period = null, bool $prorate = false): array
    {
        $period = ($period ?? CarbonImmutable::now())->startOfMonth();
        $periodStart = $period;
        $periodEnd = $period->endOfMonth();

        $alreadyBilled = Invoice::where('lease_id', $lease->id)
            ->whereDate('period_start', $periodStart->toDateString())
            ->exists();

        if ($alreadyBilled) {
            return ['status' => 'skipped', 'reason' => 'already_billed', 'invoice' => null];
        }

        $lease->loadMissing(['charges' => fn ($q) => $q->where('is_active', true)]);

        try {
            $invoice = DB::transaction(
                fn () => $this->generateInvoiceForLease($lease, $periodStart, $periodEnd, $prorate)
            );
        } catch (Throwable $e) {
            Log::error('Single-lease invoice generation failed', [
                'lease_id' => $lease->id,
                'period' => $periodStart->format('Y-m'),
                'exception' => $e,
            ]);

            return ['status' => 'failed', 'reason' => 'exception', 'invoice' => null];
        }

        if (! $invoice) {
            return ['status' => 'skipped', 'reason' => 'no_applicable_charges', 'invoice' => null];
        }

        $this->notifyInvoiceIssued($invoice);

        return ['status' => 'created', 'invoice' => $invoice];
    }

    private function notifyInvoiceIssued(Invoice $invoice): void
    {
        $tenant = $invoice->tenant;
        if (! $tenant) {
            return;
        }

        try {
            // Notification ships via 'mail' + 'database' so the tenant gets
            // both an email with PDF attachment and a portal bell entry.
            $tenant->notify(new \App\Notifications\InvoiceIssuedNotification($invoice));
        } catch (Throwable $e) {
            Log::warning('Invoice issued notification failed to queue', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function generateInvoiceForLease(Lease $lease, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, bool $prorate = false): ?Invoice
    {
        $applicableCharges = $lease->charges->filter(
            fn (Charge $c) => $this->chargeAppliesToPeriod($c, $periodStart, $periodEnd)
        );

        if ($applicableCharges->isEmpty()) {
            return null;
        }

        // Pro-rate only if the lease commences mid-period and the caller asked for it.
        $factor = 1.0;
        $effectivePeriodStart = $periodStart;
        $commencement = $lease->commencement_date
            ? CarbonImmutable::instance($lease->commencement_date)
            : null;

        if ($prorate && $commencement && $commencement->between($periodStart, $periodEnd) && $commencement->greaterThan($periodStart)) {
            $daysInPeriod = $periodEnd->diffInDays($periodStart) + 1;
            $daysBilled = $periodEnd->diffInDays($commencement) + 1;
            $factor = round($daysBilled / $daysInPeriod, 4);
            $effectivePeriodStart = $commencement;
        }

        $items = $applicableCharges->map(function (Charge $charge) use ($periodStart, $factor) {
            $amount = round((float) $charge->amount * $factor, 2);
            $vatRate = $charge->vat_applicable ? (float) $charge->vat_rate : 0.0;
            $vatAmount = round($amount * ($vatRate / 100), 2);
            $label = $charge->name . ' - ' . $periodStart->format('F Y');
            if ($factor < 1) {
                $label .= ' (' . round($factor * 100) . '% pro-rated)';
            }

            return [
                'charge_id' => $charge->id,
                'description' => $label,
                'type' => $charge->type,
                'amount' => $amount,
                'vat_rate' => $vatRate,
                'vat_amount' => $vatAmount,
                'total' => round($amount + $vatAmount, 2),
            ];
        });

        $subtotal = round((float) $items->sum('amount'), 2);
        $vatAmount = round((float) $items->sum('vat_amount'), 2);
        $total = round($subtotal + $vatAmount, 2);

        $issueDate = $effectivePeriodStart;
        $dueDate = $issueDate->addDays($lease->payment_terms_days ?? 7);

        $invoice = Invoice::create([
            'lease_id' => $lease->id,
            'tenant_id' => $lease->tenant_id,
            'status' => 'issued',
            'issue_date' => $issueDate,
            'due_date' => $dueDate,
            'period_start' => $effectivePeriodStart,
            'period_end' => $periodEnd,
            'subtotal' => $subtotal,
            'vat_amount' => $vatAmount,
            'total' => $total,
            'paid_amount' => 0,
            'balance' => $total,
            'currency' => $lease->currency ?? 'EGP',
        ]);

        foreach ($items as $item) {
            InvoiceItem::create($item + ['invoice_id' => $invoice->id]);
        }

        $this->accrueMarketingLevy($lease, $items, $effectivePeriodStart);

        return $invoice;
    }

    /**
     * Accrue the marketing levy — a percentage of billed base rent (FR MKT-2/5)
     * — into the property's marketing budget. An internal allocation: it does
     * NOT add a tenant charge, so invoice totals are unchanged. Wrapped so a
     * budget hiccup never breaks invoice generation.
     */
    private function accrueMarketingLevy(Lease $lease, \Illuminate\Support\Collection $items, CarbonImmutable $periodStart): void
    {
        try {
            $rentBilled = (float) $items->where('type', 'base_rent')->sum('amount');
            if ($rentBilled <= 0) {
                return;
            }

            $svc = app(MarketingLevyService::class);
            $levy = round($rentBilled * $svc->ratePercent() / 100, 2);
            if ($levy <= 0) {
                return;
            }

            $assetId = $lease->loadMissing('unit')->unit?->asset_id;
            if (! $assetId) {
                return;
            }

            $svc->accrue($assetId, (int) $periodStart->year, $levy);
        } catch (Throwable $e) {
            Log::warning('Marketing levy accrual failed', [
                'lease_id' => $lease->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function chargeAppliesToPeriod(Charge $charge, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): bool
    {
        if ($charge->start_date && $charge->start_date->greaterThan($periodEnd)) {
            return false;
        }
        if ($charge->end_date && $charge->end_date->lessThan($periodStart)) {
            return false;
        }

        return match ($charge->frequency) {
            'monthly' => true,
            'quarterly' => $charge->start_date
                ? ((int) $charge->start_date->diffInMonths($periodStart)) % 3 === 0
                : ($periodStart->month - 1) % 3 === 0,
            'annually' => $charge->start_date
                ? $charge->start_date->month === $periodStart->month
                : $periodStart->month === 1,
            'one_time' => $charge->start_date
                && $charge->start_date->between($periodStart, $periodEnd),
            default => false,
        };
    }
}
