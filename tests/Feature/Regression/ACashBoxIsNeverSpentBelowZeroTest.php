<?php

use App\Filament\Admin\Resources\Expenses\Pages\CreateExpense;
use App\Models\AccountMapping;
use App\Models\BankAccount;
use App\Models\DepositTransaction;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\MarketingBudget;
use App\Models\MarketingSpend;
use App\Models\PaymentMethod;
use App\Models\Payroll;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Models\VendorBillPayment;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\LedgerPoster;
use App\Services\PayrollService;
use App\Settings\AccountingSettings;
use App\Support\CashBalanceGuard;
use App\Support\PropertySettings;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/**
 * Meeting 2026-09-02, point 16 — *"Sndo2 3am / bank menf3sh ykon da2n, lazm ykon fe amount fl
 * 7sab"*: a cash box or a bank account can never be in credit.
 *
 * Nothing guarded an outbound document against the balance of the account it paid from: an
 * expense, a supplier payment, a disbursement, a payroll run, an advance, a custody grant, a
 * deposit refund or an asset purchase could take a cash box below zero and post cleanly. SAP's
 * cash journal — the one benchmark that models a drawer — refuses that as a hard error; Yardi and
 * Odoo block neither cash nor bank. One seam, `App\Support\CashBalanceGuard`, asks the poster
 * what a save would MOVE and looks at the accounts it credits, so every posting source is covered
 * by being one. Two per-property settings, BOTH shipped OFF (Yardi's default — off still WARNS in
 * figures) and both the client's to SET: `refuse_overdrawn_cash` is SAP's rule for the drawer,
 * `refuse_overdrawn_bank` is the client's own "the bank can never be in credit". The refusing
 * cases below run under the client's rule for cash (`p16UnderTheClientsRule()`, as it is set on
 * staging); the shipped default has its own case.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    ensureAllPropertiesAsset();
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    $this->seed(PaymentMethodSeeder::class);
    app(FiscalCalendar::class)->ensureYear(2026);
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00'));

    $this->asset = makeAsset(['code' => 'CBX', 'name' => 'Cash Box Mall']);
    $this->other = makeAsset(['code' => 'CBY', 'name' => 'Other Mall']);
    $this->accounts = app(AccountResolver::class);
    $this->cash = $this->accounts->id('cash', $this->asset->id);
    $this->actingAs(makeUser('super_admin'));
});

/** The client's rule for the drawer, SET company-wide as it is on staging; the bank keeps the shipped default. */
function p16UnderTheClientsRule(): void
{
    // `refresh()` first: in the FIRST test of a process the settings object was loaded during boot,
    // before `migrate:fresh` wrote the newer rows, and spatie marks those properties as
    // default-loaded — a `save()` then refuses them as "missing". A reload from the migrated
    // table clears that mark; every later test in the process boots a fresh app and never sees it.
    app(AccountingSettings::class)->refresh()->fill(['refuse_overdrawn_cash' => true])->save();
}

/** Money INTO the property's cash box (or any account) on a date — the accountant's own entry. */
function p16Fund(int $assetId, int $accountId, float $amount, string $on): JournalEntry
{
    $resolver = app(AccountResolver::class);

    return app(JournalPostingService::class)->post([
        'entry_date' => $on,
        'asset_id' => $assetId,
        'description_en' => 'Cash brought in',
        'is_manual' => true,
        'lines' => [
            ['ledger_account_id' => $accountId, 'debit' => $amount, 'credit' => 0, 'asset_id' => $assetId],
            ['ledger_account_id' => $resolver->id('rent_revenue', $assetId), 'debit' => 0, 'credit' => $amount, 'asset_id' => $assetId],
        ],
    ]);
}

function p16Expense(int $assetId, float $amount, string $on, string $rail = 'cash', ?int $bank = null, array $attrs = []): Expense
{
    return Expense::create(array_merge([
        'asset_id' => $assetId,
        'expense_date' => $on,
        'category' => 'maintenance',
        'description' => 'Generator diesel',
        'amount' => $amount,
        'vat_amount' => 0,
        'total' => $amount,
        'status' => 'recorded',
        'paid_from' => $rail,
        'bank_account_id' => $bank,
    ], $attrs));
}

