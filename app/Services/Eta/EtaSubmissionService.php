<?php

namespace App\Services\Eta;

use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Orchestrates ETA submission for a single invoice:
 *   build JSON -> POST via client -> persist submission id + status + response.
 *
 * Idempotent: re-submitting an already-Valid invoice is a no-op.
 */
class EtaSubmissionService
{
    public function __construct(
        private readonly EtaJsonBuilder $builder,
        private readonly EtaApiClient $client,
    ) {}

    public function submit(Invoice $invoice): Invoice
    {
        if ($invoice->eta_status === 'valid') {
            return $invoice;
        }

        return DB::transaction(function () use ($invoice) {
            $document = $this->builder->build($invoice);

            try {
                $response = $this->client->submitDocument($document);
            } catch (Throwable $e) {
                $invoice->update([
                    'eta_status' => 'rejected',
                    'eta_response' => ['error' => $e->getMessage()],
                    'eta_submitted_at' => now(),
                ]);

                return $invoice->refresh();
            }

            $accepted = $response['acceptedDocuments'][0] ?? null;
            $rejected = $response['rejectedDocuments'][0] ?? null;

            if ($accepted) {
                $invoice->update([
                    'eta_submission_id' => $response['submissionId'] ?? $accepted['uuid'] ?? null,
                    'eta_long_id' => $accepted['longId'] ?? null,
                    'eta_status' => strtolower($accepted['documentStatus'] ?? 'submitted'),
                    'eta_submitted_at' => now(),
                    'eta_response' => $response,
                ]);
            } elseif ($rejected) {
                $invoice->update([
                    'eta_status' => 'rejected',
                    'eta_submitted_at' => now(),
                    'eta_response' => $response,
                ]);
            } else {
                $invoice->update([
                    'eta_status' => 'submitted',
                    'eta_submitted_at' => now(),
                    'eta_response' => $response,
                ]);
            }

            return $invoice->refresh();
        });
    }
}
