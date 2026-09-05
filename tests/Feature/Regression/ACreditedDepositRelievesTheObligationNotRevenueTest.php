<?php

/*
|--------------------------------------------------------------------------
| A credited deposit relieves the OBLIGATION, not revenue (SW-238)
|--------------------------------------------------------------------------
| The write-off twin of SW-210, through the credit door — found by SW-210's own adversarial review.
| A `security_deposit` line credits `deposits_held`, a LIABILITY, at issue; `CreditNoteJournalizer`
| debited `sales_returns` — contra-REVENUE — for every line, so crediting a deposit invoice reversed
| revenue never recognised and left the refund obligation standing: a fully credited 100,000 deposit
| left the GL saying 100,000 held where the truth is 0, and `deposits_tie_out` red with no write-off
| anywhere near it.
|
| **The split is FROZEN (`credit_notes.deposit_amount`), maintained from the note's own lines while
| they are written, and the change is PROSPECTIVE** — legacy rows carry 0.00 and post exactly what
| they always posted. Keying on `credit_note_items.type` instead would have restated history:
| SW-216's backfill typed HISTORICAL lines, so the next sweep would void-and-repost entries into
| periods that may since have closed — the unclearable SW-236 drift SW-210 was reworked to avoid.
|
| The role is resolved as the ISSUE resolved it, through `DepositBilling::depositPostingRole()`,
| now shared with the write-off journalizer — one resolution for one obligation, because a reversal
| never re-classifies what it reverses.
*/

use App\Models\CreditNote;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerPoster;
use App\Services\CreditNoteService;
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
    $this->asset = makeAsset(['code' => 'CDR']);
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    $this->lease = makeLease(makeUnit($this->asset));
});

/** An issued credit note whose lines are written the way the services write them. */
function creditNoteWithLines(array $lines): CreditNote
{
    $total = round(array_sum(array_column($lines, 'total')), 2);
    $vat = round(array_sum(array_column($lines, 'vat')), 2);

    $note = CreditNote::create([
        'invoice_id' => makeInvoice(test()->lease, ['status' => 'issued'])->id,
        'tenant_id' => test()->lease->tenant_id,
        'asset_id' => test()->asset->id,
        'status' => 'issued',
        'issue_date' => now()->toDateString(),
        'reason' => 'adjustment',
        'subtotal' => round($total - $vat, 2),
        'vat_amount' => $vat,
        'total' => $total,
        'balance' => $total,
    ]);

    foreach ($lines as $line) {
        $note->items()->create([
            'description' => $line['description'],
            'type' => $line['type'],
            'amount' => round($line['total'] - $line['vat'], 2),
            'vat_rate' => 0,
            'vat_amount' => $line['vat'],
            'total' => $line['total'],
        ]);
    }

    return $note->fresh();
}

/** The debit side of the note's posted entry, keyed by account code. */
function creditNoteDebits(CreditNote $note): array
{
    app(LedgerPoster::class)->sync($note);

    $entry = JournalEntry::where('source_type', $note->getMorphClass())
        ->where('source_id', $note->id)
        ->whereNull('voided_at')
        ->with('lines')
        ->firstOrFail();

    $debits = [];

    foreach ($entry->lines as $line) {
        if ((float) $line->debit > 0) {
            $code = LedgerAccount::find($line->ledger_account_id)?->code ?? (string) $line->ledger_account_id;
            $debits[$code] = round((float) $line->debit, 2);
        }
    }

    return $debits;
}

function codeForRole(string $role): ?string
{
    $id = app(AccountResolver::class)->id($role, test()->asset->id);

    return LedgerAccount::find($id)?->code;
}

it('freezes the deposit share on the note as its lines are written', function () {
    $note = creditNoteWithLines([
        ['description' => 'Security deposit credited', 'type' => 'security_deposit', 'total' => 100000, 'vat' => 0],
    ]);

    expect(round((float) $note->deposit_amount, 2))->toEqual(100000.0);
});

it('debits the deposit liability for a credited deposit, and no contra-revenue at all', function () {
    $note = creditNoteWithLines([
        ['description' => 'Security deposit credited', 'type' => 'security_deposit', 'total' => 100000, 'vat' => 0],
    ]);

    $debits = creditNoteDebits($note);

    // The ABSENCE matters as much as the presence: both debits would still balance against AR and
    // read fine in a total — the SW-210 lesson, restated.
    expect($debits[codeForRole('deposits_held')] ?? 0.0)->toEqual(100000.0)
        ->and($debits[codeForRole('sales_returns')] ?? 0.0)->toEqual(0.0);
});

it('splits a mixed note between the obligation and contra-revenue', function () {
    $note = creditNoteWithLines([
        ['description' => 'Rent credited', 'type' => 'base_rent', 'total' => 30000, 'vat' => 0],
        ['description' => 'Security deposit credited', 'type' => 'security_deposit', 'total' => 100000, 'vat' => 0],
    ]);

    $debits = creditNoteDebits($note);

    expect($debits[codeForRole('sales_returns')] ?? 0.0)->toEqual(30000.0)
        ->and($debits[codeForRole('deposits_held')] ?? 0.0)->toEqual(100000.0);
});