// ── The cash box ────────────────────────────────────────────────────────────────────────────

it('refuses a cash payment larger than the box holds at its date, naming the figures, and takes one that fits', function () {
    p16UnderTheClientsRule();
    p16Fund($this->asset->id, $this->cash, 1000, '2026-06-01');

    $refusal = __('admin.refusals.cash_box_overdrawn', [
        'property' => 'Cash Box Mall', 'date' => '10 June 2026', 'balance' => '1,000.00', 'amount' => '1,500.00', 'shortfall' => '500.00',
    ]);
    expect(fn () => p16Expense($this->asset->id, 1500, '2026-06-10'))->toThrow(DomainException::class, $refusal);
    expect(Expense::count())->toBe(0);

    // Control: within the balance — recorded and posted, and the box reads what is left.
    $ok = p16Expense($this->asset->id, 600, '2026-06-10');
    app(LedgerPoster::class)->sync($ok);
    expect(CashBalanceGuard::balanceOf($this->cash, $this->asset->id))->toBe(400.0);

    // The other mall's drawer is its own: the same account, another dimension, empty.
    expect(fn () => p16Expense($this->other->id, 100, '2026-06-10'))->toThrow(DomainException::class);
});

it('judges an edit on its INCREASE alone, and never refuses a void, a receipt or a non-money credit', function () {
    p16UnderTheClientsRule();
    p16Fund($this->asset->id, $this->cash, 1000, '2026-06-01');
    $expense = p16Expense($this->asset->id, 100, '2026-06-05');
    app(LedgerPoster::class)->sync($expense);
    // A marketing spend: an outbound document whose amount stays editable after posting (an
    // expense freezes its own — a different guard, not this one).
    $budget = MarketingBudget::create(['asset_id' => $this->asset->id, 'period_year' => 2026, 'accrued_amount' => 500000]);
    $spend = MarketingSpend::create(['marketing_budget_id' => $budget->id, 'category' => 'event', 'description' => 'Banner', 'amount' => 800, 'paid_from' => 'cash', 'spent_on' => '2026-06-10']);
    app(LedgerPoster::class)->sync($spend);

    // 800 → 900 costs the box 100 more, which is exactly what it has; 800 → 1,300 costs 500 more.
    $spend->fresh()->update(['amount' => 900]);
    expect((float) $spend->fresh()->amount)->toBe(900.0);
    app(LedgerPoster::class)->sync($spend->fresh());
    expect(CashBalanceGuard::balanceOf($this->cash, $this->asset->id))->toBe(0.0);
    expect(fn () => $spend->fresh()->update(['amount' => 1300]))->toThrow(DomainException::class);

    // A void REMOVES an outflow: never refused, even with the box already at zero.
    $expense->fresh()->update(['status' => 'cancelled']);
    expect($expense->fresh()->status)->toBe('cancelled');

    // A receipt DEBITS the box and is never in question — the guard reads outflows only. A source
    // document, so the guard sees it (a manual entry would be a control that cannot fail).
    $lease = makeLease(makeUnit($this->asset));
    $in = DepositTransaction::create(['lease_id' => $lease->id, 'tenant_id' => $lease->tenant_id, 'type' => 'receipt', 'amount' => 50, 'transaction_date' => '2026-06-12', 'method' => 'cash']);
    expect($in->exists)->toBeTrue();

    // A credit to a non-money account (AP, when a supplier bill is approved) is not a cash question.
    $vendor = Vendor::create(['name' => 'Diesel Co', 'type' => 'supplier', 'status' => 'active']);
    $bill = VendorBill::create([
        'vendor_id' => $vendor->id, 'asset_id' => $this->asset->id, 'reference' => 'D-1', 'category' => 'utilities',
        'status' => 'approved', 'bill_date' => '2026-06-10', 'due_date' => '2026-07-10',
        'subtotal' => 999999, 'vat_amount' => 0, 'total' => 999999, 'balance' => 999999,
    ]);
    expect($bill->exists)->toBeTrue();
});

