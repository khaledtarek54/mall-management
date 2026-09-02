<?php

use App\Models\AccountMapping;
use App\Models\BankAccount;
use App\Models\LedgerAccount;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\MintBankLedgerAccountService;
use App\Support\ConfigurationHealth;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * **A bank account gets a chart account of its own — the market standard, and the thing that makes
 * bank reconciliation mean anything.**
 *
 * Yardi's Bank record points at one cash GL account and its reconciliation is OF that account;
 * NetSuite, QuickBooks and Odoo each make a bank account its own GL account, and Odoo creates the
 * account for you when you add the bank.
 *
 * The mechanical reason here is `MatchBankStatementLineService::candidatesFor()`, which finds
 * candidates with `where('ledger_account_id', …)`. Two banks on one chart account means reconciling
 * CIB OFFERS NBE's postings; the operator matches one, the statement balances, and the
 * reconciliation is wrong — worse than not reconciling, because a wrong match marks money verified.
 *
 * A POSTING ROLE account is the subtler half. The `bank` role is where every document naming NO bank
 * account lands, so pointing a real bank at it merges "money we know went through CIB" with "money
 * nobody attributed". `DemoSeeder` has been careful about this since the register was first seeded;
 * the FORM was not, which is how a real install ended up with its only bank on `11102001`.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);

    $this->asset = makeAsset(['code' => 'OWN']);
    $this->bankRole = app(AccountResolver::class)->id('bank', $this->asset->id);

    $this->freeLeaf = fn (int $n) => LedgerAccount::create([
        'code' => '119'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
        'name_en' => "Free leaf {$n}", 'name_ar' => "Free leaf {$n}",
        'type' => 'asset', 'is_postable' => true, 'is_active' => true,
    ]);
});

it('refuses a bank pointed at a posting role, and accepts one pointed anywhere else', function () {
    $free = ($this->freeLeaf)(1);

    expect(fn () => BankAccount::create([
        'asset_id' => $this->asset->id, 'name' => 'CIB', 'account_number' => 'OWN-1',
        'ledger_account_id' => $this->bankRole,
    ]))->toThrow(DomainException::class);

    // The control. Without it a guard that refused EVERY chart account would read as a pass.
    $ok = BankAccount::create([
        'asset_id' => $this->asset->id, 'name' => 'CIB', 'account_number' => 'OWN-1',
        'ledger_account_id' => $free->id,
    ]);

    expect($ok->ledger_account_id)->toBe($free->id);
});

it('refuses two banks on one chart account', function () {
    $free = ($this->freeLeaf)(2);

    BankAccount::create([
        'asset_id' => $this->asset->id, 'name' => 'CIB', 'account_number' => 'OWN-2',
        'ledger_account_id' => $free->id,
    ]);

    expect(fn () => BankAccount::create([
        'asset_id' => $this->asset->id, 'name' => 'NBE', 'account_number' => 'OWN-3',
        'ledger_account_id' => $free->id,
    ]))->toThrow(DomainException::class);
});

/**
 * The lockout control, and it is the reason the guard is dirty-aware.
 *
 * Every install predating this rule has a bank mapped somewhere, quite possibly at the role account
 * — which is exactly the state the advisory exists to report. Refusing on every save would make
 * those rows uneditable: the operator could not rename their own bank without first solving a chart
 * problem. That is the trap CLAUDE.md records for `#[NeverDeletable]` — guarding a row a workflow
 * legitimately touches breaks the workflow instead of protecting it.
 */
it('leaves a bank that was already mapped that way fully editable', function () {
    $legacy = BankAccount::create([
        'asset_id' => $this->asset->id, 'name' => 'Legacy', 'account_number' => 'OWN-4',
        'ledger_account_id' => ($this->freeLeaf)(3)->id,
    ]);

    // Simulating the pre-rule state: the row exists, pointed at the role account.
    BankAccount::withoutEvents(fn () => $legacy->forceFill(['ledger_account_id' => $this->bankRole])->save());

    $legacy->refresh()->update(['name' => 'Legacy renamed', 'purpose' => BankAccount::PURPOSE_DEPOSITS]);

    expect($legacy->fresh()->name)->toBe('Legacy renamed')
        ->and($legacy->fresh()->ledger_account_id)->toBe($this->bankRole);

    // …but MOVING it onto an account another bank already holds IS refused, so the rule bites the
    // moment the operator touches the mapping itself. (The first version of this assertion re-set
    // the SAME id and asserted no throw — which cannot fail, because the guard is dirty-aware. An
    // assertion that cannot fail reads exactly like one that passes.)
    $taken = ($this->freeLeaf)(9);

    BankAccount::create([
        'asset_id' => $this->asset->id, 'name' => 'Holder', 'account_number' => 'OWN-9',
        'ledger_account_id' => $taken->id,
    ]);

    expect(fn () => $legacy->update(['ledger_account_id' => $taken->id]))
        ->toThrow(DomainException::class);
});

