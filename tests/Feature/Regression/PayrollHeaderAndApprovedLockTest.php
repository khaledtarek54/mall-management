<?php

use App\Models\Employee;
use App\Models\Payroll;
use App\Services\PayrollService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * A payroll run's header ties to its payslips, and an APPROVED run's money is settled.
 *
 * THE GAP (module 24 close-out, 2026-08-11) — the same two shapes this batch has found in every
 * generic module it has touched.
 *
 * **1. The header is derived, but only from one direction.** `Payroll::recomputeFromLines()` sums
 * the payslips into the header so "the header — and the GL entry that posts from it — always ties
 * to the sum of the payslips". Its own docblock says it is *"called only from the PayrollLine
 * save/delete hooks"*. So the lines pull the header, and nothing pushes back: writing the header
 * directly persists whatever arrives, `Payroll::saving` re-derives `net_paid` from those tampered
 * figures, and `PayrollJournalizer` posts the salaries debit from the header while the payslips —
 * and the payslip PDFs an employee is handed — say something else. Exactly the invoice
 * header-versus-items divergence the validation sweep closed (§8 R1).
 *
 * **2. An approved run's money stayed editable.** The doc states the freeze as a fact — *"once
 * approved the header (and its GL entry) is settled and the lines are frozen"* — and names its
 * enforcement: *"mutation actions hidden + server-side `abort_unless(runIsEditable)`"*.
 * `runIsEditable()` exists in exactly one place, `PayrollLinesRelationManager`, so the freeze is a
 * property of that screen. `GeneratePayrollService` guards itself (`status !== 'draft'` throws), so
 * both known writers are safe — and every other one, an import or the console or a future screen,
 * walked straight into restating a posted payroll.
 *
 * A LUMP-SUM run keeps its manual amounts. A run with no payslips has nothing to derive from, and
 * `recomputeFromLines` already carves that out ("a pure lump-sum run never reaches here, so its
 * manual amounts stand") — the same carve-out as an invoice with no line items.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);

    $this->asset = makeAsset(['code' => 'PAY1']);
    $this->employee = Employee::create([
        'asset_id' => $this->asset->id, 'code' => 'E-1', 'name' => 'Mahmoud Fahmy',
        'hire_date' => '2026-01-01', 'status' => 'active',
        'base_salary' => 10000, 'payment_method' => 'bank',
    ]);

    $this->run = Payroll::create([
        'asset_id' => $this->asset->id,
        'period_month' => '2026-06-01',
        'gross_salaries' => 0, 'salary_tax' => 0, 'social_insurance' => 0, 'net_paid' => 0,
        'paid_from' => 'bank', 'status' => 'draft',
    ]);

    $this->run->lines()->create([
        'employee_id' => $this->employee->id,
        'gross' => 10000, 'salary_tax' => 1000, 'social_insurance' => 1100,
        'employer_social_insurance' => 1800,
    ]);
});

it('ties the header to the payslips after the line is written', function () {
    // The behaviour that already worked, asserted so the fix below cannot silently break it.
    expect(round((float) $this->run->fresh()->gross_salaries, 2))->toBe(10000.0)
        ->and(round((float) $this->run->fresh()->net_paid, 2))->toBe(7900.0);
});

it('re-derives a header written directly, rather than believing it', function () {
    // An import, the console, a crafted submit. Before the fix this persisted 99,999 and the GL
    // posted a salaries debit no payslip supported.
    $this->run->fresh()->update(['gross_salaries' => 99999]);

    expect(round((float) $this->run->fresh()->gross_salaries, 2))->toBe(10000.0)
        ->and(round((float) $this->run->fresh()->net_paid, 2))->toBe(7900.0);
});

it('leaves a lump-sum run with no payslips to stand on its own figures', function () {
    // The carve-out recomputeFromLines already documents, and a real shape: a run entered as one
    // total, with no per-employee breakdown, has nothing to derive from.
    $lump = Payroll::create([
        'asset_id' => $this->asset->id, 'period_month' => '2026-07-01',
        'gross_salaries' => 50000, 'salary_tax' => 5000, 'social_insurance' => 5500, 'net_paid' => 0,
        'paid_from' => 'bank', 'status' => 'draft',
    ]);

    expect(round((float) $lump->fresh()->gross_salaries, 2))->toBe(50000.0)
        ->and(round((float) $lump->fresh()->net_paid, 2))->toBe(39500.0);

    $lump->update(['gross_salaries' => 60000]);

    expect(round((float) $lump->fresh()->gross_salaries, 2))->toBe(60000.0);
});

it('refuses to move an APPROVED run\'s money', function () {
    app(PayrollService::class)->approve($this->run->fresh());

    expect(fn () => $this->run->fresh()->update(['salary_tax' => 1]))
        ->toThrow(DomainException::class);

    expect(fn () => $this->run->fresh()->update(['paid_from' => 'cash']))
        ->toThrow(DomainException::class);
});

it('refuses to add a payslip to an APPROVED run', function () {
    app(PayrollService::class)->approve($this->run->fresh());

    $other = Employee::create([
        'asset_id' => $this->asset->id, 'code' => 'E-2', 'name' => 'Sara Nabil',
        'hire_date' => '2026-01-01', 'status' => 'active',
        'base_salary' => 9000, 'payment_method' => 'bank',
    ]);

    expect(fn () => $this->run->fresh()->lines()->create([
        'employee_id' => $other->id, 'gross' => 9000, 'salary_tax' => 900,
        'social_insurance' => 990, 'employer_social_insurance' => 1620,
    ]))->toThrow(DomainException::class);

    // And the header did not move with it.
    expect(round((float) $this->run->fresh()->gross_salaries, 2))->toBe(10000.0);
});

it('refuses to edit or remove a payslip on an APPROVED run', function () {
    app(PayrollService::class)->approve($this->run->fresh());
    $line = $this->run->fresh()->lines()->first();

    expect(fn () => $line->update(['gross' => 20000]))->toThrow(DomainException::class);
    expect(fn () => $line->delete())->toThrow(DomainException::class);
});

it('still lets a DRAFT run be built and corrected', function () {
    // The control the five refusals need: without it they would pass just as happily if payroll
    // were frozen outright.
    $line = $this->run->fresh()->lines()->first();

    expect(fn () => $line->update(['gross' => 12000]))->not->toThrow(DomainException::class);
    expect(round((float) $this->run->fresh()->gross_salaries, 2))->toBe(12000.0);

    expect(fn () => $this->run->fresh()->update(['paid_from' => 'cash']))->not->toThrow(DomainException::class);
});

it('still allows the approval itself, and a cancel afterwards', function () {
    // The guard reads the ORIGINAL status, so the transition INTO approved is not blocked by its
    // own outcome — and cancelling a run must stay possible (it is the correction path).
    $approved = app(PayrollService::class)->approve($this->run->fresh());
    expect($approved->status)->toBe('approved');

    expect(fn () => app(PayrollService::class)->cancel($this->run->fresh()))->not->toThrow(DomainException::class);
});
