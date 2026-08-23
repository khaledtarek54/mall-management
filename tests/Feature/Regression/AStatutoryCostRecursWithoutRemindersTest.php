<?php

use App\Models\Expense;
use App\Models\RecurringExpense;
use App\Services\GenerateRecurringExpensesService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;

/**
 * EG-33 / T-8 — a cost that comes round every period, without anyone remembering it.
 *
 * **There was no recurring-expense concept anywhere in this system.** Recurrence existed only on the
 * revenue side — `charges` bill a lease every cycle — so every cost arriving on a calendar rather
 * than on an invoice was somebody's reminder: real-estate tax, municipal levies, the annual
 * civil-defence licence, a fixed retainer. Yardi calls these Recurring Payables.
 *
 * The property worth testing hardest is that generating a statutory cost TWICE is real money in the
 * ledger, on a document nobody re-reads.
 *
 * What is deliberately NOT modelled: Egyptian real-estate tax's rate, rental-value basis and 32%
 * non-residential deduction. The assessed figure is a fact the operator holds; computing it from
 * guessed rates would put a confident wrong number on a statutory filing.
 */
function taxSchedule(array $attrs = []): RecurringExpense
{
    return RecurringExpense::create($attrs + [
        'asset_id' => test()->reAsset->id,
        'description' => 'Real-estate tax instalment',
        'category' => 'government_fees',
        'amount' => 48000,
        'frequency' => RecurringExpense::SEMIANNUALLY,
        'day_of_month' => 1,
        'starts_on' => '2026-03-01',
    ]);
}

beforeEach(function () {
    // The category catalogue — and `government_fees` ships deliberately SWITCHED OFF, so it is
    // activated here exactly as the operator would before their first real-estate tax instalment.
    // Booking a statutory levy to `other` would be the wrong answer on the P&L and inside any CAM
    // pool that recovers government charges.
    test()->seed(Database\Seeders\ExpenseCategorySeeder::class);
    App\Models\ExpenseCategory::where('code', 'government_fees')->update(['is_active' => true]);
    App\Models\ExpenseCategory::flushCatalogue();

    $this->reAsset = makeAsset(['code' => 'RE']);
});

it('books the cost when its period arrives, and not before', function () {
    $schedule = taxSchedule();

    // The day before it is due — nothing.
    app(GenerateRecurringExpensesService::class)->generate(CarbonImmutable::parse('2026-02-28'));

    expect(Expense::where('recurring_expense_id', $schedule->id)->count())->toBe(0);

    app(GenerateRecurringExpensesService::class)->generate(CarbonImmutable::parse('2026-03-01'));

    $expense = Expense::where('recurring_expense_id', $schedule->id)->sole();

    expect((float) $expense->amount)->toBe(48000.0)
        ->and($expense->expense_date->toDateString())->toBe('2026-03-01')
        ->and($expense->category)->toBe('government_fees')
        // `recorded` is what posts it — the whole point of a schedule is that the cost books itself.
        ->and($expense->status)->toBe('recorded');
});

it('never books the same period twice, however often the sweep runs', function () {
    // The one that matters. The sweep runs DAILY; a statutory cost booked twice is real money in
    // the ledger, on a document nobody re-reads.
    $schedule = taxSchedule();

    foreach (range(1, 5) as $ignored) {
        app(GenerateRecurringExpensesService::class)->generate(CarbonImmutable::parse('2026-03-15'));
    }

    expect(Expense::where('recurring_expense_id', $schedule->id)->count())->toBe(1);
});

it('moves on to the NEXT period once the first is booked', function () {
    // …and the control for the test above: idempotence must not become "never generates again".
    $schedule = taxSchedule();

    app(GenerateRecurringExpensesService::class)->generate(CarbonImmutable::parse('2026-03-01'));
    app(GenerateRecurringExpensesService::class)->generate(CarbonImmutable::parse('2026-09-01'));

    $dates = Expense::where('recurring_expense_id', $schedule->id)
        ->orderBy('expense_date')->pluck('expense_date')
        ->map(fn ($d) => $d->toDateString())->all();

    // Semiannual: March and September, the two Egyptian real-estate tax instalments.
    expect($dates)->toBe(['2026-03-01', '2026-09-01']);
});

it('catches up ONE period per run, not six in a night', function () {
    // A schedule switched off for six months and back on must not mint six back-dated expenses at
    // once — six journal entries into periods that may be closed, on a cost nobody re-reads.
    $schedule = taxSchedule(['frequency' => RecurringExpense::MONTHLY, 'starts_on' => '2026-01-01']);

    app(GenerateRecurringExpensesService::class)->generate(CarbonImmutable::parse('2026-06-15'));

    expect(Expense::where('recurring_expense_id', $schedule->id)->count())->toBe(1);

    // The oldest outstanding period first, so the gap closes in order.
    expect(Expense::where('recurring_expense_id', $schedule->id)->sole()->expense_date->toDateString())
        ->toBe('2026-01-01');
});

