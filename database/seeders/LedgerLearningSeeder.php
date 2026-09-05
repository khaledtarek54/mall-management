<?php

namespace Database\Seeders;

use App\Models\BankAccount;
use App\Models\Charge;
use App\Models\CreditNote;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\Unit;
use App\Services\Accounting\LedgerPoster;
use App\Services\CreditNoteService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * A SET OF BOOKS SMALL ENOUGH TO CHECK IN YOUR HEAD.
 *
 * **Why this exists.** There was nothing between the two datasets we ship. `DemoSeeder` posts a mall
 * mid-life — 703 journal entries on the box this was written against — so a trial balance is a wall
 * of figures nobody can verify; you can only believe it. `LearningSeeder` posts **nothing**, which is
 * right for learning to lease and useless for learning the ledger, because an empty trial balance
 * teaches nothing either. This seeds **five documents** on top of the empty mall, chosen so that
 * every number on every statement can be added up by hand.
 *
 * **The books it writes**, in order — the whole point is that you can follow them:
 *
 *   1. Invoice A — rent 10,000, VAT-exempt        Dr Receivables 10,000 / Cr Rent revenue 10,000
 *   2. Invoice B — service charge 5,000 + 14% VAT Dr Receivables  5,700 / Cr Service revenue 5,000
 *                                                                        / Cr VAT payable      700
 *   3. Payment — 10,000, settles invoice A        Dr Bank        10,000 / Cr Receivables    10,000
 *   4. Credit note — 2,000 against invoice B,     Dr Sales returns    2,000 / Cr Receivables  2,000
 *      of which only 1,000 is APPLIED
 *   5. Expense — cleaning 3,000, paid from bank   Dr Cleaning expense 3,000 / Cr Bank        3,000
 *
 * Which gives a trial balance of seven lines that balances at **15,700** each side:
 *
 *   Bank              7,000 Dr     (10,000 in − 3,000 out)
 *   Receivables       3,700 Dr     (10,000 + 5,700 − 10,000 − 2,000)
 *   Cleaning expense  3,000 Dr
 *   Sales returns     2,000 Dr     ← contra-revenue: a debit that SITS AMONG the revenue accounts
 *   Rent revenue                  10,000 Cr
 *   Service revenue                5,000 Cr
 *   VAT payable                      700 Cr
 *
 * **The sales-returns line is worth a second look, and it corrected me while I was writing this.**
 * A credit note does NOT reduce the revenue account it credits — it debits a contra-revenue account
 * of its own, so the books keep saying the service charge earned 5,000 and separately say 2,000 of
 * it was given back. That is what lets anyone ask "how much did we credit back this year", which a
 * netted-off figure can never answer, and it is what Yardi does too.
 *
 * **Document 4 is the one worth the trouble.** A credit note credits Receivables IN FULL the moment
 * it is ISSUED — Yardi posts a credit memo the same way — and stands against the tenant until it is
 * applied. So the tenant ledger says invoice B is owed 4,700 while the ledger's receivables account
 * says 3,700, and BOTH are right: the 1,000 difference is the unapplied half of the credit note.
 * That is the single reconciling item every accountant meets in their first month-end, and a dataset
 * without one teaches that AR always agrees with the invoice list, which is false.
 *
 * **Every entry is posted through `LedgerPoster::sync()`** — the same path the scheduled sweep takes
 * — never by writing journal rows directly. A seeder that hand-wrote its entries would teach a
 * ledger that cannot drift, and drift is most of what the general-ledger module is about.
 *
 * **Run it:**
 *   php artisan migrate:fresh --seed --seeder='Database\Seeders\LedgerLearningSeeder'
 *
 * Then read `/admin/trial-balance` and add the column up yourself. It is the last time you will be
 * able to.
 */
class LedgerLearningSeeder extends Seeder
{
    /** The month everything is dated into, so one period holds the whole story. */
    private const PERIOD = '2026-08-01';

