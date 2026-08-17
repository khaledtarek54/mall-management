<?php

use App\Models\Expense;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Services\Reports\ReportService;
use App\Support\CostNature;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Facades\Filament;

/**
 * FR-FIN-02 — the weekly operating-cost report, split fixed vs variable. Reads Expense + VendorBill
 * (same category set → App\Support\CostNature) as the EX-VAT cost, by ISO week, property-scoped.
 */
it('classifies each category fixed vs variable, defaulting an unknown one to variable', function () {
    expect(CostNature::forCategory('admin'))->toBe('fixed')
        ->and(CostNature::forCategory('cleaning_security'))->toBe('fixed')
        ->and(CostNature::forCategory('utilities'))->toBe('variable')
        ->and(CostNature::forCategory('maintenance'))->toBe('variable')
        ->and(CostNature::forCategory('marketing'))->toBe('variable')
        ->and(CostNature::forCategory('other'))->toBe('variable')
        ->and(CostNature::forCategory('not-a-category'))->toBe('variable'); // conservative default

    expect(CostNature::categoriesOf('fixed'))->toContain('admin', 'cleaning_security')->not->toContain('utilities');
});

it('sums weekly spend split fixed/variable, ex-VAT, across expenses + bills, scoped to the property', function () {
    $asset = makeAsset(['code' => 'WSP']);
    $this->actingAs(User::factory()->create());
    Filament::setTenant($asset);

    $wk = CarbonImmutable::now()->startOfWeek(CarbonInterface::MONDAY);

    // FIXED: an admin expense — amount 1000 ex-VAT (+140 VAT).
    Expense::create([
        'asset_id' => $asset->id, 'category' => 'admin', 'amount' => 1000, 'vat_amount' => 140,
        'total' => 1140, 'paid_from' => 'cash', 'expense_date' => $wk->addDay()->toDateString(), 'status' => 'recorded',
    ]);
    // VARIABLE: a utilities vendor bill — total 570 incl. 70 VAT → 500 ex-VAT.
    $vendor = Vendor::create(['name' => 'PowerCo', 'category' => 'utilities', 'status' => 'active']);
    VendorBill::create([
        'vendor_id' => $vendor->id, 'asset_id' => $asset->id, 'category' => 'utilities',
        'bill_date' => $wk->addDays(2)->toDateString(), 'due_date' => $wk->addDays(20)->toDateString(),
        'subtotal' => 500, 'vat_amount' => 70, 'total' => 570, 'status' => 'approved',
    ]);

    // A DIFFERENT property's spend in the same week must NOT leak into this mall's report.
    $other = makeAsset(['code' => 'OTH']);
    Expense::create([
        'asset_id' => $other->id, 'category' => 'admin', 'amount' => 9999, 'vat_amount' => 0,
        'total' => 9999, 'paid_from' => 'cash', 'expense_date' => $wk->addDay()->toDateString(), 'status' => 'recorded',
    ]);
    // A cancelled bill must not count.
    VendorBill::create([
        'vendor_id' => $vendor->id, 'asset_id' => $asset->id, 'category' => 'maintenance',
        'bill_date' => $wk->addDays(3)->toDateString(), 'due_date' => $wk->addDays(20)->toDateString(),
        'subtotal' => 4000, 'vat_amount' => 0, 'total' => 4000, 'status' => 'cancelled',
    ]);

    $report = app(ReportService::class)->weeklySpend($wk, $wk->endOfWeek(CarbonInterface::SUNDAY));
    $thisWeek = collect($report['weeks'])->firstWhere('week_start', $wk->toDateString());

    expect($thisWeek)->not->toBeNull()
        ->and($thisWeek['fixed'])->toBe(1000.0)      // the admin expense, ex-VAT
        ->and($thisWeek['variable'])->toBe(500.0)    // the utilities bill, ex-VAT (cancelled one excluded)
        ->and($thisWeek['total'])->toBe(1500.0)
        ->and($report['totals']['total'])->toBe(1500.0); // other property's 9999 excluded
});

it('pre-seeds every week in the range so a spend-free week reads as zero, not a gap', function () {
    $asset = makeAsset(['code' => 'ZRO']);
    $this->actingAs(User::factory()->create());
    Filament::setTenant($asset);

    $to = CarbonImmutable::now()->endOfWeek(CarbonInterface::SUNDAY);
    $from = $to->subWeeks(3)->startOfWeek(CarbonInterface::MONDAY); // 4 weeks

    $report = app(ReportService::class)->weeklySpend($from, $to);

    expect($report['weeks'])->toHaveCount(4)
        ->and(collect($report['weeks'])->every(fn ($w) => $w['total'] === 0.0))->toBeTrue();
});
