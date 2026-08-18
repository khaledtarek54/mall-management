<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Lease;
use App\Support\PostingDate;
use App\Support\Vat;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Bill a lease's outstanding security deposit as a charge on the tenant's ledger — Voyager's model.
 *
 * **Why this exists.** A deposit used to have no document at all: it existed only as a
 * `DepositTransaction` an operator recorded AFTER money arrived, so nothing in the system ever asked
 * the tenant to pay it. The portal had to tell them to make a bank transfer and quote a reference.
 * Raised by an operator as "the client doesn't know how he should pay" — and he was right, because
 * there was nothing to pay.
 *
 * Billed, it behaves like every other charge: it ages, it appears on the statement, it is chased by
 * the collections screen, and it can be paid by card through the same rail as rent.
 *
 * **The GL is what makes it a deposit rather than income.** The `security_deposit` charge code posts
 * to `deposits_held`, a LIABILITY, so this invoice is `Dr AR / Cr Deposits Held` and the tenant's
 * payment is `Dr Bank / Cr AR`. The pair nets to exactly what a direct receipt posts in one step —
 * no double count, and no second billing path.
 *
 * **It bills the SHORTFALL, never the contractual figure.** Billing 180,000 to a tenant who has
 * already paid 150,000 is how a landlord ends up holding twice the deposit and owing it back.
 */
class BillSecurityDepositService
{
    public function __construct(private IssueInvoiceService $invoices) {}

    public function bill(Lease $lease, array $data = []): Invoice
    {
        $issueDate = CarbonImmutable::parse($data['issue_date'] ?? CarbonImmutable::now());

        // The same refusal every originating service makes: a document whose GL entry could never
        // post is worse than no document, because the operator sees "Saved" and the books do not move.
        PostingDate::assertOpen($issueDate);

        return DB::transaction(function () use ($lease, $issueDate, $data) {
            // Locked and re-read INSIDE the transaction: the shortfall is a check-then-act over
            // receipts and settled billings, so two operators (or a double-click) would each read
            // the same outstanding figure and each raise an invoice for it.
            $locked = Lease::query()->lockForUpdate()->findOrFail($lease->getKey());

            $outstanding = $locked->depositShortfall();

            if ($outstanding <= 0) {
                throw new DomainException(__('admin.deposits.nothing_outstanding', [
                    'held' => number_format($locked->depositHeld(), 2),
                ]));
            }

            $amount = round((float) ($data['amount'] ?? $outstanding), 2);

            if ($amount <= 0 || $amount > $outstanding + 0.005) {
                throw new DomainException(__('admin.deposits.exceeds_outstanding', [
                    'max' => number_format($outstanding, 2),
                ]));
            }

            // Exempt, and asked rather than assumed: a deposit is a security, not consideration for
            // a supply. `Vat::rateForType()` reads the accountant's catalogue, so if that ruling
            // ever changes the invoice follows it without a deploy.
            $rate = Vat::rateForType('security_deposit', $issueDate);
            $vat = round($amount * $rate / 100, 2);

            return $this->invoices->issue(
                agreement: $locked,
                items: [[
                    'type' => 'security_deposit',
                    'description' => __('admin.deposits.invoice_line', ['ref' => $locked->reference]),
                    'amount' => $amount,
                    'vat_rate' => $rate,
                    'vat_amount' => $vat,
                    'total' => round($amount + $vat, 2),
                ]],
                issueDate: $issueDate,
                // A deposit covers the whole tenancy, not a month of it. Dating the period to the
                // lease term keeps it out of any month's revenue reading and stops the trailing
                // proration and unearned-credit rules — both keyed on the period — from treating it
                // as time-apportioned rent they should claw back on termination.
                periodStart: $locked->commencement_date ?? $issueDate,
                periodEnd: $locked->expiry_date ?? $issueDate,
            );
        });
    }
}