    public function run(): void
    {
        // The empty mall first — reference data, one property, vacant units, three tenants. This
        // seeder only ever ADDS the five documents on top of it.
        $this->call(LearningSeeder::class);

        $tenant = Tenant::orderBy('id')->firstOrFail();
        $unit = Unit::orderBy('id')->firstOrFail();
        $period = Carbon::parse(self::PERIOD);

        $lease = $this->lease($tenant, $unit, $period);

        $invoiceA = $this->invoice($lease, $period, 'A', [
            ['type' => 'base_rent', 'description' => 'Rent — August 2026', 'amount' => 10000, 'vat_rate' => 0],
        ]);

        $invoiceB = $this->invoice($lease, $period, 'B', [
            ['type' => 'service_charge', 'description' => 'Service charge — August 2026', 'amount' => 5000, 'vat_rate' => 14],
        ]);

        $this->settle($invoiceA, $period);
        $this->creditPartly($invoiceB, $period);
        $this->spend($lease, $period);

        $this->command?->info('  Five documents posted. Trial balance: 15,700 each side.');
    }

    /** One lease, so the invoices have a debtor and a property. */
    private function lease(Tenant $tenant, Unit $unit, Carbon $period): Lease
    {
        $lease = Lease::create([
            'reference' => 'LSE-LEARN-0001',
            'unit_id' => $unit->id,
            'tenant_id' => $tenant->id,
            'status' => 'active',
            'commencement_date' => $period->copy()->startOfYear(),
            'expiry_date' => $period->copy()->startOfYear()->addYears(3)->subDay(),
            'term_months' => 36,
            'base_rent_monthly' => 10000,
            'service_charge_monthly' => 5000,
            'currency' => 'EGP',
            'payment_terms_days' => 7,
        ]);

        $lease->units()->syncWithoutDetaching([$unit->id]);

        // A rent charge so the lease reads as a real one on screen; it bills nothing here, because
        // the two invoices below are written deliberately rather than generated.
        Charge::create([
            'lease_id' => $lease->id,
            'name' => 'Monthly rent',
            'type' => 'base_rent',
            'amount' => 10000,
            'frequency' => 'monthly',
            'start_date' => $period->copy()->startOfYear(),
        ]);

        return $lease;
    }

