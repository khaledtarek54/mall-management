<?php

namespace App\Services;

use App\Contracts\BillableAgreement;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Support\PostingDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Raise an invoice from its lines — **the** place an AR document is born.
 *
 * Eight services used to hand-build the identical header: `MonthlyBilling`, `BillMeterReading`,
 * `BillViolationFine`, `LateFee`, `PercentageRentCalculation`, `CamReconciliation` (×2) and
 * `BillBouncedChequeFee`. Every one of them re-derived subtotal/VAT/total from lines it was about
 * to write out longhand anyway, and every one of them hand-seeded `paid_amount => 0` and
 * `balance => $total` — restating by hand the two fields `Invoice::recomputeTotals()` owns.
 *
 * That is eight chances to seed the AR invariant wrong, and eight edits every time a new column
 * joins the header. The rule is one rule, so it is written once here.
 *
 * **What this deliberately does NOT do.** It does not re-implement the header-follows-items rule:
 * `InvoiceItem::saved` already calls `Invoice::syncTotalsFromItems()`, so the header is re-derived
 * the moment the lines land, whatever this service wrote. The totals are still computed and passed
 * on the CREATE, not left to that hook, because the first persisted state of an AR document should
 * be the true one — `LedgerRealtimeSync` dispatches on `saved`, and an invoice born at zero is an
 * invoice that was momentarily wrong on the books. Same numbers, same write sequence, one copy.
 *
 * @see Invoice::recomputeTotals()      the four settlement channels — never seed those by hand
 * @see Invoice::syncTotalsFromItems()  the header-follows-items rule this service agrees with
 */
class IssueInvoiceService
{
    /**
     * Create an issued invoice and its lines, in that order.
     *
     * `$items` are `InvoiceItem` attribute arrays without `invoice_id` — this stamps it. The line's
     * `tax_code` is defaulted at the model layer from the charge code, so callers do not pass it.
     *
     * `$agreement` is a `Lease` today and a unit ownership in phase 2 — this service never asks
     * which, and `invoiceLinkAttributes()` is how the agreement stamps its own FK on the invoice.
     *
     * `$dueDate` defaults to the issue date plus the agreement's payment terms, which is what seven
     * of the eight callers computed by hand; the monthly run passes its own (it anchors the due date
     * to the later of the issue date and today, so a back-filled run cannot be born overdue).
     *
     * `$tenantId` defaults to the agreement's party. The three callers that bill from a source
     * document — a violation, a bounced cheque, an overdue invoice — pass that document's tenant
     * explicitly, because the debtor is stated on the document rather than inferred from the lease.
     *
     * `$currency` defaults to the agreement's. `LateFeeService` passes the OVERDUE INVOICE's instead:
     * a penalty is denominated in the currency of the debt it penalises, and the two can only differ
     * if the lease was re-denominated after that invoice was issued — in which case the invoice is
     * right and the lease is not.
     *
     * @param  array<int, array<string, mixed>>  $items  at least one; an invoice with no lines is a bug, not a document
     */
    public function issue(
        BillableAgreement $agreement,
        array $items,
        \DateTimeInterface|string $issueDate,
        \DateTimeInterface|string $periodStart,
        \DateTimeInterface|string $periodEnd,
        \DateTimeInterface|string|null $dueDate = null,
        ?int $tenantId = null,
        ?string $currency = null,
        string $status = 'issued',
    ): Invoice {
        if ($items === []) {
            // Not a DomainException: no operator can reach this. An empty line set is a caller bug,
            // and a zero-total AR document would age, dun and post to the GL as if it were real.
            throw new \InvalidArgumentException('An invoice must be raised with at least one line item.');
        }

        $subtotal = round(array_sum(array_map(static fn (array $i): float => (float) ($i['amount'] ?? 0), $items)), 2);
        $vatAmount = round(array_sum(array_map(static fn (array $i): float => (float) ($i['vat_amount'] ?? 0), $items)), 2);
        $total = round($subtotal + $vatAmount, 2);

        $invoice = Invoice::create($agreement->invoiceLinkAttributes() + [
            // The property, from the agreement that knows it — so the billing run never pays for the
            // lookup `Invoice::creating` would otherwise do, and an ownership (which has no lease to
            // infer through) answers for itself.
            'asset_id' => $agreement->assetId(),
            'tenant_id' => $tenantId ?? $agreement->billingTenantId(),
            'status' => $status,
            'issue_date' => $issueDate,
            'due_date' => $dueDate ?? $this->defaultDueDate($agreement, $issueDate),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'subtotal' => $subtotal,
            'vat_amount' => $vatAmount,
            'total' => $total,
            'paid_amount' => 0,
            'balance' => $total,
            'currency' => $currency ?? $agreement->billingCurrency(),
        ]);

        foreach ($items as $item) {
            InvoiceItem::create($item + ['invoice_id' => $invoice->id]);
        }

        return $invoice;
    }

