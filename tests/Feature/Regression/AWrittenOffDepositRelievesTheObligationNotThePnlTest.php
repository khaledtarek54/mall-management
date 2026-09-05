<?php

/*
|--------------------------------------------------------------------------
| Writing off a deposit invoice relieves the OBLIGATION, not the P&L (SW-210)
|--------------------------------------------------------------------------
| **A write-off reverses whatever the line originally CREDITED.** For a revenue line that is bad-debt
| expense — you recognised the income, and you now accept you will not collect it, which is what a
| write-off means. A `security_deposit` line credited `deposits_held`, a LIABILITY
| (`InvoiceJournalizer::REVENUE_ROLE:53`), so no revenue was ever recognised against it.
|
| `InvoiceWriteOffJournalizer` debited `bad_debt_expense` for the whole amount whatever the line was.
| Two things followed, both wrong and in opposite directions:
|
|   - the P&L took an expense for income it never recognised, and
|   - `deposits_held` stayed credited, so the balance sheet went on saying the operator owes the
|     tenant a refund for money the tenant never paid.
|
| That is also why `deposits_tie_out` goes red for any written-off deposit invoice, which turns
| `atriom:preflight` red and blocks the next deploy for a reason nothing to do with the deploy.
|
| The row was filed as *"ask the accountant"*. It is not a matter of taste: no standard permits an
| expense against unrecognised revenue, and Yardi reverses a written-off deposit charge against the
| deposit liability. Recorded as a decision taken on that basis rather than left open.
|
| The ATTRIBUTION is deliberately not a new rule — `DepositBilling` already states that a write-off
| reaches the deposit line only once every other outstanding line is exhausted (understating the
| claim re-opens the double ask `BillSecurityDepositService` exists to prevent), and this reads the
| same one, so the ledger and the lease page cannot disagree about the same write-off.
|
| **The split is FROZEN on the row, and that is the load-bearing half.** The first version of this
| fix derived it in the journalizer from the invoice's live settlement, and an adversarial review
| showed what that costs: a partial write-off leaves the invoice LIVE (`settled_short` is a shipped
| reason — "forgive part, the tenant pays the rest" is the canonical case), so a payment arriving a
| month later moves the split, and `LedgerPoster::matches()` then voids and re-posts an entry at its
| ORIGINAL date. If that month has closed the re-post throws inside a sync that only logs, and
| `gl_in_sync` reports drift for ever with no operator action available — SW-236, reached through
| another door. A write-off's entry could not drift before SW-210 and must not start.
|
| The change is therefore PROSPECTIVE, which is Yardi's rule for a classification change and this
| system's rule wherever origination and history disagree: existing rows carry 0.00 and post exactly
| what they posted before, so nothing re-posts and no currently-green install turns red.
*/

use App\Models\ChargeCode;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\Payment;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerPoster;
use App\Services\WriteOffInvoiceService;
use App\Support\DepositBilling;
use App\Support\DepositHoldings;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    $this->seed(RolesPermissionsSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    $this->lease = makeLease(makeUnit($this->asset), null, ['security_deposit' => 100000]);
});

/** The debit side of the entry this write-off posted, keyed by posting role. */
function writeOffDebits(Invoice $invoice): array
{
    $writeOff = $invoice->writeOffs()->latest('id')->firstOrFail();

    // The real service raised the row; the SWEEP is what posts it. Both, because a GL test that
    // only calls the poster proves the journalizer's arithmetic and nothing about the path.
    app(LedgerPoster::class)->sync($writeOff);

    $entry = JournalEntry::where('source_type', $writeOff->getMorphClass())
        ->where('source_id', $writeOff->id)
        ->whereNull('voided_at')
        ->with('lines')
        ->firstOrFail();

    $roles = [];

    foreach ($entry->lines as $line) {
        if ((float) $line->debit <= 0) {
            continue;
        }

        $account = LedgerAccount::find($line->ledger_account_id);
        $roles[$account?->code ?? (string) $line->ledger_account_id] = round((float) $line->debit, 2);
    }

    return $roles;
}

/** An issued invoice whose only line is a security deposit. */
function depositInvoice(float $amount = 100000): Invoice
{
    $invoice = makeInvoice(test()->lease, [
        'status' => 'issued', 'subtotal' => $amount, 'vat_amount' => 0,
        'total' => $amount, 'balance' => $amount,
    ]);

    $invoice->items()->create([
        'type' => 'security_deposit', 'description' => 'Security deposit', 'quantity' => 1,
        'unit_price' => $amount, 'amount' => $amount, 'tax_amount' => 0, 'total' => $amount,
    ]);

    $invoice->recomputeTotals();

    return $invoice->fresh();
}