    /**
     * An invoice of exactly the lines given — round figures, one line each, so the entry it posts
     * can be read off the invoice without arithmetic.
     *
     * @param  array<int, array{type:string, description:string, amount:float|int, vat_rate:float|int}>  $lines
     */
    private function invoice(Lease $lease, Carbon $period, string $label, array $lines): Invoice
    {
        $subtotal = 0.0;
        $vat = 0.0;

        foreach ($lines as $line) {
            $subtotal += (float) $line['amount'];
            $vat += round((float) $line['amount'] * (float) $line['vat_rate'] / 100, 2);
        }

        $invoice = Invoice::create([
            'lease_id' => $lease->id,
            'tenant_id' => $lease->tenant_id,
            'asset_id' => $lease->units()->first()?->asset_id ?? $lease->unit?->asset_id,
            'status' => 'issued',
            'issue_date' => $period,
            'due_date' => $period->copy()->addDays(7),
            'period_start' => $period,
            'period_end' => $period->copy()->endOfMonth(),
            'subtotal' => $subtotal,
            'vat_amount' => $vat,
            'total' => $subtotal + $vat,
            'paid_amount' => 0,
            'balance' => $subtotal + $vat,
            'currency' => 'EGP',
            'notes' => "Teaching invoice {$label}",
        ]);

        foreach ($lines as $line) {
            $lineVat = round((float) $line['amount'] * (float) $line['vat_rate'] / 100, 2);

            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'type' => $line['type'],
                'description' => $line['description'],
                'amount' => $line['amount'],
                'vat_rate' => $line['vat_rate'],
                'vat_amount' => $lineVat,
                'total' => (float) $line['amount'] + $lineVat,
            ]);
        }

        app(LedgerPoster::class)->sync($invoice->fresh());

        return $invoice->fresh();
    }

    /** Cash in, settling one invoice in full. */
    private function settle(Invoice $invoice, Carbon $period): void
    {
        $payment = Payment::create([
            'tenant_id' => $invoice->tenant_id,
            'amount' => $invoice->total,
            'method' => 'bank_transfer',
            'status' => 'captured',
            'payment_date' => $period->copy()->addDays(3),
            'currency' => 'EGP',
            // **Stated, because a seeder has no property in scope to derive it from.**
            // `RecordsBankAccount` fills this in from `TenantScope::currentAssetId()` for a document
            // with no `asset_id` of its own — and `payments` has none, since a receipt's books
            // dimension comes from the invoices it settles, which do not exist yet at `creating`.
            // In the panel that resolves to the mall the operator is working in; in a seeder,
            // console job or API call there is nobody to ask, so the receipt fell to the generic
            // `bank` POSTING ROLE while the cleaning expense beside it — which does carry an
            // `asset_id` — used the mall's own account. One mall, one bank, two chart accounts, and
            // a trial balance of eight lines instead of seven.
            //
            // Through `defaultFor()`, which is the ladder the PANEL's own picker defaults from —
            // `rather than a second rule`, exactly as `DemoSeeder::demoBankAccountForPurpose()` puts
            // it. A bare `where('asset_id', …)->first()` would work today with one account and pick
            // an arbitrary row the day a subclass mints a second (`ValPlazaSeeder extends
            // LearningSeeder`), which would make the teaching set teach something false.
            //
            // Thrown, never null: if `LearningSeeder` stops minting the account this must break
            // loudly rather than silently reinstate the split books it was written to end.
            'bank_account_id' => BankAccount::defaultFor($invoice->asset_id, Payment::bankAccountPurpose())?->getKey()
                ?? throw new RuntimeException('LedgerLearningSeeder needs the mall bank account LearningSeeder mints.'),
        ]);

        $payment->invoices()->attach($invoice->id, ['allocated_amount' => $invoice->total]);
        $invoice->recomputeTotals();

        app(LedgerPoster::class)->sync($payment->fresh());
    }

    /**
     * A credit note of 2,000, of which 1,000 is applied — the reconciling item.
     *
     * The unapplied half is the point: it credits the ledger's receivables in full on issue and
     * stands against the tenant, so the tenant ledger and the AR control account disagree by 1,000
     * and both are correct. Every teaching set needs one, or it teaches that they always agree.
     */
    private function creditPartly(Invoice $invoice, Carbon $period): void
    {
        $note = CreditNote::create([
            'tenant_id' => $invoice->tenant_id,
            'invoice_id' => $invoice->id,
            'asset_id' => $invoice->asset_id,
            'status' => 'draft',
            'issue_date' => $period->copy()->addDays(5),
            'subtotal' => 2000,
            'vat_amount' => 0,
            'total' => 2000,
            'applied_amount' => 0,
            'balance' => 2000,
            'reason' => 'adjustment',
            'notes' => 'Teaching credit note — half applied, half standing',
        ]);

        $note->items()->create([
            'description' => 'Service charge adjustment',
            'amount' => 2000,
            'vat_rate' => 0,
            'vat_amount' => 0,
            'total' => 2000,
        ]);

        // Through the REAL services, never by writing the columns. Setting `credit_applied_amount`
        // by hand looked correct and did nothing: `recomputeTotals()` DERIVES that column from the
        // applications, so the hand-written value was overwritten on the next save and the note and
        // the invoice disagreed about the same money. A seeder that writes a state no service can
        // produce is teaching a state the app cannot reach.
        $service = app(CreditNoteService::class);
        $service->issue($note);
        $service->applyToInvoice($note->fresh(), $invoice->fresh(), 1000);

        app(LedgerPoster::class)->sync($note->fresh());
    }

    /** One cost, paid from the bank, so the income statement has both sides. */
    private function spend(Lease $lease, Carbon $period): void
    {
        $expense = Expense::create([
            'asset_id' => $lease->units()->first()?->asset_id ?? $lease->unit?->asset_id,
            'category' => 'cleaning_security',
            'description' => 'Common-area cleaning — August 2026',
            'amount' => 3000,
            'vat_amount' => 0,
            'total' => 3000,
            'expense_date' => $period->copy()->addDays(10),
            'paid_from' => 'bank_transfer',
            'status' => 'recorded',
        ]);

        app(LedgerPoster::class)->sync($expense->fresh());
    }
}