it('stops at the end date, and while switched off', function () {
    $ended = taxSchedule(['frequency' => RecurringExpense::MONTHLY, 'starts_on' => '2026-01-01', 'ends_on' => '2026-02-28']);

    // Two periods inside the window, then nothing.
    foreach (['2026-01-05', '2026-02-05', '2026-03-05', '2026-04-05'] as $day) {
        app(GenerateRecurringExpensesService::class)->generate(CarbonImmutable::parse($day));
    }

    expect(Expense::where('recurring_expense_id', $ended->id)->count())->toBe(2);

    // Switched off — nothing, even though a period is due.
    $off = taxSchedule(['description' => 'Municipal levy', 'starts_on' => '2026-01-01', 'is_active' => false]);

    app(GenerateRecurringExpensesService::class)->generate(CarbonImmutable::parse('2026-06-01'));

    expect(Expense::where('recurring_expense_id', $off->id)->count())->toBe(0);
});

it('clamps the day so a schedule set to the 31st does not skip February', function () {
    // The trap `BillingDay` records: a `->day(31)` silently skips the seven months that are shorter.
    $schedule = taxSchedule(['frequency' => RecurringExpense::MONTHLY, 'day_of_month' => 31, 'starts_on' => '2026-01-31']);

    app(GenerateRecurringExpensesService::class)->generate(CarbonImmutable::parse('2026-01-31'));
    app(GenerateRecurringExpensesService::class)->generate(CarbonImmutable::parse('2026-02-28'));

    $dates = Expense::where('recurring_expense_id', $schedule->id)
        ->orderBy('expense_date')->pluck('expense_date')->map(fn ($d) => $d->toDateString())->all();

    expect($dates)->toBe(['2026-01-31', '2026-02-28']);
});

it('is refused a frequency the catalogue does not offer', function () {
    expect(fn () => taxSchedule(['frequency' => 'fortnightly']))->toThrow(Exception::class);

    // The control — a real one saves.
    expect(taxSchedule(['frequency' => RecurringExpense::QUARTERLY])->exists)->toBeTrue();
});

it('cannot be deleted once it has booked a cost', function () {
    $schedule = taxSchedule();
    app(GenerateRecurringExpensesService::class)->generate(CarbonImmutable::parse('2026-03-01'));

    expect(fn () => $schedule->fresh()->delete())->toThrow(DomainException::class);

    // The control: one that has booked nothing is a mistake worth clearing.
    $unused = taxSchedule(['description' => 'Typed twice']);

    $unused->delete();

    expect(RecurringExpense::whereKey($unused->id)->exists())->toBeFalse();
});

it('is the command the scheduler actually runs', function () {
    // A service nothing calls is the failure this codebase already shipped: BillUnitOwnershipsService
    // was fully built, fully tested, and billed nobody for the whole of module 37's life.
    $commands = collect(app(Schedule::class)->events())
        ->map(fn ($e) => $e->command ?? '')->implode(' ');

    expect($commands)->toContain('expenses:generate-recurring');
});

it('books through the real command, not just the service', function () {
    taxSchedule();

    $this->artisan('expenses:generate-recurring --date=2026-03-01')
        ->expectsOutputToContain('1 booked')
        ->assertSuccessful();

    expect(Expense::whereNotNull('recurring_expense_id')->count())->toBe(1);
});

/**
 * ── The other kind of standing cost: one owed to a SUPPLIER ─────────────────────────────────────
 *
 * EG-33 shipped covering costs the operator simply incurs. The kind a mall actually has most of is
 * the fixed cleaning retainer, the security contract, the lift-maintenance contract — a payable
 * owed to a named vendor, usually under a `vendor_contracts` row, and still typed in by hand every
 * month because `vendor_contracts` generated nothing. Found by the pre-staging verification against
 * Yardi, whose recurring payables post to a VENDOR.
 */
function retainerSchedule(array $attrs = []): RecurringExpense
{
    return RecurringExpense::create($attrs + [
        'asset_id' => test()->reAsset->id,
        'vendor_id' => App\Models\Vendor::factory()->create()->id,
        'description' => 'Cleaning retainer',
        'category' => 'cleaning_security',
        'amount' => 50000,
        'frequency' => RecurringExpense::MONTHLY,
        'day_of_month' => 1,
        'payment_terms_days' => 30,
        'starts_on' => '2026-03-01',
    ]);
}

