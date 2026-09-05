<?php

/*
|--------------------------------------------------------------------------
| Reversing a write-off refuses when the ledger void cannot land (SW-230)
|--------------------------------------------------------------------------
| `WriteOffInvoiceService::reverse()` could refuse nothing at all — no status check, no period
| check. The ledger void it relies on is dispatched as an `afterCommit` job (`SyncDocumentToLedger`)
| whose handler catches `\Throwable` and only LOGS, so the failure is silent by construction.
|
| `JournalPostingService::reversalPeriod()` throws when neither the original entry's period nor
| today's is open. In that window the old code soft-deleted the row anyway, the job swallowed the
| refusal, and the write-off's `Cr accounts_receivable` stood with no document behind it: an
| unbacked credit for a debt that is once again live. That is the same state `AVoidCannotLeaveABad
| DebtStandingTest` refuses through the void door — re-created here through the SANCTIONED route,
| which is worse, because the operator was told it worked.
|
| The rule is asked, not copied: `openPeriodForReversalOf()` is `reversalPeriod()`'s own loop with
| the throw lifted out, so the guard and the act cannot come to disagree about which periods are
| open. Refusing is the safe direction and the message names the way out — reopen a period.
|
| Note the row's second half, also fixed: `EditInvoice` catches a `DomainException` for *"the GL
| void lands in a CLOSED period"* — a refusal that job could never raise. It can now.
*/

use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\LedgerPoster;
use App\Services\WriteOffInvoiceService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'WOR']);
    $this->lease = makeLease(makeUnit($this->asset));

    $this->invoice = makeInvoice($this->lease, ['status' => 'issued', 'issue_date' => now()->toDateString()]);
    $this->invoice->items()->create([
        'type' => 'base_rent', 'description' => 'Rent',
        'amount' => 10000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 10000,
    ]);
    $this->invoice = $this->invoice->fresh();
    app(LedgerPoster::class)->sync($this->invoice);

    app(WriteOffInvoiceService::class)->write($this->invoice->fresh(), [
        'amount' => 10000,
        'reason' => 'tenant_insolvent',
        'entry_date' => now()->toDateString(),
    ]);

    $this->writeOff = $this->invoice->fresh()->writeOffs()->latest('id')->firstOrFail();
    app(LedgerPoster::class)->sync($this->writeOff);
});

/** Close every period, so the reversal has nowhere to land. */
function closeEveryPeriod(): void
{
    AccountingPeriod::query()->update(['status' => 'closed']);
}

it('reverses normally while a period is open — the control', function () {
    app(WriteOffInvoiceService::class)->reverse($this->writeOff, 'Tenant paid after all');

    // NOT `issued` specifically — `recomputeTotals()` is the single source of truth for the
    // status and re-derives `overdue` here, correctly, because the due date has passed. What the
    // reversal owes is that the invoice stops being written off and the debt is live again.
    expect($this->writeOff->fresh()->trashed())->toBeTrue()
        ->and($this->invoice->fresh()->status)->not->toBe('written_off')
        ->and((float) $this->invoice->fresh()->collectableBalance())->toEqual(10000.0);
});

it('refuses when neither the entry’s period nor today’s is open', function () {
    closeEveryPeriod();

    expect(fn () => app(WriteOffInvoiceService::class)->reverse($this->writeOff->fresh(), 'Recovered'))
        ->toThrow(DomainException::class);
});

it('leaves the write-off STANDING when it refuses — the whole point', function () {
    closeEveryPeriod();

    try {
        app(WriteOffInvoiceService::class)->reverse($this->writeOff->fresh(), 'Recovered');
    } catch (DomainException) {
        // expected
    }

    // The old code soft-deleted first and let a swallowed job fail afterwards, leaving the ledger
    // credit with no document behind it. Asserting the refusal alone would pass on that too — the
    // row surviving is what says the transaction rolled back.
    expect($this->writeOff->fresh()->trashed())->toBeFalse();

    $entry = JournalEntry::where('source_type', $this->writeOff->getMorphClass())
        ->where('source_id', $this->writeOff->id)
        ->where('status', 'posted')
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->voided_at)->toBeNull();
});

it('refuses a write-off that has already been reversed', function () {
    app(WriteOffInvoiceService::class)->reverse($this->writeOff, 'Recovered');

    expect(fn () => app(WriteOffInvoiceService::class)->reverse($this->writeOff->fresh(), 'Again'))
        ->toThrow(DomainException::class);
});

it('still reverses when only TODAY’s period is open', function () {
    // The entry's own period is preferred but not required — `reversalPeriod()` falls through to
    // today. Without this case the guard could be "the entry's period must be open", which would
    // refuse the ordinary recovery of an old debt and push the operator into a worse workaround.
    AccountingPeriod::query()
        ->whereDate('starts_on', '<', now()->startOfMonth())
        ->update(['status' => 'closed']);

    app(WriteOffInvoiceService::class)->reverse($this->writeOff, 'Recovered late');

    expect($this->writeOff->fresh()->trashed())->toBeTrue();
});

it('asks one rule, so the guard cannot drift from the act', function () {
    // `canVoidEntryFor()` must agree with what the posting service would actually do. A guard with
    // its own copy of the period loop is how the two come to disagree after somebody edits one.
    $entry = JournalEntry::where('source_type', $this->writeOff->getMorphClass())
        ->where('source_id', $this->writeOff->id)
        ->where('status', 'posted')
        ->firstOrFail();

    expect(app(LedgerPoster::class)->canVoidEntryFor($this->writeOff))->toBeTrue();

    closeEveryPeriod();

    expect(app(LedgerPoster::class)->canVoidEntryFor($this->writeOff->fresh()))->toBeFalse()
        ->and(app(JournalPostingService::class)
            ->openPeriodForReversalOf($entry->fresh()))->toBeNull();
});

it('says nothing to void for a source that never posted', function () {
    // A write-off whose entry was never posted must still be reversible — otherwise a closed month
    // would strand a row the ledger never knew about.
    $other = makeInvoice($this->lease, ['status' => 'issued', 'issue_date' => now()->toDateString()]);
    $other->items()->create([
        'type' => 'base_rent', 'description' => 'Rent', 'amount' => 5000,
        'vat_rate' => 0, 'vat_amount' => 0, 'total' => 5000,
    ]);

    app(WriteOffInvoiceService::class)->write($other->fresh(), [
        'amount' => 5000, 'reason' => 'tenant_insolvent', 'entry_date' => now()->toDateString(),
    ]);

    $unposted = $other->fresh()->writeOffs()->latest('id')->firstOrFail();
    closeEveryPeriod();

    expect(app(LedgerPoster::class)->canVoidEntryFor($unposted))->toBeTrue();
});