function accountIdForRole(string $role): int
{
    return app(AccountResolver::class)->id($role, test()->asset->id);
}

it('debits the deposit liability, not bad debt, when the written-off line is a deposit', function () {
    $invoice = depositInvoice();

    app(WriteOffInvoiceService::class)->write($invoice, [
        'amount' => 100000,
        'reason' => 'tenant_insolvent',
        'entry_date' => now()->toDateString(),
    ]);

    $deposits = LedgerAccount::find(accountIdForRole('deposits_held'))?->code;
    $badDebt = LedgerAccount::find(accountIdForRole('bad_debt_expense'))?->code;

    $debits = writeOffDebits($invoice->fresh());

    // The obligation is relieved in full; the P&L is untouched. Asserting the ABSENCE of the
    // bad-debt debit as well as the presence of the deposit one, because posting both would still
    // balance against AR and would look correct in a total.
    expect($debits[$deposits] ?? 0.0)->toEqual(100000.0)
        ->and($debits[$badDebt] ?? 0.0)->toEqual(0.0);
});

it('still books bad debt for an ordinary revenue invoice — the untouched case', function () {
    $invoice = makeInvoice($this->lease, [
        'status' => 'issued', 'subtotal' => 50000, 'vat_amount' => 0,
        'total' => 50000, 'balance' => 50000,
    ]);
    $invoice->items()->create([
        'type' => 'base_rent', 'description' => 'Rent', 'quantity' => 1,
        'unit_price' => 50000, 'amount' => 50000, 'tax_amount' => 0, 'total' => 50000,
    ]);
    $invoice->recomputeTotals();

    app(WriteOffInvoiceService::class)->write($invoice->fresh(), [
        'amount' => 50000,
        'reason' => 'tenant_insolvent',
        'entry_date' => now()->toDateString(),
    ]);

    $deposits = LedgerAccount::find(accountIdForRole('deposits_held'))?->code;
    $badDebt = LedgerAccount::find(accountIdForRole('bad_debt_expense'))?->code;

    $debits = writeOffDebits($invoice->fresh());

    // Behaviour-identical to before SW-210 — no deposit line, so no deposit debit, and every
    // install that has never billed a deposit posts exactly what it posted yesterday.
    expect($debits[$badDebt] ?? 0.0)->toEqual(50000.0)
        ->and($debits[$deposits] ?? 0.0)->toEqual(0.0);
});

it('splits a mixed invoice, and reaches the deposit line LAST', function () {
    $invoice = makeInvoice($this->lease, [
        'status' => 'issued', 'subtotal' => 130000, 'vat_amount' => 0,
        'total' => 130000, 'balance' => 130000,
    ]);
    $invoice->items()->create([
        'type' => 'base_rent', 'description' => 'Rent', 'quantity' => 1,
        'unit_price' => 30000, 'amount' => 30000, 'tax_amount' => 0, 'total' => 30000,
    ]);
    $invoice->items()->create([
        'type' => 'security_deposit', 'description' => 'Security deposit', 'quantity' => 1,
        'unit_price' => 100000, 'amount' => 100000, 'tax_amount' => 0, 'total' => 100000,
    ]);
    $invoice->recomputeTotals();

    // 50,000 written off: the 30,000 rent line is exhausted FIRST, so only 20,000 reaches the
    // deposit. Attributing the other way would understate the claim and re-open the double ask.
    app(WriteOffInvoiceService::class)->write($invoice->fresh(), [
        'amount' => 50000,
        'reason' => 'tenant_insolvent',
        'entry_date' => now()->toDateString(),
    ]);

    $deposits = LedgerAccount::find(accountIdForRole('deposits_held'))?->code;
    $badDebt = LedgerAccount::find(accountIdForRole('bad_debt_expense'))?->code;

    $debits = writeOffDebits($invoice->fresh());

    expect($debits[$badDebt] ?? 0.0)->toEqual(30000.0)
        ->and($debits[$deposits] ?? 0.0)->toEqual(20000.0);
});

