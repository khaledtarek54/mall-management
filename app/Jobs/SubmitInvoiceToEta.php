<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Services\Eta\EtaSubmissionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Support\OpsLog;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SubmitInvoiceToEta implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Bounded retries (1 initial + 2 backed-off retries) instead of the
     * worker's default 3 back-to-back attempts. The default behavior would
     * hammer ETA's OAuth endpoint within seconds and each retry would
     * overwrite eta_response with a fresh error message, losing the
     * diagnostic trail. See audit M08 F-34 / D-25.
     */
    public int $tries = 3;

    public function __construct(public Invoice $invoice) {}

    /**
     * Retry schedule in seconds: 1 minute → 5 minutes after the first failure,
     * then a 15-minute wait before the final attempt. Rides out short ETA
     * outages without re-storming the auth endpoint, and gives an operator
     * time to fix a missing tax_id between attempts.
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(EtaSubmissionService $service): void
    {
        $service->submit($this->invoice);
    }

    /**
     * All retries exhausted — a tax submission has NOT gone through. Surface it
     * loudly so it doesn't go unnoticed for weeks (lands in failed_jobs too).
     */
    public function failed(?Throwable $e): void
    {
        OpsLog::error('eta.job_exhausted', [
            'invoice_id' => $this->invoice->id,
            'invoice_number' => $this->invoice->number,
            'error' => $e?->getMessage(),
        ]);
    }
}