it('reads the running balance from the payment\'s date onward, so a back-dated payment cannot leave a later day short', function () {
    p16UnderTheClientsRule();
    // 1,000 on the 1st, 900 spent on the 20th: the box shows 100 today. A payment of 500 back-dated
    // to the 5th "fits" on the 5th (1,000 there) and leaves the 20th at −400.
    p16Fund($this->asset->id, $this->cash, 1000, '2026-06-01');
    app(LedgerPoster::class)->sync(p16Expense($this->asset->id, 900, '2026-06-20'));

    expect(fn () => p16Expense($this->asset->id, 500, '2026-06-05'))
        ->toThrow(DomainException::class, __('admin.refusals.cash_box_overdrawn', [
            'property' => 'Cash Box Mall', 'date' => '5 June 2026', 'balance' => '100.00', 'amount' => '500.00', 'shortfall' => '400.00',
        ]));
    app(LedgerPoster::class)->sync(p16Expense($this->asset->id, 100, '2026-06-05'));

    // And TODAY's balance is not the answer either: 800 received on the 25th puts the box at 800
    // today, and a 500 back-dated to the 5th still leaves the 20th at −400 (the box read 0 there).
    p16Fund($this->asset->id, $this->cash, 800, '2026-06-25');
    expect(CashBalanceGuard::balanceOf($this->cash, $this->asset->id))->toBe(800.0);
    expect(fn () => p16Expense($this->asset->id, 500, '2026-06-05'))
        ->toThrow(DomainException::class, __('admin.refusals.cash_box_overdrawn', [
            'property' => 'Cash Box Mall', 'date' => '5 June 2026', 'balance' => '0.00', 'amount' => '500.00', 'shortfall' => '500.00',
        ]));
});

it('ships OFF for both — Yardi\'s default — and off still warns in figures; on is what the client sets', function () {
    // The shipped defaults, read off the settings class itself: a fresh install refuses nothing.
    expect((new ReflectionProperty(AccountingSettings::class, 'refuse_overdrawn_cash'))->getDefaultValue())->toBeFalse()
        ->and((new ReflectionProperty(AccountingSettings::class, 'refuse_overdrawn_bank'))->getDefaultValue())->toBeFalse();

    // And the migrated row says the same — nothing here switched it off.
    expect(app(AccountingSettings::class)->refuse_overdrawn_cash)->toBeFalse()
        ->and(app(AccountingSettings::class)->refuse_overdrawn_bank)->toBeFalse();

    p16Fund($this->asset->id, $this->cash, 1000, '2026-06-01');

    session()->forget('filament.notifications');
    $expense = p16Expense($this->asset->id, 1500, '2026-06-10');
    expect($expense->exists)->toBeTrue();
    $notified = collect(session('filament.notifications', []));
    expect($notified)->toHaveCount(1)
        ->and($notified->first()['title'])->toBe(__('admin.notifications.cash_box_overdrawn_title'))
        ->and($notified->first()['body'])->toBe(__('admin.notifications.cash_box_overdrawn_body', [
            'property' => 'Cash Box Mall', 'date' => '10 June 2026', 'balance' => '1,000.00', 'amount' => '1,500.00', 'shortfall' => '500.00',
        ]));
});

it('is a per-property setting: switched off for one mall, the payment is recorded and the operator warned in figures', function () {
    p16UnderTheClientsRule();
    p16Fund($this->asset->id, $this->cash, 1000, '2026-06-01');
    PropertySettings::set('accounting.refuse_overdrawn_cash', $this->asset->id, false);

    session()->forget('filament.notifications');
    $expense = p16Expense($this->asset->id, 1500, '2026-06-10');
    expect($expense->exists)->toBeTrue();

    $notified = collect(session('filament.notifications', []));
    expect($notified)->toHaveCount(1)
        ->and($notified->first()['title'])->toBe(__('admin.notifications.cash_box_overdrawn_title'))
        ->and($notified->first()['body'])->toBe(__('admin.notifications.cash_box_overdrawn_body', [
            'property' => 'Cash Box Mall', 'date' => '10 June 2026', 'balance' => '1,000.00', 'amount' => '1,500.00', 'shortfall' => '500.00',
        ]));

    // The other mall keeps the company's rule and still refuses.
    p16Fund($this->other->id, $this->cash, 100, '2026-06-01');
    expect(fn () => p16Expense($this->other->id, 150, '2026-06-10'))->toThrow(DomainException::class);
});

