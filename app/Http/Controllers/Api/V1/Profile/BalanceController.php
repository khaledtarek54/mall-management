<?php

namespace App\Http\Controllers\Api\V1\Profile;

use App\Http\Controllers\Api\V1\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/me/balance — the Account Balance widget's data: net outstanding,
 * overdue portion, and open invoice count. Backs the mobile home screen.
 */
class BalanceController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        $tenant = $request->user();

        // Net AR (open invoice balances minus issued credit notes) — the same
        // figure the portal's AccountBalance widget shows.
        $openInvoices = $tenant->invoices()
            ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
            ->get(['id', 'balance', 'due_date', 'status']);

        $overdue = (float) $openInvoices
            ->filter(fn ($inv) => $inv->balance > 0 && $inv->due_date && $inv->due_date->isPast())
            ->sum('balance');

        return $this->ok([
            'outstanding' => round($tenant->outstandingBalance(), 2),
            'overdue' => round($overdue, 2),
            // Cast the count/bool explicitly so the generated spec publishes
            // integer/boolean rather than falling back to `string` — the client
            // decodes against the spec.
            'open_count' => (int) $openInvoices->where('balance', '>', 0)->count(),
            'currency' => 'EGP',
            'is_delinquent' => (bool) $tenant->isDelinquent(),
        ]);
    }
}
