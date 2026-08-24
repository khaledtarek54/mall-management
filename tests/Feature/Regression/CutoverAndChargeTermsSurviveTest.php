<?php

use App\Models\Charge;
use App\Models\CreditNote;
use App\Models\DepositTransaction;
use App\Models\JournalEntry;
use App\Models\Lease;
use Carbon\CarbonImmutable;
use App\Services\Accounting\LedgerPoster;
use App\Services\ChargeScheduleService;
use App\Services\LeaseRenewalService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\TaxCodeSeeder;
use App\Services\Accounting\FiscalCalendar;

/**
 * The pre-staging money fixes that are armed by an ORDINARY event rather than by an exotic one:
 * a cutover, a renewal, a relief window, and the accountant answering C-TAX.
 *
 * Each was latent — correct arithmetic today, wrong the first time somebody does the normal thing.
 * Grouped in one file because they share fixtures and because a file is Pest's unit of parallelism.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    $this->seed(TaxCodeSeeder::class);

    // A posting test needs somewhere to post: without periods the engine refuses the entry, which
    // would make the "opening deposit posts nothing" assertion pass for entirely the wrong reason.
    app(FiscalCalendar::class)->ensureYear(2026);

    $this->asset = makeAsset();
    $this->unit = makeUnit($this->asset);
    $this->lease = makeLease($this->unit, null, ['status' => 'active']);
});

// ───────────────────────────── GAP1B-01 · the deposit cutover ─────────────────────────────

/**
 * A deposit already held on the day Atriom took over must reach the REGISTER without posting: the
 * liability is already inside the accountant's opening entry. Keying it as an ordinary receipt
 * invented a cash receipt that never happened here and doubled the liability; keying nothing left
 * every legacy tenant's deposit reading zero, so a move-out refunded money the books never took.
 */
it('records an opening deposit in the register and posts nothing', function () {
    $deposit = DepositTransaction::create([
        'lease_id' => $this->lease->id,
        'tenant_id' => $this->lease->tenant_id,
        'asset_id' => $this->asset->id,
        'type' => 'receipt',
        'amount' => 30000,
        'transaction_date' => '2026-01-01',
        'method' => 'bank',
        'status' => 'recorded',
        'is_opening_balance' => true,
    ]);

    app(LedgerPoster::class)->sync($deposit);

    expect(JournalEntry::where('source_id', $deposit->id)->where('source_type', $deposit->getMorphClass())->count())->toBe(0)
        // The register still holds it — that is the whole point.
        ->and($this->lease->fresh()->depositHeld())->toEqual(30000.0);
});

/** The control: an ORDINARY receipt still posts, so the skip above is not simply "nothing posts". */
it('still posts an ordinary deposit receipt', function () {
    $deposit = DepositTransaction::create([
        'lease_id' => $this->lease->id,
        'tenant_id' => $this->lease->tenant_id,
        'asset_id' => $this->asset->id,
        'type' => 'receipt',
        'amount' => 30000,
        'transaction_date' => '2026-02-01',
        'method' => 'bank',
        'status' => 'recorded',
    ]);

    app(LedgerPoster::class)->sync($deposit);

    expect(JournalEntry::where('source_id', $deposit->id)->where('source_type', $deposit->getMorphClass())->count())->toBe(1);
});

/**
 * Only a RECEIPT can predate the system. A refund or forfeit of an old deposit is this system's own
 * cash moving and must post — a flag quietly ignored there would suppress a real entry, which is the
 * failure the flag exists to prevent in the other direction.
 */
it('refuses to mark a refund as an opening balance', function () {
    expect(fn () => DepositTransaction::create([
        'lease_id' => $this->lease->id,
        'tenant_id' => $this->lease->tenant_id,
        'asset_id' => $this->asset->id,
        'type' => 'refund',
        'amount' => 5000,
        'transaction_date' => '2026-02-01',
        'method' => 'bank',
        'status' => 'recorded',
        'is_opening_balance' => true,
    ]))->toThrow(DomainException::class);
});

// ─────────────────────── D3-01 / D3-02 · the charge terms a copy drops ───────────────────────

function arrearsFlatCharge(Lease $lease): Charge
{
    return Charge::create([
        ...$lease->invoiceLinkAttributes(),
        'name' => 'Signage licence',
        'type' => 'other',
        'amount' => 5000,
        'currency' => 'EGP',
        'frequency' => 'monthly',
        'billing_timing' => 'arrears',
        'prorate' => false,
        'start_date' => '2026-01-01',
        'is_active' => true,
    ]);
}

