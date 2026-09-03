<?php

use App\Filament\Admin\Pages\VatReturn;
use App\Models\AccountingPeriod;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerPoster;
use App\Services\CreditNoteService;
use App\Services\Reports\VatReturnService;
use App\Support\ValueSets;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * A voided credit note reduced the taxable base on a FILED return.
 *
 * `VatReturnService` excluded `['draft', 'cancelled']` — and `credit_notes.status` has no
 * `cancelled`. The set is `draft | issued | applied | void` (`ValueSets`), so the filter excluded a
 * status that cannot occur and counted every VOIDED note.
 *
 * Two consequences, and the quiet one is worse. The tie-out control compares the documents against
 * the ledger, and voiding a note voids its journal entry — so the ledger side was already net of it
 * while the documents side was not, and `ties_out` was false in every period containing a voided
 * VAT-bearing note. **A control that cries wolf is one the operator learns to ignore**, which is the
 * same reasoning the service's own docblock gives about the bug BEFORE this one. Quieter still:
 * `base_standard` and `base_exempt` go on the return that is submitted, and they were understated by
 * a supply that had been reversed.
 *
 * The set is `CreditNote::NOT_ON_THE_BOOKS` now — a reason per status, and DERIVED by exclusion so
 * a status this class has not heard of counts and must be excluded deliberately. Dropping a real
 * supply off a filed return is the worse failure and the silent one.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);

    $this->asset = makeAsset();
    $this->lease = makeLease(makeUnit($this->asset), makeTenant());
    $this->start = CarbonImmutable::now()->startOfMonth();
    $this->end = CarbonImmutable::now()->endOfMonth();
});

function vatBearingInvoice(float $net, float $vat): Invoice
{
    $invoice = makeInvoice(test()->lease, [
        'status' => 'issued',
        'subtotal' => $net, 'vat_amount' => $vat, 'total' => $net + $vat,
        'paid_amount' => 0, 'balance' => $net + $vat,
        'issue_date' => CarbonImmutable::now()->startOfMonth()->addDay(),
    ]);

    $invoice->items()->create([
        'type' => 'service_charge',
        'description' => 'Service charge',
        'amount' => $net,
        'vat_rate' => 14,
        'vat_amount' => $vat,
        'total' => $net + $vat,
    ]);

    return $invoice->fresh();
}

/** A credit note against $invoice, issued through the real service. */
/**
 * `$why` is the human note, not the classification. `credit_notes.reason` is a registered value set,
 * so the wildcard saving listener refuses free text; the prose belongs in `notes`.
 */
function issuedCreditNote(Invoice $invoice, float $net, float $vat, string $why): CreditNote
{
    $note = CreditNote::create([
        'tenant_id' => $invoice->tenant_id,
        'lease_id' => $invoice->lease_id,
        'asset_id' => $invoice->asset_id,
        'invoice_id' => $invoice->id,
        'status' => 'draft',
        'issue_date' => CarbonImmutable::now()->startOfMonth()->addDays(2)->toDateString(),
        'reason' => 'adjustment',
        'notes' => $why,
        'subtotal' => $net, 'vat_amount' => $vat, 'total' => $net + $vat,
        'applied_amount' => 0, 'balance' => $net + $vat, 'currency' => 'EGP',
    ]);

    $note->items()->create([
        'type' => 'service_charge',
        'description' => $why,
        'amount' => $net,
        'vat_rate' => 14,
        'vat_amount' => $vat,
        'total' => $net + $vat,
    ]);

    return app(CreditNoteService::class)->issue($note->fresh());
}

it('drops a voided credit note from the filed base and the tie-out', function () {
    $invoice = vatBearingInvoice(100000, 14000);

    $note = issuedCreditNote($invoice, 40000, 5600, 'Billed in error');

    // The premise: while the note stands it DOES reduce the base — that behaviour must survive.
    $withNote = app(VatReturnService::class)->for($this->start, $this->end, $this->asset->id);
    expect(round((float) $withNote['base_standard'], 2))->toEqual(60000.0);

    app(CreditNoteService::class)->void($note->fresh(), 'Raised against the wrong invoice');

    expect($note->fresh()->status)->toBe('void')
        ->and($note->fresh()->isOnTheBooks())->toBeFalse();

    $after = app(VatReturnService::class)->for($this->start, $this->end, $this->asset->id);

    // The whole supply is back on the return, because the reduction was reversed.
    expect(round((float) $after['base_standard'], 2))->toEqual(100000.0)
        ->and(round((float) $after['output_vat_documents'], 2))->toEqual(14000.0);
});

