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
        //
        // `writeOffs` is eager-loaded because every figure below is COLLECTABLE, and
        // `Invoice::collectableBalance()` prefers a loaded relation over an aggregate per row.
        $openInvoices = $tenant->invoices()
            ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
            ->with('writeOffs')
            ->get(['id', 'balance', 'due_date', 'status']);

        // **Collectable, not raw `balance` — and the three figures here must agree with each
        // other.** `outstandingBalance()` was taught to net write-offs; these two were not, so a
        // partly forgiven tenant could be handed `overdue` GREATER than `outstanding`, and an
        // `open_count` that included an invoice the operator had written off in full. A write-off
        // deliberately leaves `balance` standing (it is not a settlement channel), which is exactly
        // why every collections read has to say `collectableBalance()` out loud.
        $overdue = (float) $openInvoices
            ->filter(fn ($inv) => $inv->collectableBalance() > 0 && $inv->due_date && $inv->due_date->isPast())
            ->sum(fn ($inv) => $inv->collectableBalance());

        return $this->ok([
            'outstanding' => round($tenant->outstandingBalance(), 2),
            'overdue' => round($overdue, 2),
            // Cast the count/bool explicitly so the generated spec publishes
            // integer/boolean rather than falling back to `string` — the client
            // decodes against the spec.
            'open_count' => $openInvoices->filter(fn ($inv) => $inv->collectableBalance() > 0)->count(),
            // **The tenant's own money, which this API had never mentioned.** Two different credits
            // exist and the app was told about one: a CREDIT NOTE is a document the operator issued,
            // while this is cash the tenant has already PAID that is not yet applied to an invoice —
            // a received payment's unallocated remainder, sitting on the books as unearned revenue.
            // The portal's AccountBalance widget has always shown it, four admin surfaces read it,
            // and `ApplyTenantCreditService` spends it as one of the four settlement channels. To a
            // tenant who overpaid, it simply looked lost — and then an invoice was silently
            // part-settled from it with nothing in the payment history to explain why.
            'credit_on_account' => round($tenant->creditBalance(), 2),
            'currency' => 'EGP',
            'is_delinquent' => (bool) $tenant->isDelinquent(),
        ]);
    }
}
