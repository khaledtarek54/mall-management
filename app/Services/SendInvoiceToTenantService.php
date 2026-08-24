<?php

namespace App\Services;

use App\Models\Invoice;
use App\Notifications\InvoiceIssuedNotification;
use App\Support\OpsLog;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Send (or re-send) an invoice to its tenant — UX5-09.
 *
 * **An invoice raised by hand notified nobody.** `InvoiceIssuedNotification` was dispatched from one
 * place, `MonthlyBillingService`, so the monthly run emailed its invoices and every other path did
 * not: a violation fine, a CAM recovery, a percentage-rent overage, an NSF fee, a one-off invoice an
 * operator typed — all of them reached the tenant only if the tenant happened to open the portal.
 * And there was no send or re-send action anywhere on the invoice, so the daily *"I never received
 * it"* call ended with somebody downloading the PDF and attaching it to their own email by hand.
 *
 * This is the seam both paths go through, so there is one answer to "what does the tenant get" —
 * the same notification, with its PDF attachment and its portal bell entry, in the tenant's own
 * language.
 *
 * **A DRAFT is never sent.** A draft is not a document (the tenant cannot see one anywhere else
 * either), and mailing one would put a figure in front of a tenant that the operator has not
 * committed to. Refused rather than silently skipped, because the operator pressed a button.
 *
 * **The stamp is the point of the re-send.** `tenant_notified_at` records when the tenant was last
 * sent this invoice, which is the fact the "I never received it" conversation turns on — and it is
 * why re-sending is allowed at all rather than being a one-shot: the second send is the answer to
 * that call, not a mistake to guard against.
 */
class SendInvoiceToTenantService
{
    /**
     * @return bool  true when the tenant was notified; false when there is nobody to notify.
     *
     * @throws \DomainException  when the invoice is not a document the tenant may see.
     */
    public function send(Invoice $invoice): bool
    {
        if (! $invoice->isVisibleToTenant()) {
            throw new \DomainException(__('admin.errors.invoice_not_sendable'));
        }

        $tenant = $invoice->tenant;

        if (! $tenant) {
            return false;
        }

        try {
            $tenant->notifyPortal(new InvoiceIssuedNotification($invoice));
        } catch (Throwable $e) {
            // Reported, never swallowed: the operator pressed send and is entitled to know it did
            // not go. Re-thrown as a refusal so it renders as a message rather than the 500 page.
            OpsLog::warning('Invoice send to tenant failed', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            throw new \DomainException(__('admin.errors.invoice_send_failed'));
        }

        // Stamped OUTSIDE the notification: `notifyPortal` queues delivery, so a stamp written
        // inside a transaction that later rolled back would claim a send that had already left.
        DB::transaction(function () use ($invoice) {
            Invoice::query()->whereKey($invoice->getKey())->update(['tenant_notified_at' => now()]);
        });

        $invoice->forceFill(['tenant_notified_at' => now()]);

        return true;
    }
}
