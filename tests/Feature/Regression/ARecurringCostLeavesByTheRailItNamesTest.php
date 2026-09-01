<?php

use App\Models\CreditNote;
use App\Models\Expense;
use App\Models\RecurringExpense;
use App\Services\CreditUnearnedBillingService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * Two costs that were quietly wrong every month.
 *
 * **A recurring cost credited CASH whatever rail it left by.** `recordExpense()` never set
 * `paid_from`, and the schedule had nowhere to say it, so every generated expense fell to the
 * column default. The costs this feature exists for — real-estate tax, municipal levies, a licence
 * renewal, a fixed retainer — all leave a BANK account, so the whole recurring stream posted its
 * credit leg to the wrong side of the chart. `bank_account_id` comes with the rail because
 * `MoneyAccount` resolves the document's own account first, then the rail's, then the posting role:
 * without it a mall banking in two places cannot say which.
 *
 * **Terminating twice credited the tenant twice.** Nothing made the unearned-billing credit
 * idempotent, so a second press — a slow save, a re-checked date, a colleague in parallel — raised
 * a SECOND credit note for the same unearned days on the same invoice. Both notes are individually
 * plausible, so nothing on any screen says which is the duplicate.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);

    $this->asset = makeAsset();
});

it('generates the cost on the rail its schedule names', function () {
    $schedule = RecurringExpense::create([
        'asset_id' => $this->asset->id,
        'category' => 'maintenance',
        'description' => 'Lift maintenance retainer',
        'amount' => 25000,
        'frequency' => 'monthly',
        'day_of_month' => 1,
        'starts_on' => CarbonImmutable::now()->subMonths(2)->startOfMonth()->toDateString(),
        'is_active' => true,
        'paid_from' => 'bank',
    ]);

    $this->artisan('expenses:generate-recurring')->assertSuccessful();

    $expense = Expense::query()->where('recurring_expense_id', $schedule->id)->firstOrFail();

    expect($expense->paid_from)->toBe('bank');
});

it('still defaults to cash when the schedule says nothing — nothing moves on deploy', function () {
    // The control that keeps this deployable: an existing schedule states no rail, and its expenses
    // must keep behaving exactly as they did.
    $schedule = RecurringExpense::create([
        'asset_id' => $this->asset->id,
        'category' => 'admin',
        'description' => 'Government fee',
        'amount' => 4000,
        'frequency' => 'monthly',
        'day_of_month' => 1,
        'starts_on' => CarbonImmutable::now()->subMonths(2)->startOfMonth()->toDateString(),
        'is_active' => true,
    ]);

    $this->artisan('expenses:generate-recurring')->assertSuccessful();

    $expense = Expense::query()->where('recurring_expense_id', $schedule->id)->firstOrFail();

    expect($expense->paid_from)->toBe('cash');
});

it('will not raise a second credit note against an invoice that already has one', function () {
    // The guard at its own seam. Terminating is a button an operator can press twice — a slow save,
    // a re-checked date, a colleague in parallel — and nothing refused the second run: it raised a
    // SECOND note for the same unearned days on the same invoice. Both are individually plausible,
    // so nothing on any screen says which is the duplicate.
    //
    // Keyed on the INVOICE rather than the termination date: re-terminating with a different date
    // is the same mistake wearing a different figure. A VOIDED note deliberately does not count —
    // voiding one is exactly how an operator says "do that again properly".
    $lease = makeLease(makeUnit($this->asset), makeTenant(), ['status' => 'active', 'base_rent_monthly' => 30000]);

    $invoice = makeInvoice($lease, [
        'status' => 'issued', 'subtotal' => 30000, 'vat_amount' => 0, 'total' => 30000,
        'paid_amount' => 0, 'balance' => 30000,
        'period_start' => CarbonImmutable::now()->startOfMonth()->toDateString(),
        'period_end' => CarbonImmutable::now()->endOfMonth()->toDateString(),
    ]);
    // WITH ITS CHARGE. `isTimeApportioned()` requires `charge_id` — an item with none is treated
    // as a one-off and is never clawed back, so a fixture without it produces no note at all and
    // any assertion about duplicates passes for the wrong reason. (It did: the first version of
    // this test stayed green with the guard deleted.)
    $charge = App\Models\Charge::create([
        'lease_id' => $lease->id, 'name' => 'Base Rent', 'type' => 'base_rent',
        'origin' => App\Models\Charge::ORIGIN_SEED, 'amount' => 30000, 'currency' => 'EGP',
        'frequency' => 'monthly', 'start_date' => CarbonImmutable::now()->startOfMonth()->toDateString(),
        'is_active' => true,
    ]);

    $invoice->items()->create([
        'type' => 'base_rent', 'description' => 'Base rent', 'charge_id' => $charge->id,
        'amount' => 30000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 30000,
    ]);

    // A note already stands against it — whatever raised it.
    CreditNote::create([
        'tenant_id' => $lease->tenant_id, 'lease_id' => $lease->id, 'asset_id' => $invoice->asset_id,
        'invoice_id' => $invoice->id, 'status' => 'issued',
        'issue_date' => CarbonImmutable::now()->toDateString(), 'reason' => 'adjustment',
        'subtotal' => 20000, 'vat_amount' => 0, 'total' => 20000,
        'applied_amount' => 0, 'balance' => 20000, 'currency' => 'EGP',
    ]);

    // The premise: it is on the books, which is what the guard asks.
    expect(CreditNote::query()->where('invoice_id', $invoice->id)->onTheBooks()->count())->toBe(1);

    $notes = app(CreditUnearnedBillingService::class)
        ->forTermination($lease->fresh(), CarbonImmutable::now()->startOfMonth()->addDays(9));

    expect($notes)->toBeEmpty()
        ->and(CreditNote::query()->where('invoice_id', $invoice->id)->count())->toBe(1);
});

it('is not blocked by a VOIDED note — voiding is how an operator says do it again', function () {
    // The control. Keying on "any note ever" would make a note raised in error unfixable: the
    // correction is to void it and re-run, and a void must therefore not stand in the way.
    $lease = makeLease(makeUnit($this->asset), makeTenant(), ['status' => 'active', 'base_rent_monthly' => 30000]);

    $invoice = makeInvoice($lease, [
        'status' => 'issued', 'subtotal' => 30000, 'vat_amount' => 0, 'total' => 30000,
        'paid_amount' => 0, 'balance' => 30000,
        'period_start' => CarbonImmutable::now()->startOfMonth()->toDateString(),
        'period_end' => CarbonImmutable::now()->endOfMonth()->toDateString(),
    ]);

    CreditNote::create([
        'tenant_id' => $lease->tenant_id, 'lease_id' => $lease->id, 'asset_id' => $invoice->asset_id,
        'invoice_id' => $invoice->id, 'status' => 'void', 'voided_at' => CarbonImmutable::now(),
        'issue_date' => CarbonImmutable::now()->toDateString(), 'reason' => 'adjustment',
        'subtotal' => 20000, 'vat_amount' => 0, 'total' => 20000,
        'applied_amount' => 0, 'balance' => 0, 'currency' => 'EGP',
    ]);

    expect(CreditNote::query()->where('invoice_id', $invoice->id)->onTheBooks()->count())->toBe(0);
});