it('never books a deposit debit for money the tenant actually paid', function () {
    // 60,000 of the deposit really arrived. Only the 40,000 shortfall can be written off, and it is
    // that shortfall — never the amount received — the obligation is relieved by.
    $invoice = depositInvoice();

    $payment = Payment::create([
        'tenant_id' => $invoice->tenant_id,
        'payment_date' => now(),
        'amount' => 60000,
        'method' => 'bank_transfer',
        'status' => 'captured',
    ]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => 60000]);
    $invoice->fresh()->recomputeTotals();

    app(WriteOffInvoiceService::class)->write($invoice->fresh(), [
        'amount' => 40000,
        'reason' => 'tenant_insolvent',
        'entry_date' => now()->toDateString(),
    ]);

    $deposits = LedgerAccount::find(accountIdForRole('deposits_held'))?->code;
    $debits = writeOffDebits($invoice->fresh());

    expect($debits[$deposits] ?? 0.0)->toEqual(40000.0);

    // And the pot still reads the money that genuinely arrived — the write-off relieved the claim,
    // not the holding. `DepositBilling` answering otherwise would refund cash that never came.
    expect(DepositBilling::heldOn($invoice->fresh()))->toEqual(60000.0);
});

it('FREEZES the split, so a later payment cannot restate a posted entry', function () {
    // The finding that reworked this fix. Rent 30,000 + deposit 100,000; write off 50,000 with
    // nothing paid, so 30,000 is bad debt and 20,000 reaches the deposit. Then the tenant pays the
    // rent. A derived split would answer 50,000/0 on the next sweep and move 30,000 out of the P&L
    // retroactively, inside a month that may since have closed.
    $invoice = makeInvoice($this->lease, [
        'status' => 'issued', 'subtotal' => 130000, 'vat_amount' => 0,
        'total' => 130000, 'balance' => 130000,
    ]);
    $invoice->items()->create([
        'type' => 'base_rent', 'description' => 'Rent', 'quantity' => 1,
        'unit_price' => 30000, 'amount' => 30000, 'tax_amount' => 0, 'total' => 30000,
    ]);
    $invoice->items()->create([
        'type' => 'security_deposit', 'description' => 'Security deposit', 'quantity' => 1,
        'unit_price' => 100000, 'amount' => 100000, 'tax_amount' => 0, 'total' => 100000,
    ]);
    $invoice->recomputeTotals();

    app(WriteOffInvoiceService::class)->write($invoice->fresh(), [
        'amount' => 50000, 'reason' => 'settled_short', 'entry_date' => now()->toDateString(),
    ]);

    $before = writeOffDebits($invoice->fresh());

    $payment = Payment::create([
        'tenant_id' => $invoice->tenant_id,
        'payment_date' => now(),
        'amount' => 30000,
        'method' => 'bank_transfer',
        'status' => 'captured',
    ]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => 30000]);
    $invoice->fresh()->recomputeTotals();

    // The sweep runs again — and must find nothing to change.
    expect(writeOffDebits($invoice->fresh()))->toBe($before);
});

it('debits where the ISSUE credited, even if the accountant re-points the code', function () {
    // `charge_codes.posting_role` is operator-editable — `ChargeCodeForm` locks only `code` and
    // `is_active` for system codes. A hardcoded `deposits_held` here would debit a liability that
    // was never credited and drive it negative. `CreditNoteJournalizer` states the same rule for
    // the tax it reverses: a reversal never re-classifies what it is reversing.
    ChargeCode::query()->updateOrCreate(
        ['code' => 'security_deposit'],
        ['name_en' => 'Security deposit', 'name_ar' => 'تأمين', 'posting_role' => 'unearned_revenue', 'is_active' => true],
    );
    // The model flushes its own role memo on save (`ChargeCode:93`), so no explicit forget here —
    // and a test that reached for the private constant would be asserting on an implementation
    // detail rather than on the behaviour.

    $invoice = depositInvoice();

    app(WriteOffInvoiceService::class)->write($invoice, [
        'amount' => 100000, 'reason' => 'tenant_insolvent', 'entry_date' => now()->toDateString(),
    ]);

    $repointed = LedgerAccount::find(accountIdForRole('unearned_revenue'))?->code;
    $deposits = LedgerAccount::find(accountIdForRole('deposits_held'))?->code;

    $debits = writeOffDebits($invoice->fresh());

    expect($debits[$repointed] ?? 0.0)->toEqual(100000.0)
        ->and($debits[$deposits] ?? 0.0)->toEqual(0.0);
});