// ── The bank ────────────────────────────────────────────────────────────────────────────────

it('warns on an overdrawn bank by default, and refuses once the property says so', function () {
    $leaf = LedgerAccount::create(['code' => '11900201', 'name_en' => 'CIB — CBX', 'name_ar' => 'CIB — CBX', 'type' => 'asset', 'is_postable' => true, 'is_active' => true]);
    $bank = BankAccount::create(['asset_id' => $this->asset->id, 'name' => 'CIB — operating', 'account_number' => 'CBX-1', 'purpose' => BankAccount::PURPOSE_OPERATING, 'is_default' => true, 'ledger_account_id' => $leaf->id]);
    p16Fund($this->asset->id, $leaf->id, 2000, '2026-06-01');

    // Yardi's default: recorded, warned.
    session()->forget('filament.notifications');
    $paid = p16Expense($this->asset->id, 2500, '2026-06-10', 'bank_transfer', $bank->id);
    expect($paid->exists)->toBeTrue();
    $notified = collect(session('filament.notifications', []));
    expect($notified)->toHaveCount(1)
        ->and($notified->first()['title'])->toBe(__('admin.notifications.bank_overdrawn_title'))
        ->and($notified->first()['body'])->toBe(__('admin.notifications.bank_overdrawn_body', [
            'account' => 'CIB — CBX', 'property' => 'Cash Box Mall', 'date' => '10 June 2026', 'balance' => '2,000.00', 'amount' => '2,500.00', 'shortfall' => '500.00',
        ]));
    app(LedgerPoster::class)->sync($paid);
    expect(CashBalanceGuard::balanceOf($leaf->id, $this->asset->id))->toBe(-500.0);

    // The client's rule, SET per property: refused, in words.
    PropertySettings::set('accounting.refuse_overdrawn_bank', $this->asset->id, true);
    expect(fn () => p16Expense($this->asset->id, 10, '2026-06-11', 'bank_transfer', $bank->id))
        ->toThrow(DomainException::class, __('admin.refusals.bank_overdrawn', [
            'account' => 'CIB — CBX', 'date' => '11 June 2026', 'balance' => '-500.00', 'amount' => '10.00', 'shortfall' => '510.00',
        ]));

    // A RECEIPT into the overdrawn account is money IN and is never in question — even one too
    // small to lift it: the guard reads outflows only. A source document dimensioned to THIS
    // property (a deposit receipt — a bare `Payment` carries no property until allocated), so the
    // guard sees it on the same leaf and the same dimension it just refused on.
    $lease = makeLease(makeUnit($this->asset));
    $receipt = DepositTransaction::create(['lease_id' => $lease->id, 'tenant_id' => $lease->tenant_id, 'type' => 'receipt', 'amount' => 10, 'transaction_date' => '2026-06-11', 'method' => 'bank', 'bank_account_id' => $bank->id]);
    expect($receipt->exists)->toBeTrue();

    // A receipt lifts it; the same payment then fits.
    p16Fund($this->asset->id, $leaf->id, 600, '2026-06-11');
    expect(p16Expense($this->asset->id, 10, '2026-06-11', 'bank_transfer', $bank->id)->exists)->toBeTrue();
});

