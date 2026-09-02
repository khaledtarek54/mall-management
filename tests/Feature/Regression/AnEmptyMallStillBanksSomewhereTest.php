<?php

use App\Models\AccountMapping;
use App\Models\Asset;
use App\Models\BankAccount;
use App\Models\LedgerAccount;
use App\Services\Accounting\AccountResolver;
use Database\Seeders\ValPlazaSeeder;

/**
 * **The empty-mall seeder lays down a bank account, on a chart leaf of its own.**
 *
 * `LearningSeeder`'s rule is that an experiment's own numbers should be the only numbers on screen,
 * and a bank account adds no numbers — it is SETUP, exactly like the chart and the posting map the
 * seeder already lays down. Without one, the first receipt recorded in a demo falls to the generic
 * `bank` posting role, the money-form bank picker is EMPTY, and the requirement that a bank rail
 * names its account lifts itself because the register has nothing to offer. The demo would then
 * silently show the pre-2026-09-02 behaviour with nothing on screen to say so.
 *
 * Driven through `ValPlazaSeeder` rather than `LearningSeeder` because that is the subclass a
 * prospect's seeder is, and it proves the estate identity reaches the seeded account too.
 */
it('gives an empty mall one default bank account on a leaf nothing else uses', function () {
    $this->seed(ValPlazaSeeder::class);

    $asset = Asset::where('code', 'VP')->sole();
    $account = BankAccount::where('asset_id', $asset->id)->sole();

    expect($account->purpose)->toBe(BankAccount::PURPOSE_OPERATING)
        // THE default, so the first receipt anybody records arrives with its bank already filled.
        ->and($account->is_default)->toBeTrue()
        ->and($account->ledger_account_id)->not->toBeNull('The seeded bank maps to no chart account, so every posting still falls to the role.');

    // Never the posting role. `MatchBankStatementLineService` finds candidates BY the chart account
    // and the role is where documents naming NO bank land — seeding that arrangement would ship the
    // exact thing `BankAccount::assertLedgerAccountIsItsOwn()` refuses, and teach it as normal.
    expect(AccountMapping::where('ledger_account_id', $account->ledger_account_id)->exists())
        ->toBeFalse('The seeded bank points at a posting-role account.');

    // …and it sits where THIS chart keeps banks, beside the role account rather than instead of it.
    $role = LedgerAccount::find(app(AccountResolver::class)->id('bank', $asset->id));

    expect($account->ledgerAccount->parent_id)->toBe($role->parent_id)
        ->and($account->ledgerAccount->is_postable)->toBeTrue()
        ->and($account->ledgerAccount->code)->not->toBe($role->code);

    // The identity reaches it: a prospect's seeder is a SUBCLASS, so the chart leaf must carry that
    // prospect's name rather than the base seeder's.
    expect($account->ledgerAccount->name_en)->toContain('Val Plaza')
        ->and($account->ledgerAccount->name_ar)->toContain('Val Plaza');
});

/** Re-running must not grow a fresh chart leaf on every pass. */
it('does not add a second account or a second leaf when re-run', function () {
    $this->seed(ValPlazaSeeder::class);
    $first = BankAccount::sole();

    $this->seed(ValPlazaSeeder::class);

    expect(BankAccount::count())->toBe(1)
        ->and(BankAccount::sole()->ledger_account_id)->toBe($first->ledger_account_id);
});
