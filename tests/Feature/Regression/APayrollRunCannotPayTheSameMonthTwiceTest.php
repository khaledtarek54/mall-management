<?php

use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\Payroll;
use App\Services\PayrollService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\LockSpy;

/**
 * TWO PAYROLL RUNS FOR ONE MONTH, APPROVED AT ONCE, PAID EVERYBODY TWICE.
 *
 * `Payroll::saving` carries the double-pay guard — no employee may be on two APPROVED runs for one
 * month at one property — and it was a plain read with nothing serialising the writers. Two runs
 * approved concurrently each see the other still `draft`, both pass, and the employee is paid twice:
 * salaries posted twice, and every advance installment in both runs relieved twice.
 *
 * There is no contended ROW to lock — the two runs are different rows and the guard is about the
 * SET — so it is a cache lock, keyed on the property and the month because that is exactly the
 * scope of the guard's own query. Taken OUTSIDE the transaction, or our consistent-read snapshot
 * would already be fixed from before the other approval committed and the guard would be answered
 * from a state it had waited past.
 *
 * **And the advance re-check decided from a pre-lock snapshot.** It takes `lockForUpdate()` on each
 * `EmployeeAdvance` and then asked `outstanding()`, which issues plain reads against the repayments
 * and the approved payroll lines. A lock serialises writers; it does not make the guard behind it
 * SEE them — so two runs each deducting within the pre-approval outstanding both passed and together
 * over-repaid the loan. `outstandingForUpdate()` is the locking twin.
 *
 * **What none of this proves is that two transactions actually serialise** — that needs MySQL and
 * two connections (`docs/qa/scripts/race.sh`). What it proves is that the locks are taken and the
 * guards read under them, which is what stops the next tidy-up deleting either.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);

    $this->asset = makeAsset(['code' => 'PAY']);
    $this->month = CarbonImmutable::now()->startOfMonth();

    $this->employee = Employee::create([
        'asset_id' => $this->asset->id, 'code' => 'E-100', 'name' => 'Mona Adel',
        'position' => 'Technician', 'hire_date' => '2024-01-01',
        'base_salary' => 8000, 'payment_method' => 'bank',
    ]);

    $this->svc = app(PayrollService::class);
});

/** A draft run for the fixture month, carrying one line for the fixture employee. */
function draftPayrollWithAdvance(float $net = 8000, ?EmployeeAdvance $advance = null, float $deduction = 0): Payroll
{
    $run = Payroll::create([
        'asset_id' => test()->asset->id,
        'period_month' => test()->month->toDateString(),
        'gross_salaries' => 0, 'salary_tax' => 0, 'social_insurance' => 0,
        'paid_from' => 'bank', 'status' => 'draft',
    ]);

    $run->lines()->create([
        'employee_id' => test()->employee->id,
        'employee_advance_id' => $advance?->id,
        'gross' => $net + $deduction,
        'allowances' => 0, 'salary_tax' => 0, 'social_insurance' => 0,
        'advance_deduction' => $deduction,
        'other_deductions' => 0, 'employer_social_insurance' => 0,
    ]);

    return $run->fresh();
}

it('refuses to approve a second run for the same employee and month', function () {
    $first = draftPayrollWithAdvance();
    $second = draftPayrollWithAdvance();

    expect($this->svc->approve($first)->status)->toBe('approved');

    // The control and the refusal together: a guard that refused everything would satisfy the
    // second assertion while stopping payroll entirely.
    expect(fn () => $this->svc->approve($second->fresh()))->toThrow(DomainException::class);

    expect($second->fresh()->status)->toBe('draft');
});