/**
 * A RELIEF window rewrites the schedule directly through `overlayWindow()`, which never went through
 * `setAmount()` — so it dropped both terms. `setAmount()`'s own comment claimed "every successor
 * comes through here: … a relief, a renewal", and that sentence is why nobody looked.
 */
it('carries billing timing and the prorate flag through a relief window', function () {
    $charge = arrearsFlatCharge($this->lease);

    $result = app(ChargeScheduleService::class)->overlayWindow(
        $this->lease,
        'other',
        CarbonImmutable::parse('2026-03-01'),
        CarbonImmutable::parse('2026-04-30'),
        fn (float $amount) => 0.0,
    );

    $rows = collect($result['relief'])->push($result['resumed'])->filter();

    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        expect($row->billing_timing)->toBe('arrears')
            ->and($row->prorate)->toBeFalse();
    }
});

/**
 * A RENEWAL is a create, and it builds its charge rows from an explicit attribute list. It learned
 * `billing_timing` when EG-30 shipped and never learned `prorate`, so a flat licence the operator
 * marked "bills whole months" started prorating again one renewal at a time — and the renewal's own
 * final part-month then clawed back part of a fee the tenant owes in full.
 */
it('carries the prorate flag onto a renewal', function () {
    arrearsFlatCharge($this->lease);

    $renewal = app(LeaseRenewalService::class)->renew($this->lease, [
        'new_term_months' => 12,
        'new_rent' => (float) $this->lease->base_rent_monthly,
    ]);

    $copied = $renewal->charges()->where('name', 'Signage licence')->firstOrFail();

    expect($copied->prorate)->toBeFalse()
        ->and($copied->billing_timing)->toBe('arrears');
});

// ─────────────────────────── AR-GL-01 · the credit note's own tax ───────────────────────────

/**
 * A reversal never re-classifies the tax it reverses. `CreditNoteJournalizer` hard-coded
 * `vat_payable`, so the day a charge code points at stamp or schedule tax — a ROW the accountant
 * writes, no deploy, the open C-TAX question — the invoice would credit one liability and its credit
 * note debit another. Both entries balance, so nothing fails loudly; only the VAT return's tie-out
 * would ever have said so, permanently.
 */
it('reverses tax at the posting role the line carried, not always VAT', function () {
    $note = CreditNote::create([
        'tenant_id' => $this->lease->tenant_id,
        'lease_id' => $this->lease->id,
        'asset_id' => $this->asset->id,
        'issue_date' => '2026-02-10',
        'status' => 'issued',
        'reason' => 'Test',
        'subtotal' => 1000,
        'vat_amount' => 140,
        'total' => 1140,
        'applied_amount' => 0,
        'balance' => 1140,
        'currency' => 'EGP',
    ]);

    // Two lines, two different taxes at the same money — this is what a single accumulator hid.
    $note->describeAs('Rent credit', 500, 14, 70, 'VAT_14');
    $note->describeAs('Stamped supply credit', 500, 14, 70, 'STAMP_20');

    $payload = app(\App\Services\Accounting\Journalizers\CreditNoteJournalizer::class)->payload($note->fresh());

    $debits = collect($payload['lines'])->where('debit', '>', 0)->pluck('debit', 'ledger_account_id');

    // The entry still balances to the receivable being reversed, whatever the split.
    expect(round(collect($payload['lines'])->sum('debit'), 2))
        ->toEqual(round(collect($payload['lines'])->sum('credit'), 2))
        // Two distinct tax accounts, not one — the whole point.
        ->and($debits->count())->toBeGreaterThan(2);
});

/** The floor: a line naming NO tax code is still VAT, exactly as the invoice side treats it. */
it('falls back to VAT payable for a line that names no tax code', function () {
    $note = CreditNote::create([
        'tenant_id' => $this->lease->tenant_id,
        'lease_id' => $this->lease->id,
        'asset_id' => $this->asset->id,
        'issue_date' => '2026-02-10',
        'status' => 'issued',
        'reason' => 'Test',
        'subtotal' => 1000,
        'vat_amount' => 140,
        'total' => 1140,
        'applied_amount' => 0,
        'balance' => 1140,
        'currency' => 'EGP',
    ]);

    $note->describeAs('Unclassified credit', 1000, 14, 140);

    $payload = app(\App\Services\Accounting\Journalizers\CreditNoteJournalizer::class)->payload($note->fresh());

    expect(round(collect($payload['lines'])->sum('debit'), 2))
        ->toEqual(round(collect($payload['lines'])->sum('credit'), 2))
        ->and(collect($payload['lines'])->firstWhere('debit', 140.0))->not->toBeNull();
});
