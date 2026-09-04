<?php

use App\Models\Custody;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Services\Accounting\FiscalCalendar;
use App\Services\GrantCustodyService;
use App\Services\GrantEmployeeAdvanceService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;

/*
|--------------------------------------------------------------------------
| A grant is dated the day the money leaves (SW-110)
|--------------------------------------------------------------------------
| Handing an employee an advance, and handing a custodian a عهدة, are the same act: cash goes out
| of the till or the bank, and the date the operator types becomes the journal entry's `entry_date`
| (`EmployeeAdvanceJournalizer:43`, `CustodyJournalizer:41`).
|
| Both SETTLEMENT halves have refused a future date since F-93: `RecordAdvanceRepaymentService:40`
| and `SettleCustodyService:40` both call `PostingDate::assertNotFuture()`, and both their pickers
| carry `->maxDate(now())`. Both GRANT halves called `PostingDate::assertOpen()` instead, which
| deliberately says nothing about the future, and neither picker was bounded at all.
|
| Measured at HEAD 2026-09-04:
|   PostingDate::assertOpen('2027-03-04', 'advance_date')      → ACCEPTED, returns the date
|   PostingDate::assertNotFuture('2027-03-04', 'advance_date') → refused
|
| So cash could be booked out of a period that has not happened. The money has not moved, the
| custodian is not yet on the hook for it, and the period the entry lands in will later close
| around it — at which point the correction is refused too, because correcting it moves a document
| whose entry sits in a sealed period (`SealedPeriod`).
|
| The fix is the smallest one that closes both: the same guard the two settlement halves already
| use, in the SERVICE (a picker is UX, and the API and console never see it), plus the picker bound
| the settlement halves already carry.
*/
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    $this->asset = makeAsset(['code' => 'GRNT']);
    $this->employee = Employee::create([
        'asset_id' => $this->asset->id, 'code' => 'GRNT-1', 'name' => 'Mona Saleh',
        'hire_date' => now()->startOfYear()->toDateString(),
        'base_salary' => 9000, 'payment_method' => 'cash',
    ]);

    $this->future = now()->addDays(30)->toDateString();
});

it('refuses an employee advance dated after the money could have moved', function () {
    expect(fn () => app(GrantEmployeeAdvanceService::class)->grant($this->employee, [
        'amount' => 5000,
        'advance_date' => $this->future,
        'paid_from' => 'cash',
    ]))->toThrow(DomainException::class);

    // Nothing may survive the refusal: an advance row is what the payroll deduction reads, so a
    // grant with no cash behind it starts deducting from a salary for money nobody handed over.
    expect(EmployeeAdvance::count())->toBe(0);
});

it('still grants an advance dated today', function () {
    $advance = app(GrantEmployeeAdvanceService::class)->grant($this->employee, [
        'amount' => 5000,
        'advance_date' => now()->toDateString(),
        'paid_from' => 'cash',
    ]);

    expect($advance->exists)->toBeTrue()
        ->and($advance->advance_date->toDateString())->toBe(now()->toDateString());
});

it('refuses a custody granted after the money could have moved', function () {
    expect(fn () => app(GrantCustodyService::class)->grant($this->employee, [
        'amount' => 10000,
        'custody_date' => $this->future,
        'paid_from' => 'cash',
    ]))->toThrow(DomainException::class);

    expect(Custody::count())->toBe(0);
});

it('still grants a custody dated today', function () {
    $custody = app(GrantCustodyService::class)->grant($this->employee, [
        'amount' => 10000,
        'custody_date' => now()->toDateString(),
        'paid_from' => 'cash',
    ]);

    expect($custody->exists)->toBeTrue()
        ->and($custody->custody_date->toDateString())->toBe(now()->toDateString());
});

it('bounds both grant date pickers at today, exactly as their settlement halves already are', function () {
    // The service is the gate; the picker is what stops the mistake being made. The settlement
    // halves are swept alongside the grant halves deliberately — a sweep that found only the two
    // being fixed could pass by finding nothing the day either file is renamed.
    $pickers = [
        ['Filament/Admin/RelationManagers/EmployeeAdvancesRelationManager.php', "DatePicker::make('advance_date')"],
        ['Filament/Admin/Resources/Custodies/Schemas/CustodyForm.php', "DatePicker::make('custody_date')"],
        ['Filament/Admin/RelationManagers/EmployeeAdvancesRelationManager.php', "DatePicker::make('repaid_on')"],
        ['Filament/Admin/RelationManagers/CustodyTransactionsRelationManager.php', "DatePicker::make('transaction_date')"],
    ];

    $unbounded = [];
    $seen = [];

    foreach ($pickers as [$relative, $needle]) {
        // Comments stripped: `CustodyTransactionsRelationManager` explains its own bound in prose a
        // line above it, and a gate that fires on a sentence is one that gets weakened rather
        // than fixed.
        $lines = explode("\n", sourceWithoutComments(app_path($relative)));

        foreach ($lines as $i => $line) {
            if (! str_contains($line, $needle)) {
                continue;
            }

            $seen[$needle] = ($seen[$needle] ?? 0) + 1;
            $chain = '';

            for ($j = $i + 1; $j < min($i + 14, count($lines)); $j++) {
                if (str_contains($lines[$j], '::make(')) {
                    break;
                }

                $chain .= $lines[$j];
            }

            if (! str_contains($chain, '->maxDate(')) {
                $unbounded[] = basename($relative).' → '.$needle;
            }
        }
    }

    // The premise. Every needle must have matched something, or this reports on a set it stopped
    // collecting — the failure this codebase has recorded three times.
    foreach ($pickers as [, $needle]) {
        expect($seen[$needle] ?? 0)->toBeGreaterThanOrEqual(1, "No block found for {$needle}");
    }

    expect($unbounded)->toBe([], "A date picker on money leaving the business, with no upper bound:\n  ".implode("\n  ", $unbounded));
});
