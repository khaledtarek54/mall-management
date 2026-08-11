<?php

use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\LedgerReportService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * A POSTED journal entry, and every line on it, is immutable.
 *
 * THE GAP (module 21 close-out, 2026-08-11) — and it is the purest instance of what this whole
 * sweep has been about, in the one module every other module posts into.
 *
 * `JournalPostingService` validates the entry when it posts: every line carries a debit or a
 * credit, the total is non-zero, and debits equal credits to the half-piastre. **Nothing
 * re-validates afterwards.** And the enforcement of "a posted entry is permanent" was:
 *
 *     EditJournalEntry::getSaveFormAction()->visible(fn () => $this->record->status === 'draft')
 *
 * — a hidden Save button. `JournalEntry::booted()` carried only a `creating` hook (number,
 * `is_manual`); `JournalLine::booted()` carried only the NOT-NULL coercion. So the general ledger
 * protected itself at layer 3, weakly, while every module in this batch was being made to protect
 * its own documents at layer 1.
 *
 * The consequences are worse here than anywhere else in the sweep, because they are not confined to
 * one document:
 *
 *   - **an unbalanced entry.** Edit one line's amount, or add a line, or delete one, and debits no
 *     longer equal credits. The trial balance stops balancing and every report built on it — the
 *     balance sheet, the P&L, the owner statements — is wrong at once, with nothing naming which
 *     entry did it;
 *   - **a restated closed period.** `entry_date` decides which period an entry belongs to. Moving it
 *     walks the amount into another month — including a month that has been closed, reported and
 *     shown to an owner — which is the exact divergence `PostingDate` exists to stop, arriving from
 *     inside the ledger instead of from a source document;
 *   - **money moved with no trail.** Changing a line's `ledger_account_id` re-homes an amount
 *     between accounts, leaving both wrong and nothing recording that it happened.
 *
 * The correction path already exists and is the one the module documents: `void()` posts a balanced
 * reversing entry (قيد عكسي) and you post a fresh one. So freezing costs an operator nothing — this
 * is not the case where a lock would trap someone, which is why it can be an outright refusal.
 *
 * DRAFT entries stay fully editable: a draft is not on the books yet, which is the entire
 * distinction the status carries.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    $this->asset = makeAsset(['code' => 'GL1']);
    $accounts = app(AccountResolver::class);

    $this->entry = app(JournalPostingService::class)->post([
        'entry_date' => now()->toDateString(),
        'description_en' => 'Manual accrual',
        'asset_id' => $this->asset->id,
        'status' => 'posted',
        'lines' => [
            ['ledger_account_id' => $accounts->id('cash'), 'debit' => 5000, 'credit' => 0],
            ['ledger_account_id' => $accounts->id('misc_income'), 'debit' => 0, 'credit' => 5000],
        ],
    ]);
});

it('refuses to change a posted entry\'s date, which is the period it belongs to', function () {
    expect(fn () => $this->entry->fresh()->update(['entry_date' => now()->subYear()->toDateString()]))
        ->toThrow(DomainException::class);
});

it('refuses to change a posted entry\'s property or status', function () {
    $other = makeAsset(['code' => 'GL2']);

    expect(fn () => $this->entry->fresh()->update(['asset_id' => $other->id]))
        ->toThrow(DomainException::class);

    expect(fn () => $this->entry->fresh()->update(['status' => 'draft']))
        ->toThrow(DomainException::class);
});

it('refuses to re-price a line on a posted entry — the unbalanced-ledger case', function () {
    $line = $this->entry->fresh()->lines()->first();

    expect(fn () => $line->update(['debit' => 9999]))->toThrow(DomainException::class);

    // The books still balance, which is the assertion that actually matters.
    expect(app(LedgerReportService::class)->trialBalance()['balanced'])->toBeTrue();
});

it('refuses to add a line to a posted entry', function () {
    $cash = app(AccountResolver::class)->id('cash');

    expect(fn () => $this->entry->fresh()->lines()->create([
        'ledger_account_id' => $cash, 'debit' => 1000, 'credit' => 0,
    ]))->toThrow(DomainException::class);

    expect(app(LedgerReportService::class)->trialBalance()['balanced'])->toBeTrue();
});

it('refuses to remove a line from a posted entry', function () {
    expect(fn () => $this->entry->fresh()->lines()->first()->delete())
        ->toThrow(DomainException::class);

    expect($this->entry->fresh()->lines()->count())->toBe(2)
        ->and(app(LedgerReportService::class)->trialBalance()['balanced'])->toBeTrue();
});

it('refuses to re-home a line to another account', function () {
    $line = $this->entry->fresh()->lines()->first();
    $other = app(AccountResolver::class)->id('misc_income');

    expect(fn () => $line->update(['ledger_account_id' => $other]))
        ->toThrow(DomainException::class);
});

it('still lets a DRAFT entry be built and corrected', function () {
    // The control the six refusals need: a draft is not on the books, which is the whole
    // distinction the status carries. Without this they would pass just as happily if the ledger
    // were frozen outright and manual entries were unusable.
    $accounts = app(AccountResolver::class);

    $draft = app(JournalPostingService::class)->post([
        'entry_date' => now()->toDateString(),
        'description_en' => 'Draft accrual',
        'asset_id' => $this->asset->id,
        'status' => 'draft',
        'lines' => [
            ['ledger_account_id' => $accounts->id('cash'), 'debit' => 1000, 'credit' => 0],
            ['ledger_account_id' => $accounts->id('misc_income'), 'debit' => 0, 'credit' => 1000],
        ],
    ]);

    expect(fn () => $draft->update(['description_en' => 'Draft accrual (revised)']))
        ->not->toThrow(DomainException::class);

    $line = $draft->fresh()->lines()->first();
    expect(fn () => $line->update(['debit' => 1200]))->not->toThrow(DomainException::class);
    expect(fn () => $line->delete())->not->toThrow(DomainException::class);
});

it('still allows the documented correction — void, then post a fresh entry', function () {
    // Freezing is only fair if the correction path exists. It does, and this is it.
    app(JournalPostingService::class)->void($this->entry->fresh());

    expect($this->entry->fresh()->status)->toBe('void')
        ->and(app(LedgerReportService::class)->trialBalance()['balanced'])->toBeTrue();
});

it('still allows posting a draft — the transition is not blocked by its own outcome', function () {
    $accounts = app(AccountResolver::class);

    $draft = app(JournalPostingService::class)->post([
        'entry_date' => now()->toDateString(),
        'description_en' => 'To be posted',
        'asset_id' => $this->asset->id,
        'status' => 'draft',
        'lines' => [
            ['ledger_account_id' => $accounts->id('cash'), 'debit' => 700, 'credit' => 0],
            ['ledger_account_id' => $accounts->id('misc_income'), 'debit' => 0, 'credit' => 700],
        ],
    ]);

    expect(fn () => app(JournalPostingService::class)->postDraft($draft))->not->toThrow(DomainException::class);
    expect($draft->fresh()->status)->toBe('posted');
});