it('classifies a supplier payment through its bank, its rail\'s account or the bank role — and ignores everything else', function () {
    p16UnderTheClientsRule();
    $leaf = LedgerAccount::create(['code' => '11900202', 'name_en' => 'NBE — CBX', 'name_ar' => 'NBE — CBX', 'type' => 'asset', 'is_postable' => true, 'is_active' => true]);
    $bank = BankAccount::create(['asset_id' => $this->asset->id, 'name' => 'NBE', 'account_number' => 'CBX-2', 'purpose' => BankAccount::PURPOSE_OPERATING, 'is_default' => true, 'ledger_account_id' => $leaf->id]);

    // A RAIL pointed at its own chart account (EG-11: `payment_methods.ledger_account_id`) is
    // bank money too — an InstaPay wallet account is not a drawer.
    $wallet = LedgerAccount::create(['code' => '11900203', 'name_en' => 'InstaPay wallet', 'name_ar' => 'InstaPay wallet', 'type' => 'asset', 'is_postable' => true, 'is_active' => true]);
    PaymentMethod::query()->where('code', 'instapay')->update(['ledger_account_id' => $wallet->id]);

    expect(CashBalanceGuard::kindOf($this->cash, $this->asset->id))->toBe('cash_box')
        ->and(CashBalanceGuard::kindOf($leaf->id, $this->asset->id))->toBe('bank')
        ->and(CashBalanceGuard::kindOf($wallet->id, $this->asset->id))->toBe('bank')
        ->and(CashBalanceGuard::kindOf($this->accounts->id('bank', $this->asset->id), $this->asset->id))->toBe('bank')
        ->and(CashBalanceGuard::kindOf($this->accounts->id('accounts_payable', $this->asset->id), $this->asset->id))->toBeNull()
        ->and(CashBalanceGuard::kindOf($this->accounts->id('rent_revenue', $this->asset->id), $this->asset->id))->toBeNull();

    // A supplier payment from the bank is the same guard through another source: refused once the
    // property says so, from the bank's OWN leaf.
    PropertySettings::set('accounting.refuse_overdrawn_bank', $this->asset->id, true);
    $vendor = Vendor::create(['name' => 'Lift Co', 'type' => 'supplier', 'status' => 'active']);
    $bill = VendorBill::create([
        'vendor_id' => $vendor->id, 'asset_id' => $this->asset->id, 'reference' => 'L-1', 'category' => 'maintenance',
        'status' => 'approved', 'bill_date' => '2026-06-01', 'due_date' => '2026-07-01',
        'subtotal' => 3000, 'vat_amount' => 0, 'total' => 3000, 'balance' => 3000,
    ]);
    p16Fund($this->asset->id, $leaf->id, 1000, '2026-06-01');
    expect(fn () => VendorBillPayment::create(['vendor_bill_id' => $bill->id, 'amount' => 3000, 'payment_date' => '2026-06-10', 'method' => 'bank_transfer', 'bank_account_id' => $bank->id]))
        ->toThrow(DomainException::class);
    expect(VendorBillPayment::create(['vendor_bill_id' => $bill->id, 'amount' => 1000, 'payment_date' => '2026-06-10', 'method' => 'bank_transfer', 'bank_account_id' => $bank->id])->exists)->toBeTrue();
});

it('covers a deposit refund by the same seam — the source derives its property in its own hook, before the guard', function () {
    p16UnderTheClientsRule();
    // The lease's deposit pot: a receipt of 5,000 lands in the cash box (Dr cash / Cr deposits held).
    $lease = makeLease(makeUnit($this->asset));
    $receipt = depositMovement($lease, 'receipt', 5000);
    $receipt->forceFill(['method' => 'cash'])->saveQuietly();
    app(LedgerPoster::class)->sync($receipt->fresh());
    expect(CashBalanceGuard::balanceOf($this->cash, $this->asset->id))->toBe(5000.0);
    // 3,000 of it was spent on diesel: the POT still says 5,000 is held, the BOX holds 2,000.
    app(LedgerPoster::class)->sync(p16Expense($this->asset->id, 3000, '2026-06-08'));

    // A refund of 4,000 in cash is within the pot (its own guard) and beyond the box: refused
    // by THIS one, naming the box. 1,500 fits both.
    expect(fn () => DepositTransaction::create(['lease_id' => $lease->id, 'tenant_id' => $lease->tenant_id, 'type' => 'refund', 'amount' => 4000, 'transaction_date' => '2026-06-10', 'method' => 'cash']))
        ->toThrow(DomainException::class, __('admin.refusals.cash_box_overdrawn', [
            'property' => 'Cash Box Mall', 'date' => '10 June 2026', 'balance' => '2,000.00', 'amount' => '4,000.00', 'shortfall' => '2,000.00',
        ]));
    expect(DepositTransaction::create(['lease_id' => $lease->id, 'tenant_id' => $lease->tenant_id, 'type' => 'refund', 'amount' => 1500, 'transaction_date' => '2026-06-10', 'method' => 'cash'])->exists)->toBeTrue();
});

