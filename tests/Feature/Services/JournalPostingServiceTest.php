<?php

use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\JournalPostingService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    $this->post = app(JournalPostingService::class);
    $this->r = app(AccountResolver::class);
});

/** Helper: an invoice-style balanced payload (Dr AR / Cr rent + VAT). */
function invoicePayload(AccountResolver $r, array $overrides = []): array
{
    return array_merge([
        'entry_date' => now()->toDateString(),
        'description_en' => 'Test entry',
        'lines' => [
            ['ledger_account_id' => $r->id('accounts_receivable'), 'debit' => 1140, 'credit' => 0],
            ['ledger_account_id' => $r->id('rent_revenue'), 'debit' => 0, 'credit' => 1000],
            ['ledger_account_id' => $r->id('vat_payable'), 'debit' => 0, 'credit' => 140],
        ],
    ], $overrides);
}

it('posts a balanced entry with an auto-generated number', function () {
    $je = $this->post->post(invoicePayload($this->r));

    expect($je->status)->toBe('posted');
    expect($je->isBalanced())->toBeTrue();
    expect($je->lines)->toHaveCount(3);
    expect($je->number)->toStartWith('JE-');
    expect($je->accounting_period_id)->not->toBeNull();
    expect($je->totalDebit())->toEqualWithDelta(1140.0, 0.001);
});

it('rejects an unbalanced entry', function () {
    expect(fn () => $this->post->post([
        'lines' => [
            ['ledger_account_id' => $this->r->id('cash'), 'debit' => 100, 'credit' => 0],
            ['ledger_account_id' => $this->r->id('rent_revenue'), 'debit' => 0, 'credit' => 50],
        ],
    ]))->toThrow(DomainException::class);

    expect(JournalEntry::count())->toBe(0); // nothing persisted
});

it('rejects posting to a non-postable summary account', function () {
    expect(fn () => $this->post->post([
        'lines' => [
            ['account_code' => '1', 'debit' => 100, 'credit' => 0], // "Assets" summary
            ['ledger_account_id' => $this->r->id('cash'), 'debit' => 0, 'credit' => 100],
        ],
    ]))->toThrow(DomainException::class);
});

it('rejects a line that is both debit and credit', function () {
    expect(fn () => $this->post->post([
        'lines' => [
            ['ledger_account_id' => $this->r->id('cash'), 'debit' => 100, 'credit' => 100],
            ['ledger_account_id' => $this->r->id('rent_revenue'), 'debit' => 0, 'credit' => 100],
        ],
    ]))->toThrow(DomainException::class);
});

it('rejects negative amounts', function () {
    expect(fn () => $this->post->post([
        'lines' => [
            ['ledger_account_id' => $this->r->id('cash'), 'debit' => -100, 'credit' => 0],
            ['ledger_account_id' => $this->r->id('rent_revenue'), 'debit' => 0, 'credit' => -100],
        ],
    ]))->toThrow(DomainException::class);
});

it('refuses to post into a closed period', function () {
    $period = AccountingPeriod::forDate(now());
    $period->update(['status' => 'closed']);

    expect(fn () => $this->post->post(invoicePayload($this->r)))
        ->toThrow(DomainException::class, 'closed');
});

it('is idempotent per source document', function () {
    $source = makeAsset();

    $first = $this->post->post(invoicePayload($this->r, ['source' => $source]));
    $second = $this->post->post(invoicePayload($this->r, ['source' => $source]));

    expect($second->id)->toBe($first->id);
    expect(JournalEntry::count())->toBe(1);
});

it('voids a posted entry by creating a balanced reversal', function () {
    $je = $this->post->post(invoicePayload($this->r));

    $reversal = $this->post->void($je, 'mistake');

    expect($je->fresh()->status)->toBe('void');
    expect($reversal->reversal_of_id)->toBe($je->id);
    expect($reversal->isBalanced())->toBeTrue();
    // The reversal swaps sides: original total debit becomes total credit.
    expect($reversal->totalCredit())->toEqualWithDelta($je->totalDebit(), 0.001);
});

it('refuses to void a draft (only posted entries can be voided)', function () {
    $je = JournalEntry::create(['entry_date' => now(), 'status' => 'draft', 'is_manual' => true]);
    $je->lines()->create(['ledger_account_id' => $this->r->id('cash'), 'debit' => 100, 'credit' => 0]);
    $je->lines()->create(['ledger_account_id' => $this->r->id('rent_revenue'), 'debit' => 0, 'credit' => 100]);

    expect(fn () => $this->post->void($je->fresh()))->toThrow(DomainException::class);
    expect($je->fresh()->status)->toBe('draft');
    expect(JournalEntry::count())->toBe(1); // no phantom reversal created
});

it('postDraft posts a balanced saved draft', function () {
    $je = JournalEntry::create(['entry_date' => now(), 'status' => 'draft', 'is_manual' => true]);
    $je->lines()->create(['ledger_account_id' => $this->r->id('cash'), 'debit' => 200, 'credit' => 0]);
    $je->lines()->create(['ledger_account_id' => $this->r->id('rent_revenue'), 'debit' => 0, 'credit' => 200]);

    $posted = $this->post->postDraft($je->fresh());

    expect($posted->status)->toBe('posted');
    expect($posted->accounting_period_id)->not->toBeNull();
});

it('postDraft rejects an unbalanced saved draft', function () {
    $je = JournalEntry::create(['entry_date' => now(), 'status' => 'draft', 'is_manual' => true]);
    $je->lines()->create(['ledger_account_id' => $this->r->id('cash'), 'debit' => 200, 'credit' => 0]);
    $je->lines()->create(['ledger_account_id' => $this->r->id('rent_revenue'), 'debit' => 0, 'credit' => 150]);

    expect(fn () => $this->post->postDraft($je->fresh()))->toThrow(DomainException::class);
    expect($je->fresh()->status)->toBe('draft'); // unchanged
});

it('void() refuses and creates no reversal when no open period exists', function () {
    $je = $this->post->post(invoicePayload($this->r));
    app(\App\Services\Accounting\PeriodService::class)->closePeriod(AccountingPeriod::forDate(now()));

    $before = JournalEntry::count();
    expect(fn () => $this->post->void($je->fresh()))->toThrow(\DomainException::class);
    expect(JournalEntry::count())->toBe($before);
    expect($je->fresh()->status)->toBe('posted');
});

it('postDraft() no-ops a posted entry and refuses a voided one', function () {
    $posted = $this->post->post(invoicePayload($this->r));
    expect($this->post->postDraft($posted->fresh())->id)->toBe($posted->id); // already posted → no-op

    $this->post->void($posted->fresh());
    expect(fn () => $this->post->postDraft($posted->fresh()))->toThrow(\DomainException::class);
});

it('rejects a single-line payload', function () {
    expect(fn () => $this->post->post([
        'entry_date' => now()->toDateString(),
        'lines' => [['ledger_account_id' => $this->r->id('accounts_receivable'), 'debit' => 100, 'credit' => 0]],
    ]))->toThrow(\DomainException::class);
});

it('rejects a line referencing an inactive account', function () {
    \App\Models\LedgerAccount::where('code', '41101001')->update(['is_active' => false]);
    $inactive = \App\Models\LedgerAccount::where('code', '41101001')->value('id');

    expect(fn () => $this->post->post([
        'entry_date' => now()->toDateString(),
        'lines' => [
            ['ledger_account_id' => $this->r->id('accounts_receivable'), 'debit' => 1000, 'credit' => 0],
            ['ledger_account_id' => $inactive, 'debit' => 0, 'credit' => 1000],
        ],
    ]))->toThrow(\DomainException::class);
});