it('takes the lock on the exact key, with the callback inside it', function () {
    // **BEHAVIOURAL, because the source-string version was a tautology.** `toContain("Cache::lock(")`
    // passes with the lock in the wrong place, with `->get()` instead of `->block()`, with the
    // callback's result discarded, with the key built from the wrong columns, and with the whole
    // thing after `DB::beginTransaction()` — which is the one placement the commit rests on.
    //
    // Mocking pins the key term by term (the source grep never checked the month or the TTL) and
    // pins that the approval runs INSIDE the lock.
    $run = draftPayrollWithAdvance();

    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')->once()->with(10, Mockery::type('Closure'))
        ->andReturnUsing(fn (int $seconds, Closure $callback) => $callback());

    Cache::shouldReceive('lock')->once()
        ->with('payroll:approve:'.$this->asset->id.':'.$this->month->format('Y-m'), 30)
        ->andReturn($lock);

    expect($this->svc->approve($run)->status)->toBe('approved');
});

it('refuses rather than 500s when another approval holds the lock', function () {
    // `block()` throws `LockTimeoutException`, which the exception handler does not render — only a
    // `DomainException` becomes a toast. So the operator waited ten seconds and got the error page
    // with their form state gone; the realistic trigger is the holder's process dying, because the
    // lock lives to its 30s TTL while the wait is 10s.
    $run = draftPayrollWithAdvance();

    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')->once()->andThrow(new LockTimeoutException);
    Cache::shouldReceive('lock')->once()->andReturn($lock);

    expect(fn () => $this->svc->approve($run))->toThrow(DomainException::class);

    expect($run->fresh()->status)->toBe('draft');
});

it('is idempotent under the lock, not merely before it', function () {
    // The `draft` check in `approve()` is OUTSIDE the lock, so two requests on the SAME run both
    // read draft, both take the key, serialise — and the second one re-approved: `approved_at` and
    // `approved_by_user_id` overwritten by the later actor, a second activity row.
    // `Payroll::saving`'s clash guard cannot catch it, because `whereKeyNot()` excludes the run from
    // its own query.
    $run = draftPayrollWithAdvance();

    $first = $this->svc->approve($run);
    $stamp = $first->approved_at;

    test()->travel(5)->minutes();

    // The stale in-memory copy is what a double-click sends: it still says `draft`.
    $second = $this->svc->approve($run);

    expect($second->status)->toBe('approved')
        ->and($second->approved_at->eq($stamp))->toBeTrue('the second approval re-stamped the run');
});

it('reads the advance balance UNDER the lock it just took', function () {
    // `AUTHORITATIVE_GUARDS` claims this, and the gate reads the method's own body — but the claim
    // is only worth anything if the guard is actually reached, so this drives the real approval and
    // watches which tables were locked.
    $advance = EmployeeAdvance::create([
        'asset_id' => $this->asset->id, 'employee_id' => $this->employee->id,
        'type' => 'loan', 'amount' => 3000,
        'advance_date' => $this->month->toDateString(), 'paid_from' => 'cash',
    ]);

    $run = draftPayrollWithAdvance(7000, $advance, 1000);

    $spy = LockSpy::watch(fn () => $this->svc->approve($run));

    expect($spy->locked('employee_advances'))->toBeTrue(
        'the advance row was not locked. Locked: '.implode(', ', $spy->lockedTables()))
        ->and($spy->locked('payroll_lines'))->toBeTrue(
            'the outstanding balance was read WITHOUT a lock, so it answers from the snapshot taken '
            .'before this transaction waited — two runs then over-repay the same advance')
        // **AND `payrolls`, which is the table that actually MOVES.** The first version used
        // `whereHas('payroll', …)->lockForUpdate()`, and MySQL does not lock a nested subquery's
        // rows unless the subquery says so — so it locked `payroll_lines`, whose
        // `advance_deduction` is identical before and after the other run approves, and read
        // `payrolls.status` non-locking from the snapshot. Measured on real MySQL with two
        // connections: it answered 0.00 where the truth was 3,000, byte-identical to the plain
        // `outstanding()` it replaced. A join puts `payrolls` in the same query block.
        //
        // The SHAPE is asserted in the case below, not here: `LockSpy` compiles the lock to a comment
        // on the OUTER query, and a `whereHas` names `payrolls` inside its own subquery text — so a
        // table-name check cannot tell a join from a subquery, which is exactly how the broken
        // version looked correct on SQLite.
        ->and($spy->locked('payrolls'))->toBeTrue(
            'the approved-payrolls half was read from the snapshot, so the guard is a no-op on MySQL');
});