// ── What the review found ────────────────────────────────────────────────────────────────────

it('judges a document moved to an EARLIER day on its whole amount there, not on a net of zero', function () {
    p16UnderTheClientsRule();
    // 200 on the 5th, 1,200 more from the 15th; 900 spent on the 20th (fine there: 1,400 held).
    p16Fund($this->asset->id, $this->cash, 200, '2026-06-01');
    p16Fund($this->asset->id, $this->cash, 1200, '2026-06-15');
    $budget = MarketingBudget::create(['asset_id' => $this->asset->id, 'period_year' => 2026, 'accrued_amount' => 500000]);
    $spend = MarketingSpend::create(['marketing_budget_id' => $budget->id, 'category' => 'event', 'description' => 'Banner', 'amount' => 900, 'paid_from' => 'cash', 'spent_on' => '2026-06-20']);
    app(LedgerPoster::class)->sync($spend);

    // Netting the new entry against the old one per account reads as ZERO movement — the first cut
    // let this through and re-posted 900 onto the 5th, where the box held 200. The document's own
    // entry is left out and the whole payload judged where it now lands.
    expect(fn () => $spend->fresh()->update(['spent_on' => '2026-06-05']))
        ->toThrow(DomainException::class, __('admin.refusals.cash_box_overdrawn', [
            'property' => 'Cash Box Mall', 'date' => '5 June 2026', 'balance' => '200.00', 'amount' => '900.00', 'shortfall' => '700.00',
        ]));
    // Moved to a day that holds it: fine — and lowering the amount is never refused.
    $spend->fresh()->update(['spent_on' => '2026-06-16']);
    $spend->fresh()->update(['amount' => 100]);
    expect((float) $spend->fresh()->amount)->toBe(100.0);
});

it('reads a bank\'s OWN account whole — a receipt allocated across two malls lands there with no property', function () {
    p16UnderTheClientsRule();
    $leaf = LedgerAccount::create(['code' => '11900204', 'name_en' => 'CIB — shared', 'name_ar' => 'CIB — shared', 'type' => 'asset', 'is_postable' => true, 'is_active' => true]);
    $bank = BankAccount::create(['asset_id' => $this->asset->id, 'name' => 'CIB', 'account_number' => 'CBX-3', 'purpose' => BankAccount::PURPOSE_OPERATING, 'is_default' => true, 'ledger_account_id' => $leaf->id]);
    PropertySettings::set('accounting.refuse_overdrawn_bank', $this->asset->id, true);

    // The consolidated receipt (`PaymentJournalizer` posts one settling two malls' invoices under
    // no property) is what funds the account; a per-dimension read would not see it.
    $consolidated = app(JournalPostingService::class)->post([
        'entry_date' => '2026-06-01', 'asset_id' => null, 'description_en' => 'Receipt across two malls', 'is_manual' => true,
        'lines' => [
            ['ledger_account_id' => $leaf->id, 'debit' => 3000, 'credit' => 0],
            ['ledger_account_id' => $this->accounts->id('rent_revenue', $this->asset->id), 'debit' => 0, 'credit' => 3000],
        ],
    ]);
    expect($consolidated->asset_id)->toBeNull();
    expect(CashBalanceGuard::balanceOf($leaf->id, $this->asset->id))->toBe(0.0)
        ->and(CashBalanceGuard::balanceOf($leaf->id, null, whole: true))->toBe(3000.0);

    expect(p16Expense($this->asset->id, 2500, '2026-06-10', 'bank_transfer', $bank->id)->exists)->toBeTrue();
    // The shared cash ROLE stays per dimension: the other mall's drawer is not this mall's.
    expect(CashBalanceGuard::isABanksOwnAccount($this->cash))->toBeFalse();
});

