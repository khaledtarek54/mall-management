<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Support\InvoiceItemSettlement;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\Auth;

/**
 * Flag one invoice line as disputed, and lift it again (story MF-07).
 *
 * **What it changes and what it deliberately does not.** A disputed line's OUTSTANDING amount comes
 * out of the late-fee base, so the sweep stops charging a penalty on money nobody has agreed is
 * owed. It does not reduce the invoice, it does not touch the GL, and it does not move
 * `invoices.balance` — the debt is still claimed, it is simply not yet chargeable. Anything else
 * would be writing off a debt through a flag, which is `WriteOffInvoiceService`'s job and a
 * different decision with a different authority.
 *
 * **The header status is left alone on purpose.** `invoices.status` already has a `disputed` value,
 * and it is the wrong tool: an invoice is rarely disputed in full. The argument is about the service
 * charge while the rent on the same document is undisputed and collectable, so marking the header
 * stops chasing money nobody is arguing about.
 *
 * **A reason is required.** This flag suppresses a fee; "disputed" with no stated reason is a note to
 * nobody, and the first question anyone asks three months later is why.
 */
class DisputeInvoiceItemService
{
    public function dispute(InvoiceItem $item, string $reason): InvoiceItem
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException(__('admin.errors.dispute_reason_required'));
        }

        $invoice = $item->invoice;

        // A document that left the books claims nothing, so there is nothing to argue about — and a
        // dispute recorded against it would sit in the reports forever with no way to resolve it.
        if (in_array($invoice->status, ['cancelled', 'written_off'], true)) {
            throw new DomainException(__('admin.errors.dispute_invoice_not_open'));
        }

        // Disputing a line that is already settled claims an argument about money that has been
        // paid. It is almost always the wrong line, and it would suppress a fee for no reason.
        if ($this->outstandingFor($item) <= 0) {
            throw new DomainException(__('admin.errors.dispute_line_already_settled'));
        }

        $item->forceFill([
            'disputed_at' => CarbonImmutable::now(),
            'disputed_reason' => $reason,
            'disputed_by_id' => Auth::id(),
        ])->save();

        return $item->refresh();
    }

    /** The argument is over — the line is chargeable again from now on. */
    public function resolve(InvoiceItem $item): InvoiceItem
    {
        if (! $item->isDisputed()) {
            throw new DomainException(__('admin.errors.dispute_not_disputed'));
        }

        $item->forceFill([
            'disputed_at' => null,
            'disputed_reason' => null,
            'disputed_by_id' => null,
        ])->save();

        return $item->refresh();
    }

    /**
     * How much of an invoice is under dispute — the amount the late-fee base must exclude.
     *
     * Read from {@see InvoiceItemSettlement}, not from the line totals: a line that has been
     * part-paid is only disputed for what is still owed on it, and using the gross figure would
     * suppress a fee on money that was already settled.
     */
    public static function disputedOutstanding(Invoice $invoice): float
    {
        return round((float) InvoiceItemSettlement::for($invoice)
            ->filter(fn (array $line) => $line['item']->isDisputed())
            ->sum('outstanding'), 2);
    }

    private function outstandingFor(InvoiceItem $item): float
    {
        return (float) (InvoiceItemSettlement::for($item->invoice)
            ->firstWhere(fn (array $line) => $line['item']->id === $item->id)['outstanding'] ?? 0.0);
    }
}