it('locks `payrolls` in the same query block, not in a nested subquery', function () {
    // **The crux, and SQLite cannot see it.** MySQL: *"A locking read clause in an outer statement
    // does not lock the rows of a table in a nested subquery unless a locking read clause is also
    // specified in the subquery."* `payrolls.status` is the ONLY value that moves during this race —
    // the lines and their `advance_deduction` are identical before and after the other run approves —
    // so `whereHas('payroll', …)->lockForUpdate()` locked the data that cannot change and read the
    // deciding column non-locking from the snapshot. Measured on real MySQL with two connections:
    // 0.00 where the truth was 3,000.
    //
    // The SHAPE is what can be pinned here, because the behaviour needs two connections and a real
    // MySQL (`docs/qa/scripts/race.sh`). A join puts `payrolls` in the same block, where `for update`
    // reaches it; an `exists (select …)` does not.
    $advance = EmployeeAdvance::create([
        'asset_id' => $this->asset->id, 'employee_id' => $this->employee->id,
        'type' => 'loan', 'amount' => 5000,
        'advance_date' => $this->month->toDateString(), 'paid_from' => 'cash',
    ]);

    $statements = [];
    DB::listen(function ($query) use (&$statements) {
        $statements[] = strtolower($query->sql);
    });

    $advance->outstandingForUpdate();

    $onLines = collect($statements)->filter(
        fn (string $sql): bool => str_contains($sql, 'payroll_lines') && str_contains($sql, 'advance_deduction'),
    );

    expect($onLines)->not->toBeEmpty('the approved-payroll term was never queried at all');

    expect($onLines->first())->toContain('join "payrolls"')
        ->not->toContain('exists (select');
});

it('refuses a run that would over-repay an advance', function () {
    // The guard the locking read exists to make authoritative, on its own terms.
    $advance = EmployeeAdvance::create([
        'asset_id' => $this->asset->id, 'employee_id' => $this->employee->id,
        'type' => 'loan', 'amount' => 1000,
        'advance_date' => $this->month->toDateString(), 'paid_from' => 'cash',
    ]);

    $run = draftPayrollWithAdvance(6000, $advance, 2000);   // 2,000 against a 1,000 loan

    expect(fn () => $this->svc->approve($run))->toThrow(DomainException::class);

    expect($run->fresh()->status)->toBe('draft');
});

it('still approves an ordinary run — the control for every refusal above', function () {
    $run = draftPayrollWithAdvance();

    expect($this->svc->approve($run)->status)->toBe('approved')
        ->and($run->fresh()->approved_at)->not->toBeNull();
});

it('answers the same figure locked or not, on a populated advance', function () {
    // Two copies of the same arithmetic with no gate holding them equal, failing OPEN: add a fourth
    // term to `outstanding()` — a waiver, a write-off — and the AUTHORITATIVE twin silently answers
    // a LARGER balance, which is the permissive direction. `Lease::depositHeldForUpdate()` is the
    // precedent and it has exactly this test.
    $advance = EmployeeAdvance::create([
        'asset_id' => $this->asset->id, 'employee_id' => $this->employee->id,
        'type' => 'loan', 'amount' => 5000,
        'advance_date' => $this->month->toDateString(), 'paid_from' => 'cash',
    ]);

    $advance->repayments()->create([
        'asset_id' => $this->asset->id,
        'amount' => 1000,
        'repaid_on' => $this->month->toDateString(),
        'method' => 'cash',
    ]);

    $run = draftPayrollWithAdvance(7000, $advance, 1500);
    $this->svc->approve($run);

    $fresh = $advance->fresh();

    // 5,000 − 1,000 cash − 1,500 via payroll = 2,500, and both reads must say so.
    expect($fresh->outstanding())->toEqual(2500.0)
        ->and($fresh->outstandingForUpdate())->toEqual($fresh->outstanding());
});
