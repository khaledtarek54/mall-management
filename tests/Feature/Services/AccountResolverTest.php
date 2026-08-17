<?php

use App\Models\AccountMapping;
use App\Models\LedgerAccount;
use App\Services\Accounting\AccountResolver;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    $this->r = app(AccountResolver::class);
});

it('prefers a per-asset override over the global default', function () {
    $asset = makeAsset();
    $override = LedgerAccount::where('code', '11202001')->first(); // Other Debtors

    AccountMapping::updateOrCreate(
        ['key' => 'accounts_receivable', 'asset_id' => $asset->id],
        ['ledger_account_id' => $override->id],
    );

    expect($this->r->id('accounts_receivable', $asset->id))->toBe($override->id); // per-asset wins
    expect($this->r->account('accounts_receivable', null)->code)->toBe('11201001'); // global default
    expect($this->r->account('accounts_receivable', makeAsset()->id)->code)->toBe('11201001'); // other asset → global
});

it('throws on an unmapped role', function () {
    expect(fn () => $this->r->account('role_that_does_not_exist'))->toThrow(DomainException::class);
});

it('throws when a mapping points to a non-postable (summary) account', function () {
    $summary = LedgerAccount::where('code', '4')->first(); // Revenue parent — not postable
    AccountMapping::updateOrCreate(['key' => 'misc_income', 'asset_id' => null], ['ledger_account_id' => $summary->id]);

    expect(fn () => $this->r->account('misc_income'))->toThrow(DomainException::class);
});
