<?php

use App\Filament\Admin\Resources\FixedAssets\FixedAssetResource;
use App\Filament\Admin\Resources\FixedAssets\Pages\ListFixedAssets;
use App\Models\DepreciationEntry;
use App\Models\FixedAsset;
use App\Services\DisposeFixedAssetService;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

/**
 * SW-192 — the fixed-asset register's totals are the balance sheet's, so a sold asset is out of
 * them.
 *
 * A DISPOSED asset keeps its cost, its accumulated depreciation and its dates on the register for
 * the audit trail — "where did that chiller go?" is a question this screen has to answer, which is
 * why the status filter is deliberately not defaulted to Active. But `FixedAssetDisposalJournalizer`
 * has already credited the gross cost off Furniture & Equipment and debited the accumulated
 * depreciation back, so the books carry it at NOTHING.
 *
 * The footer summed every row anyway, under a label calling the net figure the one that agrees with
 * the balance sheet. Measured on `mall_management_qa` (2026-09-04): the GL carried 2,250,000.00 of
 * Furniture & Equipment against 338,166.64 of accumulated depreciation — 1,911,833.36 net — while
 * the screen read 2,325,000.00 / 362,333.21 / **1,962,666.79**, overstated by the disposed floor
 * scrubber's 50,833.43 of residual book value.
 *
 * All THREE totals narrow. Narrowing only the net one would leave the footer's own arithmetic
 * (cost − accumulated = NBV) broken, which reads as a fault in the subtraction rather than as the
 * tie-out it is.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->mall = makeAsset(['code' => 'SW192']);

    $this->live = FixedAsset::create([
        'asset_id' => $this->mall->id, 'name' => 'Chiller', 'tag' => 'SW192-LIVE',
        'acquisition_date' => '2026-01-01', 'acquisition_cost' => 100000, 'salvage_value' => 0,
        'useful_life_months' => 100, 'method' => 'straight_line', 'funded_from' => 'cash',
    ]);

    $this->sold = FixedAsset::create([
        'asset_id' => $this->mall->id, 'name' => 'Floor scrubber', 'tag' => 'SW192-SOLD',
        'acquisition_date' => '2026-01-01', 'acquisition_cost' => 75000, 'salvage_value' => 0,
        'useful_life_months' => 84, 'method' => 'straight_line', 'funded_from' => 'cash',
    ]);

    DepreciationEntry::create(['fixed_asset_id' => $this->live->id, 'period_month' => '2026-02-01', 'amount' => 20000]);
    DepreciationEntry::create(['fixed_asset_id' => $this->sold->id, 'period_month' => '2026-02-01', 'amount' => 25000]);

    $this->actingAs(makeUser('accounting', [$this->mall->id]));

    // Through the real service, so the row reaches `disposed` the only way production can.
    app(DisposeFixedAssetService::class)->dispose($this->sold, [
        'disposed_on' => '2026-03-01',
        'proceeds' => 40000,
    ]);
});

it('totals only what is still on the books, on all three money columns', function () {
    asTenant($this->mall, function () {
        Livewire::test(ListFixedAssets::class)
            ->assertTableColumnSummarySet('acquisition_cost', 'total', 100000.0)
            ->assertTableColumnSummarySet('accumulated', 'total', 20000.0)
            // 100,000 − 20,000, and the footer's own arithmetic still holds across the three.
            // The scrubber's 75,000 / 25,000 / 50,000 left the books with its write-off entry.
            ->assertTableColumnSummarySet('net_book_value', 'total', 80000.0);
    });
});

it('still lists the disposed asset — the TOTALS narrowed, not the register', function () {
    // THE CONTROL, and the one that matters most. "Fixing" this by hiding disposed rows would
    // satisfy the totals above and destroy the audit trail the register exists for.
    asTenant($this->mall, function () {
        Livewire::test(ListFixedAssets::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$this->live, $this->sold]);
    });
});

it('closes the register CSV on the same on-books totals as the screen', function () {
    // The export is the schedule an accountant reconciles the balance sheet against, so the two
    // may never disagree — and it keeps the sold asset's own row, with its historic figures.
    $csv = FixedAssetResource::registerCsv();

    $soldRow = collect($csv['rows'])->firstWhere(0, 'SW192-SOLD');
    expect($soldRow)->not->toBeNull()
        ->and((float) $soldRow[5])->toBe(75000.0)
        ->and((float) $soldRow[7])->toBe(25000.0)
        ->and((float) $soldRow[8])->toBe(50000.0);

    $total = collect($csv['rows'])->last();
    expect((float) $total[5])->toBe(100000.0)
        ->and((float) $total[7])->toBe(20000.0)
        ->and((float) $total[8])->toBe(80000.0);
});

it('reads a live asset as on the books and a disposed one as off them', function () {
    // The registry itself, asked directly — the one place the three summarizers and the CSV all
    // read from. A fourth reader added later inherits this.
    expect($this->live->fresh()->isOnBooks())->toBeTrue()
        ->and($this->sold->fresh()->isOnBooks())->toBeFalse()
        ->and(FixedAsset::ON_BOOKS_STATUSES)->not->toContain('disposed');
});
