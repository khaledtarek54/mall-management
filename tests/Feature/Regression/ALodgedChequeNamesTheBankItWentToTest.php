<?php

use App\Models\BankAccount;
use App\Models\PostDatedCheque;
use App\Services\Accounting\MintBankLedgerAccountService;
use App\Services\PostDatedChequeService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * **A post-dated cheque is lodged with a bank, and the register must say which one.**
 *
 * `post_dated_cheques.bank_name` has always been the DRAWER's bank — the tenant's, printed on the
 * face of the cheque. Nothing recorded the other side: which of the operator's own accounts the
 * cheque was presented to. `deposited` has been one of the five statuses since the register shipped,
 * so it could say a cheque was *at a bank* and never which.
 *
 * **Yardi captures the bank at LODGEMENT and treats clearing as its confirmation**, because the bank
 * belongs to the physical act — one piece of paper, one branch — and it is known on the day. Atriom
 * inferred it months later at clearing, from whichever property happened to be on screen. That is
 * not cosmetic: `MatchBankStatementLineService::candidatesFor()` finds candidates by the chart
 * account behind the bank, so a cheque banked at NBE and cleared under CIB becomes a CIB candidate
 * and the operator matches money against a statement it never appeared on.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    $this->seed(PaymentMethodSeeder::class);

    $this->asset = makeAsset(['code' => 'PDC']);
    $this->tenant = makeTenant(['name' => 'Cheque Retail']);

    $mint = app(MintBankLedgerAccountService::class);

    // TWO accounts, and the second is deliberately NOT the property default — otherwise every
    // assertion below would pass on the fallback rather than on the lodgement, which is the exact
    // false pass this session already shipped once.
    $this->cib = BankAccount::create([
        'asset_id' => $this->asset->id, 'name' => 'CIB — operating', 'account_number' => 'PDC-1',
        'purpose' => BankAccount::PURPOSE_OPERATING, 'is_default' => true,
        'ledger_account_id' => $mint->mint('CIB', $this->asset->id)?->id,
    ]);

    $this->nbe = BankAccount::create([
        'asset_id' => $this->asset->id, 'name' => 'NBE — collections', 'account_number' => 'PDC-2',
        'purpose' => BankAccount::PURPOSE_OPERATING, 'is_default' => false,
        'ledger_account_id' => $mint->mint('NBE', $this->asset->id)?->id,
    ]);

    $this->cheque = PostDatedCheque::create([
        'reference' => PostDatedCheque::generateReference(),
        'asset_id' => $this->asset->id,
        'tenant_id' => $this->tenant->id,
        'cheque_number' => 'CHQ-0001',
        'bank_name' => 'Banque Misr',   // the DRAWER's bank — the tenant's, not ours
        'amount' => 25000,
        'currency' => 'EGP',
        'cheque_date' => now()->subDay()->toDateString(),
        'received_date' => now()->subMonth()->toDateString(),
    ]);
});

/** A cheque in the drawer has not been to a bank, and the register must not pretend otherwise. */
it('holds a cheque without inventing a bank for it', function () {
    expect($this->cheque->bank_account_id)->toBeNull()
        ->and($this->cheque->deposited_on)->toBeNull()
        ->and($this->cheque->status)->toBe(PostDatedCheque::STATUS_HELD);
});

it('records which bank it was lodged with, and when', function () {
    $on = now()->subDays(3)->toDateString();

    app(PostDatedChequeService::class)->deposit($this->cheque, $this->nbe->id, $on);

    $fresh = $this->cheque->fresh();

    expect($fresh->status)->toBe(PostDatedCheque::STATUS_DEPOSITED)
        ->and($fresh->bank_account_id)->toBe($this->nbe->id)
        ->and($fresh->deposited_on?->toDateString())->toBe($on)
        // The drawer's bank is a different fact and is untouched.
        ->and($fresh->bank_name)->toBe('Banque Misr');
});

/**
 * The clearing receipt banks WHERE THE CHEQUE WENT — not where the operator happens to be.
 *
 * This is the whole point, and the assertion is only meaningful because NBE is not the property
 * default: with the lodgement ignored the receipt would land on CIB through
 * `RecordsBankAccount`'s fallback and the test would pass for the wrong reason.
 */
it('clears into the account the cheque was lodged with, not the property default', function () {
    app(PostDatedChequeService::class)->deposit($this->cheque, $this->nbe->id);

    app(PostDatedChequeService::class)->clear($this->cheque->fresh(), makeUser('accounting'));

    $payment = $this->cheque->fresh()->clearedPayment;

    expect($payment)->not->toBeNull()
        ->and($payment->bank_account_id)->toBe($this->nbe->id)
        ->and($payment->bank_account_id)->not->toBe($this->cib->id);
});

/** An install that registered no account still lodges and clears exactly as it did before. */
it('still lodges and clears when no bank account is named', function () {
    app(PostDatedChequeService::class)->deposit($this->cheque);

    expect($this->cheque->fresh()->bank_account_id)->toBeNull()
        ->and($this->cheque->fresh()->deposited_on)->not->toBeNull();

    app(PostDatedChequeService::class)->clear($this->cheque->fresh(), makeUser('accounting'));

    expect($this->cheque->fresh()->status)->toBe(PostDatedCheque::STATUS_CLEARED);
});

/** "I did not say" must not erase "it went to NBE". */
it('keeps the lodgement bank when a re-present names none', function () {
    $service = app(PostDatedChequeService::class);

    $service->deposit($this->cheque, $this->nbe->id);
    $service->bounce($this->cheque->fresh());
    $service->deposit($this->cheque->fresh());

    expect($this->cheque->fresh()->bank_account_id)->toBe($this->nbe->id);

    // …and a re-present that DOES name one moves it, because a bounced cheque is commonly put
    // through a different account.
    $service->bounce($this->cheque->fresh());
    $service->deposit($this->cheque->fresh(), $this->cib->id);

    expect($this->cheque->fresh()->bank_account_id)->toBe($this->cib->id);
});

/** You either handed it over or you did not. */
it('refuses a lodgement dated in the future', function () {
    expect(fn () => app(PostDatedChequeService::class)
        ->deposit($this->cheque, $this->nbe->id, now()->addDay()->toDateString()))
        ->toThrow(DomainException::class);

    expect($this->cheque->fresh()->status)->toBe(PostDatedCheque::STATUS_HELD);
});

/**
 * A cheque taken at Mall A cannot be lodged into Mall B's account — that would put Mall A's
 * collection into Mall B's bank, which balances and is wrong. The guard comes from
 * `RecordsBankAccount`, which the model now uses for this and NOT for defaulting.
 */
it('refuses another mall\'s account', function () {
    $other = makeAsset(['code' => 'PDX']);

    $foreign = BankAccount::create([
        'asset_id' => $other->id, 'name' => 'Other mall', 'account_number' => 'PDX-1',
        'purpose' => BankAccount::PURPOSE_OPERATING,
    ]);

    expect(fn () => app(PostDatedChequeService::class)->deposit($this->cheque, $foreign->id))
        ->toThrow(DomainException::class);
});