    /**
     * Issue date + the lease's payment terms.
     *
     * `CarbonImmutable::parse()` rather than the caller's own instance: several callers hold a
     * mutable `now()` and used `->copy()->addDays()` precisely so the shared instance would not
     * shift under them. Parsing here makes that impossible to get wrong.
     */
    private function defaultDueDate(BillableAgreement $agreement, \DateTimeInterface|string $issueDate): CarbonImmutable
    {
        return CarbonImmutable::parse(
            $issueDate instanceof \DateTimeInterface ? Carbon::instance($issueDate) : $issueDate
        )->addDays($agreement->paymentTermsDays());
    }

    /**
     * Issue an EXISTING draft — the panel's door, since SW-240 made issuing an ACT.
     *
     * `issue()` above is where a generated invoice is BORN issued; this is where a hand-raised
     * draft becomes one. It used to happen by picking `issued` in the form's status Select, which
     * meant the most consequential transition an invoice has — the one that puts it in front of
     * the tenant, on the books and in the GL — rode on an ordinary field save with no
     * confirmation, while the credit note beside it had an Issue button, a permission and a
     * service. One rule across both AR documents now.
     *
     * The posting-date assert is belt over the model's braces, deliberately: `GuardsPostingDate`
     * is `isDirty(issue_date)`-only and `SealedPeriod` catches the no-entry-yet status flip, but
     * the house rule is that the SERVICE guards, and this is the sentence the operator reads on
     * the field rather than a toast after the fact.
     */
    public function raise(Invoice $invoice): Invoice
    {
        if ($invoice->status !== 'draft') {
            throw new \DomainException(__('admin.errors.issue_invoice_not_draft'));
        }

        // The docblock on issue() says it: an invoice with no lines is a bug, not a document. A
        // draft is precisely an invoice WITH lines that has not been raised (SW-215) — one with
        // none has nothing to post and nothing to bill.
        if (! $invoice->items()->exists()) {
            throw new \DomainException(__('admin.errors.issue_invoice_no_lines'));
        }

        PostingDate::assertOpen($invoice->issue_date, __('admin.fields.issue_date'));

        // A plain save, never quiet — `LedgerRealtimeSync` posts the entry from the `saved`
        // event — and then `recomputeTotals()` EXPLICITLY, because the save alone does not run it:
        // the adversarial review caught this service's first comment claiming the ladder ran when
        // nothing here called it. The old Select door reached the ladder as a side effect (a full
        // form save re-saves the items repeater → `InvoiceItem::saved` → `syncTotalsFromItems()`),
        // so without this call a past-due draft issued to `issued` and sat there until the nightly
        // overdue scan — a status the operator reads as current for up to a day. With it, a
        // past-due draft issues straight to `overdue`, which is the truth.
        $invoice->status = 'issued';
        $invoice->save();
        $invoice->recomputeTotals();

        return $invoice->refresh();
    }
}