it('keeps deposits_tie_out GREEN — the check both rows were justified by', function () {
    // 100,000 billed, 60,000 received, 40,000 written off. GL: Cr 100,000 at issue, Dr 40,000 at
    // write-off = 60,000. Documents: held 60,000 + claimed 0. The review found neither deposit
    // regression test asserted the tie-out that is the entire stated reason for the fix.
    $invoice = depositInvoice();

    $payment = Payment::create([
        'tenant_id' => $invoice->tenant_id, 'payment_date' => now(), 'amount' => 60000,
        'method' => 'bank_transfer', 'status' => 'captured',
    ]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => 60000]);
    $invoice->fresh()->recomputeTotals();

    app(WriteOffInvoiceService::class)->write($invoice->fresh(), [
        'amount' => 40000, 'reason' => 'tenant_insolvent', 'entry_date' => now()->toDateString(),
    ]);

    app(LedgerPoster::class)->sync($invoice->fresh());
    $writeOff = $invoice->fresh()->writeOffs()->latest('id')->firstOrFail();
    app(LedgerPoster::class)->sync($writeOff);

    $ids = [test()->asset->id];

    expect((float) DepositHoldings::glBalance($ids))->toEqual(60000.0)
        ->and(DepositHoldings::expectedGlBalance($ids))->toEqual(60000.0);
});

it('attributes the deposit relief by the operator’s DATES, not by insertion order', function () {
    // Mixed invoice: rent 30,000 + deposit 100,000. The SECOND write-off recorded carries the
    // EARLIER date. By [entry_date, id] the earlier-dated 30,000 exhausts the rent, so the
    // later-dated 20,000 reaches the deposit in full; a bare created-order sum attributes the
    // relief the other way and moves the wrong month's P&L.
    $invoice = makeInvoice($this->lease, [
        'status' => 'issued', 'subtotal' => 130000, 'vat_amount' => 0,
        'total' => 130000, 'balance' => 130000,
    ]);
    $invoice->items()->create([
        'type' => 'base_rent', 'description' => 'Rent', 'quantity' => 1,
        'unit_price' => 30000, 'amount' => 30000, 'tax_amount' => 0, 'total' => 30000,
    ]);
    $invoice->items()->create([
        'type' => 'security_deposit', 'description' => 'Security deposit', 'quantity' => 1,
        'unit_price' => 100000, 'amount' => 100000, 'tax_amount' => 0, 'total' => 100000,
    ]);
    $invoice->recomputeTotals();

    // Recorded FIRST, dated LATER.
    app(WriteOffInvoiceService::class)->write($invoice->fresh(), [
        'amount' => 20000, 'reason' => 'settled_short', 'entry_date' => now()->toDateString(),
    ]);
    // Recorded SECOND, dated EARLIER — the back-dated correction an operator really keys.
    app(WriteOffInvoiceService::class)->write($invoice->fresh(), [
        'amount' => 30000, 'reason' => 'settled_short', 'entry_date' => now()->subMonth()->toDateString(),
    ]);

    [$laterDated, $earlierDated] = $invoice->fresh()->writeOffs()->orderBy('id')->get();

    // The split each row FROZE is what its entry posts, so the attribution question is asked of
    // the origination computation directly, with each row's own siblings in place.
    expect(round(DepositBilling::depositShareAtWriteOff(
        $invoice->fresh(), (float) $earlierDated->amount, $earlierDated->id), 2))
        ->toEqual(0.0)      // the earlier-dated 30,000 IS the rent
        ->and(round(DepositBilling::depositShareAtWriteOff(
            $invoice->fresh(), (float) $laterDated->amount, $laterDated->id), 2))
        ->toEqual(20000.0); // the later-dated 20,000 reaches the deposit in full
});

it('always balances, whatever the split', function () {
    $invoice = depositInvoice();

    app(WriteOffInvoiceService::class)->write($invoice, [
        'amount' => 100000,
        'reason' => 'tenant_insolvent',
        'entry_date' => now()->toDateString(),
    ]);

    $writeOff = $invoice->fresh()->writeOffs()->latest('id')->firstOrFail();
    app(LedgerPoster::class)->sync($writeOff);

    $entry = JournalEntry::where('source_type', $writeOff->getMorphClass())
        ->where('source_id', $writeOff->id)
        ->whereNull('voided_at')
        ->with('lines')
        ->firstOrFail();

    expect(round((float) $entry->lines->sum('debit'), 2))
        ->toEqual(round((float) $entry->lines->sum('credit'), 2));
});
