<?php

/*
|--------------------------------------------------------------------------
| A recurring cost that names a contract stops when the contract does (SW-242)
|--------------------------------------------------------------------------
| Found on the first day of the month-long staging soak, by dry-running the calendar: Guardian
| Security's contract ends on the 12th, `vendors:expire-contracts` marks it `expired` on the 13th,
| and on the 20th `expenses:generate-recurring` raised a 68,400 draft bill for the retainer anyway —
| and would have again every month, under a contract the register already showed as ended.
|
| `RecurringExpense::nextDueOn()` read only the schedule's own `ends_on`; the contract was copied
| onto each bill as a foreign key and never asked anything. Yardi's recurring payable is a child of
| the contract and stops with it. Now the schedule's window closes at its own `ends_on` or the
| contract's `end_date`, whichever comes first — so an operator can still end a retainer EARLIER
| than the contract, never later — and the same rule answers "can this schedule ever book" on save,
| so a schedule set up under a contract that has already ended is refused rather than saved inert.
|
| Every case is paired with a control that must book, because a rule that stops everything
| satisfies the refusals alone.
*/

use App\Models\RecurringExpense;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Models\VendorContract;
use App\Services\GenerateRecurringExpensesService;
use Carbon\CarbonImmutable;
use Database\Seeders\ExpenseCategorySeeder;

beforeEach(function () {
    $this->seed(ExpenseCategorySeeder::class);
    $this->asset = makeAsset(['code' => 'RC']);
    $this->vendor = Vendor::create(['name' => 'Guardian Security', 'type' => 'service_provider', 'status' => 'active', 'email' => 'ops@guardian.test']);
});

function sw242Contract(string $end, string $status = 'active'): VendorContract
{
    return VendorContract::create([
        'vendor_id' => test()->vendor->id,
        'asset_id' => test()->asset->id,
        'name' => 'Mall security — 24/7',
        'status' => $status,
        'start_date' => '2025-09-13',
        'end_date' => $end,
        'notice_period_days' => 30,
        'value' => 720000,
        'currency' => 'EGP',
    ]);
}

function sw242Schedule(?VendorContract $contract, array $attributes = []): RecurringExpense
{
    return RecurringExpense::create($attributes + [
        'asset_id' => test()->asset->id,
        'vendor_id' => test()->vendor->id,
        'vendor_contract_id' => $contract?->id,
        'description' => 'Security retainer',
        'category' => 'cleaning_security',
        'amount' => 60000,
        'frequency' => RecurringExpense::MONTHLY,
        'day_of_month' => 20,
        'starts_on' => '2026-06-01',
        'last_generated_on' => '2026-08-20',
    ]);
}

it('raises no bill on a booking day after the contract has ended', function () {
    $schedule = sw242Schedule(sw242Contract('2026-09-12'));

    expect($schedule->nextDueOn(CarbonImmutable::parse('2026-09-20')))->toBeNull()
        ->and($schedule->effectiveEndsOn()?->toDateString())->toBe('2026-09-12');

    $result = app(GenerateRecurringExpensesService::class)->generate(CarbonImmutable::parse('2026-09-20'));

    expect($result['generated'])->toBe(0)
        ->and(VendorBill::where('recurring_expense_id', $schedule->id)->exists())->toBeFalse();
});

it('still bills while the contract runs — the control', function () {
    $schedule = sw242Schedule(sw242Contract('2026-12-31'));

    expect($schedule->nextDueOn(CarbonImmutable::parse('2026-09-20'))?->toDateString())->toBe('2026-09-20');

    $result = app(GenerateRecurringExpensesService::class)->generate(CarbonImmutable::parse('2026-09-20'));

    expect($result['generated'])->toBe(1)
        ->and(VendorBill::where('recurring_expense_id', $schedule->id)->whereDate('bill_date', '2026-09-20')->exists())->toBeTrue();
});

it('is bounded by the contract even when its own end date is later, and by its own end date when that is earlier', function () {
    $later = sw242Schedule(sw242Contract('2026-09-12'), ['ends_on' => '2027-06-30']);
    expect($later->effectiveEndsOn()?->toDateString())->toBe('2026-09-12');

    // Its own end comes first: September (the 20th, inside the window) still books, October does not.
    $earlier = sw242Schedule(sw242Contract('2026-12-31'), ['ends_on' => '2026-09-30', 'description' => 'Ends early']);
    expect($earlier->effectiveEndsOn()?->toDateString())->toBe('2026-09-30')
        ->and($earlier->nextDueOn(CarbonImmutable::parse('2026-10-20'))?->toDateString())->toBe('2026-09-20');

    $earlier->forceFill(['last_generated_on' => '2026-09-20'])->save();
    expect($earlier->fresh()->nextDueOn(CarbonImmutable::parse('2026-10-20')))->toBeNull();
});

it('leaves a schedule with no contract on its own window', function () {
    $schedule = sw242Schedule(null);

    expect($schedule->effectiveEndsOn())->toBeNull()
        ->and($schedule->nextDueOn(CarbonImmutable::parse('2026-09-20'))?->toDateString())->toBe('2026-09-20');
});

it('refuses a schedule set up under a contract that has already ended, naming the CONTRACT', function () {
    $contract = sw242Contract('2026-05-31', 'expired');

    expect(fn () => sw242Schedule($contract, ['last_generated_on' => null]))
        ->toThrow(DomainException::class, 'Mall security — 24/7');
});

it('refuses re-linking an EXISTING schedule to a contract that has ended — the edit door', function () {
    $schedule = sw242Schedule(sw242Contract('2026-12-31'));
    $ended = sw242Contract('2026-05-31', 'expired');

    expect(fn () => $schedule->update(['vendor_contract_id' => $ended->id]))
        ->toThrow(DomainException::class, '2026-05-31')
        ->and($schedule->fresh()->vendor_contract_id)->not->toBe($ended->id);
});

it('stops when the contract is TERMINATED early, whatever end date the term carried', function () {
    $contract = sw242Contract('2027-06-30');
    $schedule = sw242Schedule($contract);

    $contract->update(['status' => 'terminated']);

    // The termination is dated onto the contract, so the register and the bound agree.
    expect($contract->fresh()->end_date->toDateString())->toBe(now()->toDateString())
        ->and($schedule->fresh()->nextDueOn(CarbonImmutable::now()->addMonths(2)))->toBeNull();
});

it('stays bounded when the contract is deleted', function () {
    $contract = sw242Contract('2026-09-12');
    $schedule = sw242Schedule($contract);

    $contract->delete();

    expect($schedule->fresh()->vendor_contract_id)->toBe($contract->id)
        ->and($schedule->fresh()->nextDueOn(CarbonImmutable::parse('2026-09-20')))->toBeNull();
});
