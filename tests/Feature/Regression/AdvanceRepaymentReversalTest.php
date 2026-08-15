<?php

use App\Support\MorphMap;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeAdvanceRepayment;
use App\Models\JournalEntry;
use App\Services\Accounting\FiscalCalendar;
use App\Services\GrantEmployeeAdvanceService;
use App\Services\RecordAdvanceRepaymentService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Spatie\Activitylog\Models\Activity;

/**
 * Regression — gap-analysis **F-91** (module 24): advance repayments had no correction path.
 *
 * THE BUG. Every other money document in Atriom can be corrected (invoice → credit note, vendor
 * bill → void, custody settlement → reverse). A repayment could not: no edit, no delete, no
 * reverse. A single mis-keyed amount (500 typed as 5,000) left `outstanding` wrong and cash
 * overstated, and a compensating negative is blocked by the amount > 0 guard — the only escape
 * was super_admin soft-deleting the WHOLE advance, which cascades and voids the correct grant
 * entry too.
 *
 * THE FIX. `RecordAdvanceRepaymentService::reverse()` soft-deletes the repayment — which IS the
 * void: `EmployeeAdvance::repaid()` sums the soft-delete-aware `repayments()` relation (so
 * `outstanding` goes back up), and `EmployeeAdvanceRepayment` is a registered GL source whose
 * real-time sync fires on `deleted` (so `LedgerPoster::sync()` sees a trashed source and voids
 * its entry). The row is retained for audit, with a `reversed` activity capturing who + why.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    $this->asset = makeAsset(['code' => 'ADV']);
    $this->actor = makeUser('accounting', [$this->asset->id]);
    $this->actingAs($this->actor);

    $this->employee = Employee::create([
        'asset_id' => $this->asset->id, 'code' => 'ADV-1', 'name' => 'Sara',
        'hire_date' => now()->startOfYear()->toDateString(), 'base_salary' => 6000, 'payment_method' => 'cash',
    ]);

    $this->advance = app(GrantEmployeeAdvanceService::class)->grant($this->employee, [
        'amount' => 10000, 'advance_date' => now()->startOfMonth()->toDateString(), 'paid_from' => 'cash',
    ]);
});

/** A repayment of $amount against the advance, dated today. */
function advRepay(float $amount): EmployeeAdvanceRepayment
{
    return app(RecordAdvanceRepaymentService::class)->record(test()->advance->fresh(), [
        'amount' => $amount, 'repaid_on' => now()->toDateString(), 'method' => 'cash',
    ]);
}

it('restores outstanding when a repayment is reversed', function () {
    // The audit's scenario: 500 owed back, 5,000 recorded.
    $r = advRepay(5000);
    expect($this->advance->fresh()->outstanding())->toBe(5000.0);

    app(RecordAdvanceRepaymentService::class)->reverse($r, 'Typo — should have been 500');

    expect($this->advance->fresh()->outstanding())->toBe(10000.0, 'the full advance is outstanding again')
        ->and($this->advance->fresh()->repaid())->toBe(0.0, 'the repayment no longer counts')
        ->and(EmployeeAdvanceRepayment::withTrashed()->find($r->id)->trashed())->toBeTrue('the row is retained for audit');
});

it('voids the repayment\'s ledger entry through the real sweep', function () {
    $r = advRepay(5000);
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertExitCode(0);
    expect(JournalEntry::where('source_type', MorphMap::alias(EmployeeAdvanceRepayment::class))->where('source_id', $r->id)
        ->where('status', 'posted')->exists())->toBeTrue('precondition: it posted');

    app(RecordAdvanceRepaymentService::class)->reverse($r, 'Wrong amount');
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertExitCode(0);

    expect(JournalEntry::where('source_type', MorphMap::alias(EmployeeAdvanceRepayment::class))->where('source_id', $r->id)
        ->where('status', 'posted')->exists())->toBeFalse('the entry is voided — no live GL effect');
});

it('records who reversed it and why', function () {
    $r = advRepay(5000);

    app(RecordAdvanceRepaymentService::class)->reverse($r, 'Duplicate of the earlier receipt');

    $entry = Activity::where('log_name', 'employee_advance_repayment')->where('event', 'reversed')->latest('id')->first();
    expect($entry)->not->toBeNull()
        ->and($entry->causer_id)->toBe($this->actor->id)
        ->and($entry->properties['reason'])->toBe('Duplicate of the earlier receipt');
});

it('refuses to reverse the same repayment twice', function () {
    $r = advRepay(2000);
    app(RecordAdvanceRepaymentService::class)->reverse($r, 'First reversal');

    expect(fn () => app(RecordAdvanceRepaymentService::class)->reverse($r->fresh(), 'Again'))
        ->toThrow(DomainException::class);
});

it('leaves other repayments untouched when one is reversed', function () {
    $a = advRepay(2000);
    advRepay(3000);
    expect($this->advance->fresh()->outstanding())->toBe(5000.0);

    app(RecordAdvanceRepaymentService::class)->reverse($a, 'Only this one was wrong');

    expect($this->advance->fresh()->outstanding())->toBe(7000.0)   // 10,000 − 3,000 that remains
        ->and($this->advance->fresh()->repayments()->count())->toBe(1);
});

it('frees the reversed amount to be re-recorded correctly', function () {
    // The whole point: reverse the wrong 5,000, then record the right 500.
    $wrong = advRepay(5000);
    app(RecordAdvanceRepaymentService::class)->reverse($wrong, 'Should have been 500');

    $right = advRepay(500);

    expect($right)->not->toBeNull()
        ->and($this->advance->fresh()->outstanding())->toBe(9500.0);
});