it('asks a period-dated source — payroll — from the day of the act, not the 1st its entry is dated', function () {
    p16UnderTheClientsRule();
    // The box holds 100 on 1 June and 5,000 more from the 10th. The June run (net 3,000) is
    // approved today, the 15th: its entry is dated the 1st, when the box could not have paid it —
    // the money leaves at approval. Judged from the 1st it would be refused on every month-end.
    p16Fund($this->asset->id, $this->cash, 100, '2026-06-01');
    p16Fund($this->asset->id, $this->cash, 5000, '2026-06-10');
    $employee = Employee::create(['asset_id' => $this->asset->id, 'code' => 'E-16', 'name' => 'Cashier', 'position' => 'Cashier', 'hire_date' => '2025-01-01', 'base_salary' => 3000, 'payment_method' => 'cash']);
    $run = Payroll::create(['asset_id' => $this->asset->id, 'period_month' => '2026-06-01', 'gross_salaries' => 0, 'salary_tax' => 0, 'social_insurance' => 0, 'paid_from' => 'cash', 'status' => 'draft']);
    $run->lines()->create(['employee_id' => $employee->id, 'gross' => 3000, 'allowances' => 0, 'salary_tax' => 0, 'social_insurance' => 0, 'advance_deduction' => 0, 'other_deductions' => 0, 'employer_social_insurance' => 0]);

    expect(app(PayrollService::class)->approve($run->fresh())->status)->toBe('approved');

    // And a run the box cannot pay TODAY is still refused, naming today.
    $second = Payroll::create(['asset_id' => $this->asset->id, 'period_month' => '2026-05-01', 'gross_salaries' => 0, 'salary_tax' => 0, 'social_insurance' => 0, 'paid_from' => 'cash', 'status' => 'draft']);
    $second->lines()->create(['employee_id' => $employee->id, 'gross' => 9000, 'allowances' => 0, 'salary_tax' => 0, 'social_insurance' => 0, 'advance_deduction' => 0, 'other_deductions' => 0, 'employer_social_insurance' => 0]);
    expect(fn () => app(PayrollService::class)->approve($second->fresh()))
        ->toThrow(DomainException::class, __('admin.refusals.cash_box_overdrawn', [
            'property' => 'Cash Box Mall', 'date' => '15 June 2026', 'balance' => '5,100.00', 'amount' => '9,000.00', 'shortfall' => '3,900.00',
        ]));
});

// ── The door the operator uses ───────────────────────────────────────────────────────────────

it('refuses through the expense create page, as a refusal and not a fault', function () {
    p16UnderTheClientsRule();
    p16Fund($this->asset->id, $this->cash, 300, '2026-06-01');

    asTenant($this->asset, function () {
        $fill = fn (float $amount) => [
            'asset_id' => $this->asset->id, 'expense_date' => '2026-06-10', 'description' => 'Diesel', 'category' => 'maintenance',
            'amount' => $amount, 'paid_from' => 'cash', 'bank_account_id' => null,
        ];
        // Layer-agnostic, the `ARelievedInvoiceAcceptsNoMoreMoneyTest` idiom: the refusal may
        // surface as the thrown exception or as the page's own handling of it — what matters is
        // that the door did not write.
        try {
            Livewire::test(CreateExpense::class)->fillForm($fill(900))->call('create');
        } catch (DomainException) {
            // refused at the model — also a refusal
        }
        expect(Expense::count())->toBe(0);

        Livewire::test(CreateExpense::class)->fillForm($fill(250))->call('create')->assertHasNoFormErrors();
        expect(Expense::count())->toBe(1);
    });
});

it('fails open when the chart cannot answer, so an incomplete setup never blocks ordinary work', function () {
    p16UnderTheClientsRule();
    // No cash role mapped for this property tier and none portfolio-wide: nothing is a cash box.
    AccountMapping::query()->where('key', 'cash')->delete();

    expect(CashBalanceGuard::kindOf($this->cash, $this->asset->id))->toBeNull();
    expect(p16Expense($this->asset->id, 5000, '2026-06-10')->exists)->toBeTrue();
});
