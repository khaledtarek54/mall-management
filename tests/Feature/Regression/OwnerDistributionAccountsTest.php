<?php

use App\Models\LedgerAccount;
use App\Services\Accounting\AccountResolver;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * Slice 1 of the owner-statements module (docs/modules/32-owner-statements.md):
 * the two new chart accounts + mapping roles the GL journalizers post to. Pins the codes,
 * natures and postability so a chart edit can't silently re-point them. The generic
 * ChartOfAccountsConformanceTest already proves every mapping lands on a postable/active
 * account; this proves THESE two roles resolve to the accounts the posting recipe expects.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
});

it('resolves owner_distributions to the contra-equity draw account', function () {
    $account = app(AccountResolver::class)->account('owner_distributions');

    expect($account->code)->toBe('34101001')
        ->and($account->type)->toBe('equity')
        // equity ⇒ credit-normal (derived + gate-enforced); it carries a DEBIT balance as a
        // contra, exactly like a dividends/drawings account.
        ->and($account->normal_balance)->toBe('credit')
        ->and($account->is_postable)->toBeTrue()
        ->and($account->is_active)->toBeTrue();
});

it('resolves due_to_owner to the owner-payable liability under Due to Related Parties', function () {
    $account = app(AccountResolver::class)->account('due_to_owner');

    expect($account->code)->toBe('21802001')
        ->and($account->type)->toBe('liability')
        ->and($account->normal_balance)->toBe('credit')
        ->and($account->is_postable)->toBeTrue();

    // Parented under 218 "Due to Related Parties" (owners are related parties).
    $parentCode = LedgerAccount::whereKey($account->parent_id)->value('code');
    expect($parentCode)->toBe('21802');
    $grandParentCode = LedgerAccount::whereKey(
        LedgerAccount::whereKey($account->parent_id)->value('parent_id')
    )->value('code');
    expect($grandParentCode)->toBe('218');
});

it('keeps the owner-distributions equity branch as a non-postable summary above the leaf', function () {
    foreach (['34', '34101'] as $summaryCode) {
        $summary = LedgerAccount::where('code', $summaryCode)->first();
        expect($summary)->not->toBeNull("equity summary {$summaryCode} should exist")
            ->and($summary->type)->toBe('equity')
            ->and($summary->is_postable)->toBeFalse("summary {$summaryCode} must not accept journal lines");
    }
});