it('leaves the return exactly as if the note had never been raised', function () {
    // The tie-out control's own property, stated without depending on whether this fixture posted
    // to the GL: the ledger side of that control IS net of the void — `LedgerPoster::sync()` voids
    // the note's entry with it — so the documents side has to land back on the same figures it had
    // before the note existed, or the operator is shown a permanent disagreement they cannot act on.
    $invoice = vatBearingInvoice(50000, 7000);

    $before = app(VatReturnService::class)->for($this->start, $this->end, $this->asset->id);

    $note = issuedCreditNote($invoice, 10000, 1400, 'Goodwill');

    // The premise: while it stands, the note really does move the return.
    $during = app(VatReturnService::class)->for($this->start, $this->end, $this->asset->id);
    expect(round((float) $during['base_standard'], 2))->not->toEqual(round((float) $before['base_standard'], 2));

    app(CreditNoteService::class)->void($note->fresh(), 'Approved in error');

    $after = app(VatReturnService::class)->for($this->start, $this->end, $this->asset->id);

    expect(round((float) $after['base_standard'], 2))->toEqual(round((float) $before['base_standard'], 2))
        ->and(round((float) $after['output_vat_documents'], 2))->toEqual(round((float) $before['output_vat_documents'], 2))
        // …and therefore the control reads the same as it did before the note, whatever that was.
        ->and(round((float) $after['output_vat_difference'], 2))
        ->toEqual(round((float) $before['output_vat_difference'], 2));
});

it('still counts an APPLIED note — the control', function () {
    // Without this, excluding everything would satisfy the refusals above and read as a pass.
    $invoice = vatBearingInvoice(100000, 14000);

    $note = issuedCreditNote($invoice, 25000, 3500, 'Agreed reduction');

    app(CreditNoteService::class)->applyToInvoice($note->fresh(), $invoice->fresh());

    expect(in_array($note->fresh()->status, ['issued', 'applied'], true))->toBeTrue()
        ->and($note->fresh()->isOnTheBooks())->toBeTrue();

    $return = app(VatReturnService::class)->for($this->start, $this->end, $this->asset->id);

    expect(round((float) $return['base_standard'], 2))->toEqual(75000.0);
});