/**
 * Odoo's behaviour: the chart account is minted for you, under wherever THIS install keeps banks.
 *
 * Anchored on the parent of the `bank` role account rather than a literal `11102`, because the real
 * Egyptian chart has not been supplied and any hardcoded code would be a guess about somebody else's
 * numbering. The width comes from the siblings, so an 8-digit chart and a 10-digit one each get a
 * leaf that looks like its neighbours.
 */
it('mints a dedicated leaf beside the ones already there', function () {
    $role = LedgerAccount::find($this->bankRole);
    $parent = $role->parent;

    $minted = app(MintBankLedgerAccountService::class)->mint('CIB — operating', $this->asset->id);

    expect($minted)->not->toBeNull()
        ->and($minted->parent_id)->toBe($parent->id)
        ->and($minted->is_postable)->toBeTrue()
        ->and($minted->type)->toBe('asset')
        // Beside the role account, never instead of it, and the same shape as its neighbours.
        ->and($minted->code)->not->toBe($role->code)
        ->and($minted->code)->toStartWith($parent->code)
        ->and(strlen($minted->code))->toBe(strlen($role->code));

    // And it is immediately usable, which is the whole point of offering it.
    $account = BankAccount::create([
        'asset_id' => $this->asset->id, 'name' => 'CIB', 'account_number' => 'OWN-5',
        'ledger_account_id' => $minted->id,
    ]);

    expect($account->ledger_account_id)->toBe($minted->id);

    // Twice in a row does not collide.
    expect(app(MintBankLedgerAccountService::class)->mint('NBE — service charge', $this->asset->id)?->code)
        ->not->toBe($minted->code);
});

/** With no `bank` role mapped the chart cannot say where banks live, and guessing is not on offer. */
it('refuses to invent a home for a bank when the chart has not said where banks go', function () {
    AccountMapping::query()->where('key', 'bank')->delete();
    app()->forgetInstance(AccountResolver::class);

    expect(app(MintBankLedgerAccountService::class)->mint('Nowhere', $this->asset->id))->toBeNull();
});

/**
 * The advisory reports the installs that predate the rule — reported, never refused.
 *
 * Paired with the clean control, because a check that fires on everything is no better than one
 * that fires on nothing.
 */
it('reports a bank sharing an account, and stays quiet when each has its own', function () {
    BankAccount::create([
        'asset_id' => $this->asset->id, 'name' => 'Own leaf', 'account_number' => 'OWN-6',
        'ledger_account_id' => ($this->freeLeaf)(4)->id,
    ]);

    $row = fn () => collect(ConfigurationHealth::run())->firstWhere('key', 'bank_accounts_have_their_own_account');

    expect($row()['ok'])->toBeTrue('A bank with its own leaf is not a finding.');

    $onTheRole = BankAccount::create([
        'asset_id' => $this->asset->id, 'name' => 'On the role', 'account_number' => 'OWN-7',
        'ledger_account_id' => ($this->freeLeaf)(5)->id,
    ]);

    BankAccount::withoutEvents(fn () => $onTheRole->forceFill(['ledger_account_id' => $this->bankRole])->save());

    expect($row()['ok'])->toBeFalse()
        ->and($row()['detail'])->toContain('On the role')
        // Advisory, never blocking: the books are correct, the reconciliation is merely ambiguous,
        // and a red BLOCKING row would stop a deploy over a chart preference.
        ->and($row()['severity'])->toBe(ConfigurationHealth::ADVISORY);
});

/** A bank naming NO chart account is a different, earlier question and must not be reported here. */
it('does not report a bank that names no chart account at all', function () {
    BankAccount::create([
        'asset_id' => $this->asset->id, 'name' => 'Unmapped', 'account_number' => 'OWN-8',
    ]);

    expect(collect(ConfigurationHealth::run())->firstWhere('key', 'bank_accounts_have_their_own_account')['ok'])
        ->toBeTrue();
});

/**
 * A RETIRED chart account still holds its code.
 *
 * `ledger_accounts.code` is a PLAIN unique index and `LedgerAccount` soft-deletes, so a retired
 * `…002` occupies that code while the SoftDeletes global scope hides it from an ordinary query. The
 * first cut of `mintLedgerAccount()` read the siblings without `withTrashed()` and would therefore
 * propose the code it had just freed *in appearance only* — a duplicate-key 500, on the button whose
 * entire job is to be the easy path. Found by reading the code, not by a failing test.
 */
it('does not re-propose the code of a retired account', function () {
    $first = app(MintBankLedgerAccountService::class)->mint('CIB', $this->asset->id);
    $first->delete();

    $second = app(MintBankLedgerAccountService::class)->mint('NBE', $this->asset->id);

    expect($second)->not->toBeNull()
        ->and($second->code)->not->toBe($first->code);
});
