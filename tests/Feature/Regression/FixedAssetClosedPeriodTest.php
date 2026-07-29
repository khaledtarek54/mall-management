<?php

/**
 * Module 23's operator-typed dates must respect a closed accounting period.
 *
 * Three dates in this module become a journal entry's `entry_date`, and all three
 * are typed by an operator on a freely-editable DatePicker:
 *
 *   acquisition_date → FixedAssetAcquisitionJournalizer
 *   disposed_on      → FixedAssetDisposalJournalizer
 *   period_month     → DepreciationEntryJournalizer (via --month)
 *
 * None of them went through App\Support\PostingDate, which every other
 * money-writing service in the app uses (VendorBillService, StockMovementService,
 * CreditNoteService, ApplyTenantCreditService, FinaliseOwnerStatementRunService).
 *
 * The failure is silent and asymmetric, which is what makes it dangerous: the
 * register row commits and the operator is told it worked, while the journal
 * entry is refused inside a best-effort queued job that only logs. The asset
 * leaves the books in one state and the GL in another — and for a disposal that
 * means Furniture & Equipment keeps carrying an asset the company has sold.
 *
 * A MISSING period stays legal (fresh installs / pre-accounting dates); only a
 * CLOSED one is refused.
 */

use App\Models\AccountingPeriod;
use App\Models\DepreciationEntry;
use App\Models\FixedAsset;
use App\Models\FixedAssetDisposal;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\PeriodService;
use App\Services\DisposeFixedAssetService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear(2026);

    $this->asset = makeAsset();
    $this->fixedAsset = FixedAsset::create([
        'asset_id' => $this->asset->id,
        'name' => 'Chiller unit',
        'tag' => 'FA-001',
        'category' => 'equipment',
        'acquisition_date' => '2026-01-15',
        'acquisition_cost' => 120000,
        'salvage_value' => 0,
        'useful_life_months' => 60,
        'status' => 'active',
        'funded_from' => 'bank',
    ]);
});

/** Seal the month covering $date through the real close service, as an operator would. */
function faClosePeriod(string $date): void
{
    test()->artisan('accounting:sync-ledger', ['--all' => true])->assertExitCode(0);

    app(PeriodService::class)->closePeriod(AccountingPeriod::forDate(Carbon::parse($date)));
}

it('refuses a disposal dated into a CLOSED period', function () {
    faClosePeriod('2026-03-20');

    expect(fn () => app(DisposeFixedAssetService::class)->dispose($this->fixedAsset, [
        'disposed_on' => '2026-03-20',
        'proceeds' => 50000,
        'proceeds_account' => 'bank',
    ]))->toThrow(DomainException::class);
});

it('leaves the asset ACTIVE when the disposal is refused', function () {
    // The divergence itself: without the guard the register row commits (status =
    // disposed, the operator sees "Disposed ✓") while the write-off entry is refused
    // in a best-effort job — so Furniture & Equipment goes on carrying an asset the
    // company no longer owns, and nothing on screen says so.
    faClosePeriod('2026-03-20');

    try {
        app(DisposeFixedAssetService::class)->dispose($this->fixedAsset, [
            'disposed_on' => '2026-03-20',
            'proceeds' => 50000,
        ]);
    } catch (DomainException) {
        // expected
    }

    expect($this->fixedAsset->fresh()->status)->toBe('active')
        ->and($this->fixedAsset->fresh()->disposed_on)->toBeNull()
        ->and(FixedAssetDisposal::count())->toBe(0, 'A refused disposal left a write-off row behind.');
});

it('allows a disposal into an OPEN period', function () {
    $disposal = app(DisposeFixedAssetService::class)->dispose($this->fixedAsset, [
        'disposed_on' => '2026-03-20',
        'proceeds' => 50000,
    ]);

    expect($disposal->exists)->toBeTrue()
        ->and($this->fixedAsset->fresh()->status)->toBe('disposed');
});

it('allows a disposal dated where NO period exists', function () {
    // A missing period is not a closed one. Refusing here would break every install
    // that has not adopted accounting yet — the mistake PostingDate's docblock records.
    expect(AccountingPeriod::forDate(Carbon::parse('2027-05-20')))->toBeNull();

    $disposal = app(DisposeFixedAssetService::class)->dispose($this->fixedAsset, [
        'disposed_on' => '2027-05-20',
        'proceeds' => 0,
    ]);

    expect($disposal->exists)->toBeTrue();
});

it('still refuses a second disposal (terminal) before looking at the date', function () {
    app(DisposeFixedAssetService::class)->dispose($this->fixedAsset, [
        'disposed_on' => '2027-05-20',
        'proceeds' => 0,
    ]);

    expect(fn () => app(DisposeFixedAssetService::class)->dispose($this->fixedAsset->fresh(), [
        'disposed_on' => '2027-06-20',
        'proceeds' => 0,
    ]))->toThrow(HttpException::class);
});

/* ---- the other two dates in this module --------------------------------- */

it('refuses a new asset ACQUIRED into a closed period', function () {
    faClosePeriod('2026-03-20');

    expect(fn () => FixedAsset::create([
        'asset_id' => $this->asset->id,
        'name' => 'Back-dated generator',
        'tag' => 'FA-002',
        'category' => 'equipment',
        'acquisition_date' => '2026-03-10',
        'acquisition_cost' => 90000,
        'salvage_value' => 0,
        'useful_life_months' => 60,
        'status' => 'active',
        'funded_from' => 'cash',
    ]))->toThrow(DomainException::class);

    expect(FixedAsset::where('tag', 'FA-002')->exists())->toBeFalse();
});

it('refuses MOVING an existing asset\'s acquisition date into a closed period', function () {
    faClosePeriod('2026-03-20');

    expect(fn () => $this->fixedAsset->update(['acquisition_date' => '2026-03-10']))
        ->toThrow(DomainException::class);
});

it('still lets an asset acquired in a since-closed month be edited', function () {
    // The guard is about MOVING an entry into a sealed period, not about freezing the
    // record. Re-checking on every save would make this asset uneditable — you could
    // not correct its name or tag — which is a different rule from the one intended.
    faClosePeriod('2026-01-20'); // the month this asset was acquired in

    $this->fixedAsset->update(['name' => 'Chiller unit (corrected)']);

    expect($this->fixedAsset->fresh()->name)->toBe('Chiller unit (corrected)');
});

it('refuses depreciating into a closed month from the console', function () {
    faClosePeriod('2026-03-20');

    $this->artisan('accounting:post-depreciation', ['--month' => '2026-03'])
        ->assertExitCode(1);

    expect(DepreciationEntry::count())->toBe(
        0,
        'Charges were written for a month whose journal entries can never post.'
    );
});

it('still depreciates into an open month', function () {
    $this->artisan('accounting:post-depreciation', ['--month' => '2026-03'])
        ->assertExitCode(0);

    expect(DepreciationEntry::count())->toBeGreaterThan(0);
});