it('raises a supplier BILL when the schedule names a vendor', function () {
    $schedule = retainerSchedule();

    app(GenerateRecurringExpensesService::class)->generate(CarbonImmutable::parse('2026-03-01'));

    $bill = App\Models\VendorBill::where('recurring_expense_id', $schedule->id)->sole();

    expect($bill->vendor_id)->toBe($schedule->vendor_id)
        ->and((float) $bill->subtotal)->toBe(50000.0)
        ->and($bill->bill_date->toDateString())->toBe('2026-03-01')
        // Payment terms are a term of THIS agreement, not the AR default a tenant is given.
        ->and($bill->due_date->toDateString())->toBe('2026-03-31')
        // DRAFT, and that is the point: `vendor_bills.reference` is the SUPPLIER's invoice number,
        // unique per vendor and impossible to invent, and posting Dr Expense / Cr AP for an invoice
        // nobody sent would be the system inventing a creditor's claim.
        ->and($bill->status)->toBe('draft')
        ->and($bill->reference)->toBeNull()
        // THE CONTROL — and the invariant: it raised a bill INSTEAD of an expense, never both.
        ->and(Expense::where('recurring_expense_id', $schedule->id)->count())->toBe(0);
});

it('still books an EXPENSE when no vendor is named — the paired control', function () {
    // Naming a supplier is the whole discriminator, so the negative case has to be asserted on the
    // same day with the same sweep, or "it raised a bill" proves nothing about what a blank does.
    $tax = taxSchedule();

    app(GenerateRecurringExpensesService::class)->generate(CarbonImmutable::parse('2026-03-01'));

    expect(Expense::where('recurring_expense_id', $tax->id)->count())->toBe(1)
        ->and(App\Models\VendorBill::where('recurring_expense_id', $tax->id)->count())->toBe(0);
});

it('never raises the same supplier bill twice, however often the sweep runs', function () {
    $schedule = retainerSchedule();

    foreach (['2026-03-01', '2026-03-02', '2026-03-15', '2026-03-31'] as $day) {
        app(GenerateRecurringExpensesService::class)->generate(CarbonImmutable::parse($day));
    }

    // One for March, one for April — the sweep having run four times inside March.
    expect(App\Models\VendorBill::where('recurring_expense_id', $schedule->id)->count())->toBe(1);

    app(GenerateRecurringExpensesService::class)->generate(CarbonImmutable::parse('2026-04-01'));

    expect(App\Models\VendorBill::where('recurring_expense_id', $schedule->id)
        ->orderBy('bill_date')->pluck('bill_date')
        ->map(fn ($d) => $d->toDateString())->all())
        ->toBe(['2026-03-01', '2026-04-01']);
});

it('cannot be deleted once it has raised a supplier bill', function () {
    // `blockedBy` naming only `expenses` would leave every supplier schedule freely deletable —
    // the under-population trap the deletion gate cannot see, because it only checks the relations
    // NAMED there really exist.
    $schedule = retainerSchedule();
    app(GenerateRecurringExpensesService::class)->generate(CarbonImmutable::parse('2026-03-01'));

    expect(fn () => $schedule->fresh()->delete())->toThrow(DomainException::class);

    // The control: one that has raised nothing is still a mistake worth clearing.
    $unused = retainerSchedule(['description' => 'Typed twice']);

    $unused->delete();

    expect(RecurringExpense::whereKey($unused->id)->exists())->toBeFalse();
});

it('derives the input tax from the code the schedule states', function () {
    // `recurring_expenses.tax_code` was offered on the form and read by NOTHING — both documents
    // were minted with zero tax, so a cost under VAT_14 booked no recoverable input VAT and the
    // code sat on the row explaining a figure never derived from it.
    $withTax = retainerSchedule(['tax_code' => 'VAT_14']);

    app(GenerateRecurringExpensesService::class)->generate(CarbonImmutable::parse('2026-03-01'));

    $bill = App\Models\VendorBill::where('recurring_expense_id', $withTax->id)->sole();

    expect((float) $bill->vat_amount)->toBe(7000.0)
        ->and((float) $bill->total)->toBe(57000.0);

    // THE CONTROL — a schedule naming no code still books nothing, which is what an unclassified
    // cost has always meant. Without this the assertion above passes on an install that taxes
    // everything at 14%.
    $noTax = retainerSchedule(['description' => 'Security retainer', 'amount' => 20000]);

    app(GenerateRecurringExpensesService::class)->generate(CarbonImmutable::parse('2026-03-01'));

    $plain = App\Models\VendorBill::where('recurring_expense_id', $noTax->id)->sole();

    expect((float) $plain->vat_amount)->toBe(0.0)
        ->and((float) $plain->total)->toBe(20000.0);
});

it('names the drafts separately in the command output', function () {
    // An expense is POSTED and a bill is a DRAFT waiting for an invoice — different states needing
    // different next actions. One combined count would read as "2 costs booked" and hide that one
    // of them is sitting unapproved in the AP register.
    taxSchedule();
    retainerSchedule();

    $this->artisan('expenses:generate-recurring --date=2026-03-01')
        ->expectsOutputToContain('2 booked')
        ->expectsOutputToContain('(draft)')
        ->assertSuccessful();
});
