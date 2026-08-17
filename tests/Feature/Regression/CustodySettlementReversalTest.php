<?php

use App\Models\Custody;
use App\Models\CustodyTransaction;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Services\Accounting\FiscalCalendar;
use App\Services\GrantCustodyService;
use App\Services\SettleCustodyService;
use App\Support\MorphMap;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Spatie\Activitylog\Models\Activity;

/**
 * Regression — gap-analysis **F-94** (module 25): custody settlements had no correction path.
 *
 * THE BUG. Every other money document in Atriom can be corrected (invoice → credit note, journal
 * entry → void, vendor bill → void, payroll → cancel). A custody settlement could not: no edit, no
 * delete, no reverse existed. A single mis-keyed amount (500 typed as 5,000) left `outstanding`
 * wrong and the GL over/understating an expense, unfixable short of super_admin deleting the WHOLE
 * custody — which cascades and voids the correct grant entry too, destroying the audit trail.
 *
 * THE FIX. `SettleCustodyService::reverse()` soft-deletes the settlement — which IS the void here:
 * `Custody::settled()` sums the soft-delete-aware `transactions()` relation (so `outstanding` goes
 * straight back up), and `CustodyTransaction` is a registered GL source whose real-time sync fires
 * on `deleted` (so `LedgerPoster::sync()` sees a trashed source and voids its entry). The row is
 * retained for audit, with an explicit `reversed` activity capturing who + why.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    $this->asset = makeAsset(['code' => 'REV']);
    $this->actor = makeUser('accounting', [$this->asset->id]);
    $this->actingAs($this->actor);

    $this->employee = Employee::create([
        'asset_id' => $this->asset->id, 'code' => 'REV-1', 'name' => 'Ahmed',
        'hire_date' => now()->startOfYear()->toDateString(), 'base_salary' => 6000, 'payment_method' => 'cash',
    ]);

    $this->custody = app(GrantCustodyService::class)->grant($this->employee, [
        'amount' => 10000, 'custody_date' => now()->startOfMonth()->toDateString(), 'paid_from' => 'cash',
    ]);
});

/** A settlement of $amount, dated today. */
function revSettle(float $amount): CustodyTransaction
{
    return app(SettleCustodyService::class)->settle(test()->custody->fresh(), [
        'type' => 'expense', 'amount' => $amount, 'category' => 'cleaning_security',
        'transaction_date' => now()->toDateString(),
    ]);
}

it('restores outstanding when a settlement is reversed', function () {
    // The audit's scenario: 500 spent, 5,000 recorded.
    $txn = revSettle(5000);
    expect($this->custody->fresh()->outstanding())->toBe(5000.0);

    app(SettleCustodyService::class)->reverse($txn, 'Typo — should have been 500');

    expect($this->custody->fresh()->outstanding())->toBe(10000.0, 'the money is back in the custodian\'s hands')
        ->and($this->custody->fresh()->transactions()->count())->toBe(0, 'the settlement no longer counts')
        ->and(CustodyTransaction::withTrashed()->find($txn->id)->trashed())->toBeTrue('the row is retained for audit');
});

it('voids the settlement\'s ledger entry through the real sweep', function () {
    $txn = revSettle(5000);
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertExitCode(0);
    expect(JournalEntry::where('source_type', MorphMap::alias(CustodyTransaction::class))->where('source_id', $txn->id)
        ->where('status', 'posted')->exists())->toBeTrue('precondition: it posted');

    app(SettleCustodyService::class)->reverse($txn, 'Wrong amount');
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertExitCode(0);

    expect(JournalEntry::where('source_type', MorphMap::alias(CustodyTransaction::class))->where('source_id', $txn->id)
        ->where('status', 'posted')->exists())->toBeFalse('the entry is voided — no live GL effect');
});

it('records who reversed it and why', function () {
    $txn = revSettle(5000);

    app(SettleCustodyService::class)->reverse($txn, 'Duplicate of the earlier receipt');

    $entry = Activity::where('log_name', 'custody_transaction')->where('event', 'reversed')->latest('id')->first();
    expect($entry)->not->toBeNull()
        ->and($entry->causer_id)->toBe($this->actor->id)
        ->and($entry->properties['reason'])->toBe('Duplicate of the earlier receipt');
});

it('unlocks the custody amount when its only settlement is reversed', function () {
    // "Locked once settled" keys on transactions()->exists() (trashed-excluding). Reversing the
    // only settlement restores the pre-settlement state, amount editable again.
    $txn = revSettle(3000);
    expect($this->custody->fresh()->transactions()->exists())->toBeTrue();

    app(SettleCustodyService::class)->reverse($txn, 'Recorded against the wrong custody');

    expect($this->custody->fresh()->transactions()->exists())->toBeFalse('the amount unlocks');
});

it('refuses to reverse the same settlement twice', function () {
    $txn = revSettle(2000);
    app(SettleCustodyService::class)->reverse($txn, 'First reversal');

    expect(fn () => app(SettleCustodyService::class)->reverse($txn->fresh(), 'Again'))
        ->toThrow(DomainException::class);
});

it('leaves other settlements untouched when one is reversed', function () {
    $a = revSettle(2000);
    revSettle(3000);
    expect($this->custody->fresh()->outstanding())->toBe(5000.0);

    app(SettleCustodyService::class)->reverse($a, 'Only this one was wrong');

    expect($this->custody->fresh()->outstanding())->toBe(7000.0)   // 10,000 − 3,000 that remains
        ->and($this->custody->fresh()->transactions()->count())->toBe(1);
});
