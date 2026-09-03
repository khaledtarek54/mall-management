<?php

use App\Models\Payroll;
use Tests\Support\PayrollRuns;

/**
 * **Nobody is paid twice for the same month — including when nobody is named.**
 *
 * The 2026-08-20 guard is on the EMPLOYEE, deliberately: a supplementary run is legitimate (a bonus,
 * an off-cycle correction, a starter paid late), and what may never happen is one person drawing two
 * approved payslips for one period.
 *
 * But it only runs when the run HAS lines. A **lump-sum run has none** — a supported shape, not an
 * abuse: `PayrollForm` unlocks the header money fields exactly when a run has no lines — so it skips
 * the guard on its own approval AND leaves nothing for another run's guard to find.
 *
 * Measured on HEAD before the fix: a 3-line payslip run of 12,000 and a lineless run of 12,000, same
 * mall, same month, **both approved in either order**, and two lineless runs likewise.
 * `PayrollJournalizer` reads `gross_salaries` from the HEADER, so the ledger carried **24,000 of
 * salaries expense for a 12,000 month** — overstating the wage bill, understating net operating
 * income, and crediting the bank for cash that never left, which leaves the reconciliation carrying
 * a phantom outflow permanently. Nothing downstream objects: `billing:reconcile` does not look at
 * payroll at all, and each journal entry is internally balanced.
 *
 * The second hole is the property scope. A run filed against NO property is portfolio-wide, and
 * `where('asset_id', null)` compiles to `asset_id = null`, which matches nothing — so a consolidated
 * run and a mall run for the same month were both approvable too.
 */
beforeEach(fn () => $this->asset = makeAsset(['code' => 'PAY']));

it('refuses a lump-sum run when the month already has a payslip run', function () {
    PayrollRuns::approve(PayrollRuns::run($this->asset, 3));

    expect(PayrollRuns::approve(PayrollRuns::run($this->asset, 0)))->toBe('REFUSED');
});

it('refuses a payslip run when the month already has a lump-sum run', function () {
    // The other order, and it has to be tested separately: the guard reads the run being approved,
    // so "this one has no lines" and "the other one has no lines" are two different branches.
    PayrollRuns::approve(PayrollRuns::run($this->asset, 0));

    expect(PayrollRuns::approve(PayrollRuns::run($this->asset, 3)))->toBe('REFUSED');
});

it('refuses a second lump-sum run', function () {
    // Neither side names anybody. The likeliest trigger of the three, and the row did not claim it.
    PayrollRuns::approve(PayrollRuns::run($this->asset, 0));

    expect(PayrollRuns::approve(PayrollRuns::run($this->asset, 0)))->toBe('REFUSED');
});

it('still allows a supplementary run that says who it pays', function () {
    // The control that matters most: the employee guard exists so this stays possible — a bonus, an
    // off-cycle correction, a starter paid late. A blanket one-run-per-month bar would kill it, and
    // an operator with no escape back-dates into the wrong month instead, which is worse.
    PayrollRuns::approve(PayrollRuns::run($this->asset, 3));

    expect(PayrollRuns::approve(PayrollRuns::run($this->asset, 2)))->toBe('approved');
});

it('still refuses a second payslip for the SAME person', function () {
    // …and the guard it was written for is untouched.
    $shared = [PayrollRuns::employee($this->asset), PayrollRuns::employee($this->asset)];
    PayrollRuns::approve(PayrollRuns::run($this->asset, 2, employees: $shared));

    expect(PayrollRuns::approve(PayrollRuns::run($this->asset, 2, employees: $shared)))->toBe('REFUSED');
});

it('refuses a consolidated run against a mall run for the same month', function () {
    // A run filed against NO property is portfolio-wide, so it covers this mall too. The old clause
    // was `where('asset_id', null)`, which matches nothing — so both were approvable.
    PayrollRuns::approve(PayrollRuns::run($this->asset, 0, assetId: null));

    expect(PayrollRuns::approve(PayrollRuns::run($this->asset, 0)))->toBe('REFUSED');
});

it('leaves another month alone', function () {
    PayrollRuns::approve(PayrollRuns::run($this->asset, 0));

    expect(PayrollRuns::approve(PayrollRuns::run($this->asset, 0, month: '2026-09-01')))->toBe('approved');
});

it('leaves another property alone', function () {
    $other = makeAsset(['code' => 'OTH']);
    PayrollRuns::approve(PayrollRuns::run($this->asset, 0));

    expect(PayrollRuns::approve(PayrollRuns::run($this->asset, 0, assetId: $other->id)))->toBe('approved');
});
