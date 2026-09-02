<?php

use App\Models\BankAccount;
use App\Models\JournalEntry;
use App\Models\Expense;
use App\Models\Payment;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerPoster;
use App\Services\Accounting\MintBankLedgerAccountService;
use App\Support\MoneyDocumentDoors;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Facades\DB;

/**
 * SW-228 — every receipt taken online fell to the generic `bank` posting role.
 *
 * `RecordsBankAccount` fills a money document's bank account in from
 * `asset_id ?? bill?->asset_id ?? TenantScope::currentAssetId()`. The first two are facts the ROW
 * carries. The third is the mall the operator happens to be looking at — and **`payments` has no
 * `asset_id` column at all**, deliberately: a receipt's books dimension comes from the invoices it
 * settles, which do not exist yet at `creating`.
 *
 * So on a gateway callback or an API request, where there is no selected mall, the fallback answered
 * null and the receipt named no account. `MoneyAccount::for()` then falls to the `bank` POSTING
 * ROLE, which is where money **nobody attributed** lands — and
 * `MatchBankStatementLineService::candidatesFor()` finds reconciliation candidates BY the chart
 * account, so a named bank's postings were offered alongside every unattributed receipt. That is
 * exactly the state the bank register was built to end.
 *
 * It was not an edge: `PaymobPaymentInitiator` and `RecordDemoPaymentAction` are the whole online
 * CARD channel — the highest volume of inbound receipts on a live install, and the ones that land in
 * the mall's operating account. (A merchant settlement account is registered as an ordinary
 * `operating` one — `BankAccount::PURPOSES` deliberately models no settlement kind.)
 * `PaymentMethod::requiresBankAccount('card')` falls through to `code !== 'cash'` unless the
 * operator unticks it, and BOTH creators ask through `Payment::defaultBankAccountIdFor()` so they
 * cannot go on stamping an account the panel has stopped asking for.
 *
 * The invoice knows the mall, so naming the account is a derivation rather than a guess.
 */
beforeEach(function () {
    config([
        'integrations.paymob.enabled' => false,
        'integrations.demo_payments.enabled' => true,
    ]);

    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'OLB']);
    $this->tenant = makeTenant();
    $this->lease = makeLease(makeUnit($this->asset), $this->tenant);
    $this->invoice = makeInvoice($this->lease, ['total' => 500, 'balance' => 500, 'status' => 'issued']);

    // The mall's own bank, with its own chart leaf — minted through the service the picker's create
    // button calls, so the arrangement is one the running system would produce.
    $this->bank = BankAccount::create([
        'asset_id' => $this->asset->id,
        'name' => 'Operating account',
        'bank_name' => 'CIB',
        'account_number' => '100200300',
        'currency' => 'EGP',
        'purpose' => BankAccount::PURPOSE_OPERATING,
        'is_default' => true,
        'is_active' => true,
        'ledger_account_id' => app(MintBankLedgerAccountService::class)->mint('Operating account')?->id,
    ]);
});

it('names the mall bank account on a receipt taken with no operator in the room', function () {
    // No `Filament::setTenant()` anywhere — that is the whole point. This is an API request.
    $this->post("/pay/{$this->invoice->paymentLinkToken()}/demo")->assertRedirect();

    $payment = Payment::latest('id')->firstOrFail();

    // Measured before the fix: null, and the entry credited the generic `bank` role account.
    expect($payment->bank_account_id)->toBe($this->bank->id);
});

it('posts that receipt to the bank\'s OWN chart account, not the catch-all role', function () {
    // The reason the column matters. A posting role account is where documents naming NO account
    // land; a real bank pointed at it merges "money we know went through CIB" with "money nobody
    // attributed", and the reconciliation screen then offers one as a candidate for the other.
    $this->post("/pay/{$this->invoice->paymentLinkToken()}/demo")->assertRedirect();

    $payment = Payment::latest('id')->firstOrFail();

    // Posted through the real service, not by writing journal rows — the rule module 21 states: a
    // test that calls `post()` directly proves only the journalizer's arithmetic.
    app(LedgerPoster::class)->sync($payment->fresh());

    $lines = JournalEntry::query()
        ->where('source_type', $payment->getMorphClass())
        ->where('source_id', $payment->id)
        ->where('status', 'posted')
        ->with('lines')
        ->get()
        ->flatMap->lines;

    expect($lines)->not->toBeEmpty('the receipt never posted, so this proves nothing')
        ->and($lines->pluck('ledger_account_id'))->toContain($this->bank->ledger_account_id);

    // …and the catch-all role carries none of it.
    $role = DB::table('account_mappings')->where('key', 'bank')->value('ledger_account_id');

    // Asserted non-null first: unmapped, `(int) null` is 0, no line ever carries 0, and the
    // exclusion below would pass while proving nothing.
    expect($role)->not->toBeNull('the `bank` posting role is unmapped, so this proves nothing')
        ->and($lines->pluck('ledger_account_id'))->not->toContain((int) $role);
});

it('leaves the receipt unattributed when the mall has no bank account, rather than inventing one', function () {
    // A CONTROL, not a third proof: it passes with the fix removed too, because the old behaviour
    // was to name nothing at all. What it pins is the opposite mistake — an install that has not
    // reached the bank register must still be able to take money, and a resolver that cannot know
    // must not guess. `delete()` soft-deletes, and `defaultFor()` queries through `active()`, whose
    // global scope excludes trashed rows.
    $this->bank->delete();

    $this->post("/pay/{$this->invoice->paymentLinkToken()}/demo")->assertRedirect();

    expect(Payment::latest('id')->firstOrFail()->bank_account_id)->toBeNull();
});

it('has the registry name the receipt among the documents that cannot self-default', function () {
    // The premise behind all of the above, asserted rather than assumed: `payments` has no
    // `asset_id` column, so nothing about the row can answer which mall the money belongs to.
    // The supplier payment is in the same position — the gate covers both — and the rest of the
    // rail-carrying documents are not, because they carry the column and default from it.
    expect(MoneyDocumentDoors::documentsThatCannotSelfDefault())->toHaveKey(Payment::class)
        ->and(MoneyDocumentDoors::documentsWithARail())->toHaveKey(Expense::class)
        ->and(MoneyDocumentDoors::documentsThatCannotSelfDefault())->not->toHaveKey(Expense::class);
});