it('still books contra-revenue for an ordinary credit note — the untouched case', function () {
    $note = creditNoteWithLines([
        ['description' => 'Rent credited', 'type' => 'base_rent', 'total' => 50000, 'vat' => 0],
    ]);

    $debits = creditNoteDebits($note);

    expect($debits[codeForRole('sales_returns')] ?? 0.0)->toEqual(50000.0)
        ->and($debits[codeForRole('deposits_held')] ?? 0.0)->toEqual(0.0);
});

it('treats an untyped line as NOT STATED, never as a deposit', function () {
    // SW-216's own rule: null means not stated, and apportioning it is a decision, not a
    // derivation. It takes the sales_returns floor — verbatim the legacy behaviour.
    $note = creditNoteWithLines([
        ['description' => 'Goodwill credit', 'type' => null, 'total' => 20000, 'vat' => 0],
    ]);

    expect(round((float) $note->deposit_amount, 2))->toEqual(0.0)
        ->and(creditNoteDebits($note)[codeForRole('sales_returns')] ?? 0.0)->toEqual(20000.0);
});

it('is PROSPECTIVE — a legacy note posts exactly what it always posted', function () {
    $note = creditNoteWithLines([
        ['description' => 'Security deposit credited', 'type' => 'security_deposit', 'total' => 100000, 'vat' => 0],
    ]);

    // A pre-SW-238 row: typed line (the SW-216 backfill), frozen figure never computed. Forced
    // quietly, as a migration-era row would stand.
    $note->deposit_amount = 0;
    $note->saveQuietly();

    $debits = creditNoteDebits($note->fresh());

    expect($debits[codeForRole('sales_returns')] ?? 0.0)->toEqual(100000.0)
        ->and($debits[codeForRole('deposits_held')] ?? 0.0)->toEqual(0.0);
});

it('keeps deposits_tie_out GREEN while the note stands unapplied — the FATAL the review caught', function () {
    // The first version of this fix debited `deposits_held` at issue and taught the DOCUMENTS side
    // nothing: an issued-but-unapplied deposit credit note read relieved in the GL and claimed on
    // the register, so `deposits_tie_out` went red, `atriom:preflight` failed, and deploys were
    // blocked by an ordinary operator act — the exact state this row was justified by, re-created
    // through its own fix. `DepositHoldings::standingDepositCredits()` is the missing term, the
    // deposit twin of `glTieOut()`'s `outstandingCredits`.
    $note = creditNoteWithLines([
        ['description' => 'Security deposit credited', 'type' => 'security_deposit', 'total' => 100000, 'vat' => 0],
    ]);

    app(LedgerPoster::class)->sync($note->invoice->fresh());
    app(LedgerPoster::class)->sync($note);

    $ids = [test()->asset->id];

    // The tie-out itself, both sides — not the journalizer's arithmetic.
    expect(DepositHoldings::standingDepositCredits($ids))->toEqual(100000.0)
        ->and(DepositHoldings::expectedGlBalance($ids))
        ->toEqual((float) DepositHoldings::glBalance($ids));
});

it('keeps the tie-out green after the note is APPLIED too — the other end of the window', function () {
    $note = creditNoteWithLines([
        ['description' => 'Security deposit credited', 'type' => 'security_deposit', 'total' => 100000, 'vat' => 0],
    ]);

    // Through the REAL service — two hand fixtures were silently reverted by the model's own
    // derive hooks (applied_amount re-summed from applications; credit_applied re-derived by
    // recomputeTotals), which is those hooks doing their job. The service is the reachable input,
    // and it caps at the invoice's settleable amount — so the source invoice must actually carry
    // the 100,000 deposit line it is being credited for.
    $source = $note->invoice->fresh();
    $source->items()->create([
        'type' => 'security_deposit', 'description' => 'Security deposit', 'quantity' => 1,
        'unit_price' => 100000, 'amount' => 100000, 'tax_amount' => 0, 'total' => 100000,
    ]);
    $source->fresh()->recomputeTotals();

    app(CreditNoteService::class)->applyToInvoice($note->fresh(), $source->fresh());

    app(LedgerPoster::class)->sync($note->invoice->fresh());
    app(LedgerPoster::class)->sync($note->fresh());

    $ids = [test()->asset->id];

    expect(DepositHoldings::standingDepositCredits($ids))->toEqual(0.0)
        ->and(DepositHoldings::expectedGlBalance($ids))
        ->toEqual((float) DepositHoldings::glBalance($ids));
});

it('shares ONE role resolution with the write-off door', function () {
    // Two reversal doors onto one obligation. A second copy of the resolution is how they come to
    // debit different accounts the day the accountant re-points the charge code.
    expect(DepositBilling::depositPostingRole())->toBe('deposits_held');

    $source = file_get_contents(app_path('Services/Accounting/Journalizers/InvoiceWriteOffJournalizer.php'));

    expect($source)->toContain('DepositBilling::depositPostingRole()');
});