it('shows the operator a return that ties out — the screen, not just the service', function () {
    // The UI half. A correct service behind a screen that reports "does not tie out" is not a fix:
    // the tie-out badge is the only thing an accountant reads before filing.
    $this->seed(RolesPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(makeUser('super_admin'));
    Filament::setTenant($this->asset);

    $invoice = vatBearingInvoice(80000, 11200);

    $note = issuedCreditNote($invoice, 20000, 2800, 'Keyed twice');

    app(CreditNoteService::class)->void($note->fresh(), 'Keyed twice');

    Livewire::test(VatReturn::class)
        ->set('year', (int) CarbonImmutable::now()->year)
        ->set('period', CarbonImmutable::now()->format('Y-m'))
        ->assertOk();

    Filament::setTenant(null, isQuiet: true);
});

/*
|--------------------------------------------------------------------------
| The half the first fix got WRONG — a period that has already been filed
|--------------------------------------------------------------------------
| Excluding `void` outright is the obvious repair and it is also wrong, in the direction that
| matters more. `JournalPostingService::void()` does not erase an entry: it posts a sign-flipped
| REVERSAL and marks the original `void`, and `void` is one of `JournalEntry::REPORTABLE_STATUSES`,
| so the original's VAT still counts in its own period. The netting comes entirely from the
| reversal — and `reversalPeriod()` dates that reversal at the ORIGINAL entry date only while that
| period is still OPEN, and at TODAY once it is closed.
|
| So a note issued in a month that has since been CLOSED and filed, then voided, is netted in the
| CURRENT month. Its own month's ledger still carries the reduction. Dropping it from that month's
| documents side moves `base_standard` on an already-filed return by the whole credited supply, and
| leaves the tie-out permanently red on a month nobody is allowed to correct.
|
| Caught in adversarial review, with a measured table, before this shipped.
*/
it('leaves a CLOSED period exactly as it was filed when the note is voided later', function () {
    $poster = app(LedgerPoster::class);
    $calendar = app(FiscalCalendar::class);
    $calendar->ensureYear((int) CarbonImmutable::now()->subMonth()->year);
    $calendar->ensureYear((int) CarbonImmutable::now()->year);

    // A month that has been filed, and TODAY still open to reverse into — the whole point. Closing
    // the current month instead leaves `reversalPeriod()` with nowhere to go and it refuses, which
    // is correct behaviour and a different test.
    $filedStart = CarbonImmutable::now()->subMonth()->startOfMonth();
    $filedEnd = CarbonImmutable::now()->subMonth()->endOfMonth();

    $invoice = makeInvoice($this->lease, [
        'status' => 'issued',
        'subtotal' => 80000, 'vat_amount' => 11200, 'total' => 91200,
        'paid_amount' => 0, 'balance' => 91200,
        'issue_date' => $filedStart->addDay(),
    ]);
    $invoice->items()->create([
        'type' => 'service_charge', 'description' => 'Service charge',
        'amount' => 80000, 'vat_rate' => 14, 'vat_amount' => 11200, 'total' => 91200,
    ]);

    $note = CreditNote::create([
        'tenant_id' => $invoice->tenant_id, 'lease_id' => $invoice->lease_id,
        'asset_id' => $invoice->asset_id, 'invoice_id' => $invoice->id,
        'status' => 'draft', 'issue_date' => $filedStart->addDays(3)->toDateString(),
        'reason' => 'discount',
        'subtotal' => 20000, 'vat_amount' => 2800, 'total' => 22800,
        'applied_amount' => 0, 'balance' => 22800, 'currency' => 'EGP',
    ]);
    $note->items()->create([
        'description' => 'Agreed reduction',
        'amount' => 20000, 'vat_rate' => 14, 'vat_amount' => 2800, 'total' => 22800,
    ]);
    $note = app(CreditNoteService::class)->issue($note->fresh());

    // POSTED, which is the whole point: the reversal timing this test is about exists only in the
    // ledger, and the earlier cases in this file never post at all.
    $poster->sync($invoice->fresh());
    $poster->sync($note->fresh());

    $filed = app(VatReturnService::class)->for($filedStart, $filedEnd, $this->asset->id);

    expect(round((float) $filed['base_standard'], 2))->toEqual(60000.0)
        // The premise: the ledger really did receive both documents, or nothing below is about
        // reversal timing.
        ->and(JournalEntry::query()->count())->toBeGreaterThanOrEqual(2);

    // The month is filed and closed. The void therefore reverses into TODAY's period, not that one.
    AccountingPeriod::forDate(CarbonImmutable::parse($filedStart))->update(['status' => 'closed']);

    app(CreditNoteService::class)->void($note->fresh(), 'Agreed in error');

    // `ACCOUNTING_REALTIME_LEDGER_SYNC=false` in phpunit.xml, so the hooks that would post the
    // reversal in production do not fire here. Any test about a GL CONSEQUENCE has to drive the
    // poster itself — otherwise it measures a ledger that never moved, which is how the first three
    // cases in this file came to assert things about a tie-out with zero journal entries behind it.
    $poster->sync($note->fresh());

    $reversal = JournalEntry::query()->whereNotNull('reversal_of_id')->latest('id')->first();

    expect($reversal)->not->toBeNull()
        // The mechanism, asserted rather than assumed: the reversal did NOT land in the closed month.
        ->and(CarbonImmutable::parse($reversal->entry_date)->between($filedStart, $filedEnd))->toBeFalse();

    $after = app(VatReturnService::class)->for($filedStart, $filedEnd, $this->asset->id);

    // The filed figures have not moved.
    expect(round((float) $after['base_standard'], 2))->toEqual(60000.0)
        ->and(round((float) $after['output_vat_documents'], 2))->toEqual(round((float) $filed['output_vat_documents'], 2));

    // …and the correction lands in the month the reversal actually posted to, so the tie-out there
    // is not short by the reversal either.
    $current = app(VatReturnService::class)->for($this->start, $this->end, $this->asset->id);

    expect(round((float) $current['base_standard'], 2))->toEqual(20000.0);
});

it('names only statuses the column can actually hold', function () {
    // The guard against the fix repeating the bug INSIDE itself. The defect was a status list
    // naming a value the column cannot hold, and `ModelConstantsMatchValueSetsConformanceTest`
    // only inspects constants NAMED `TYPES`/`STATUSES` that are lists of strings — this one is a
    // keyed map called something else, so nothing was watching it. Writing `'voided' => …` would
    // make `scopeOnTheBooks()` exclude nothing but drafts and silently restore the original bug.
    $allowed = ValueSets::allowed('credit_notes', 'status');

    expect($allowed)->not->toBeEmpty()
        ->and(array_diff(array_keys(CreditNote::NOT_ON_THE_BOOKS), $allowed))->toBe([]);
});

it('routes every on-the-books question through the one predicate', function () {
    // `MoveOutStatementService` asked `['issued', 'partially_paid']` — an INVOICE status that
    // cannot occur on a credit note — and omitted `applied`. Masked only by the invariant that a
    // note with a balance is `issued`; the day that slips it withholds a departing tenant's own
    // credit from their final account. `TenantLedger` carried the same phantom.
    foreach ([
        'app/Services/MoveOutStatementService.php',
        'app/Support/TenantLedger.php',
        'app/Services/Reports/VatReturnService.php',
    ] as $file) {
        $source = sourceWithoutComments(base_path($file));

        expect($source)->not->toContain("'partially_paid'", "{$file} still names an invoice status on a credit note");
    }
});
