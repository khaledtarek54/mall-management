<?php

use App\Filament\Admin\Resources\TenantSalesDeclarations\Pages\ListTenantSalesDeclarations;
use App\Models\TenantSalesDeclaration;
use App\Services\PercentageRentCalculationService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * SW-171 — the View modal of a LOCKED declaration recomputed the figure it was BILLED at.
 *
 * Locking freezes the overage on the row and raises an invoice for it, which is why
 * `TenantSalesDeclarationResource::canEdit()` refuses a locked record outright. The comment on the
 * preview field said so and drew the wrong conclusion: a resource's `ViewAction` declares no schema
 * of its own, so Filament renders the RESOURCE FORM as the modal
 * (`Resources\Pages\ListRecords::configureAction()` → `infolist(form($schema))`, and
 * `Resource::infolist()` hands the schema back untouched), fills it from the record, and runs the
 * `afterStateHydrated` on `calculated_percentage_rent` — which passed no lock, so `refreshDerived()`
 * recomputed and overwrote the frozen number with a live estimate.
 *
 * It only diverges once something the calculation reads has moved, which is precisely the case an
 * operator opens the modal to check: a renegotiated percentage rate, a lease that stopped carrying
 * percentage rent, a sibling month of an annual year. The tenant's invoice still says 2,500; the
 * screen said 5,000, or nothing at all.
 *
 * The controls are the point: an UNLOCKED declaration must still show what a lock would charge —
 * the preview is why the field is filled on open at all — so a fix that simply stopped recomputing
 * would satisfy the refusals and remove the feature.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->asset = makeAsset();
    $this->unit = makeUnit($this->asset);
    $this->tenant = makeTenant();
    $this->operator = makeUser('super_admin', [$this->asset->id]);
    $this->actingAs($this->operator);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);

    // (100,000 − 50,000) × 5% = 2,500 for any month declared at 100,000.
    $this->lease = makeLease($this->unit, $this->tenant, [
        'status' => 'active',
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2028-12-31',
        'has_percentage_rent' => true,
        'percentage_rent_calculation_type' => 'artificial',
        'percentage_rent_frequency' => 'monthly',
        'percentage_rent_threshold' => 50_000,
        'percentage_rent_rate' => 5,
    ]);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/**
 * A submitted declaration for one month of the fixture lease.
 *
 * Named for this file: `declarationFor` and `evidenceDeclarationFor` are already declared elsewhere
 * under `tests/`, and two file-scope functions of one name is a fatal redeclaration that exits the
 * whole suite with no output (see `TestHelperUniquenessConformanceTest`).
 */
function salesDeclarationFor(int $leaseId, string $month, float $sales): TenantSalesDeclaration
{
    $start = CarbonImmutable::parse($month.'-01');

    return TenantSalesDeclaration::create([
        'lease_id' => $leaseId,
        'period_start' => $start,
        'period_end' => $start->endOfMonth(),
        'declared_sales' => $sales,
        'calculated_percentage_rent' => 0,
        'status' => 'submitted',
        'declared_at' => $start->endOfMonth()->addDays(2),
    ]);
}

/**
 * The percentage-rent figure the View modal actually puts on screen.
 *
 * Read off the MOUNTED modal's own state, because that is the only place the hydration hooks have
 * run — building the schema in a test proves nothing about what `afterStateHydrated` did.
 */
function viewModalPercentageRent(TenantSalesDeclaration $declaration): ?float
{
    $component = Livewire::test(ListTenantSalesDeclarations::class)
        ->mountTableAction('view', $declaration);

    // The modal really opened. Without this a hidden or refused action reads as a null figure and
    // every assertion below would pass for the wrong reason.
    expect($component->instance()->mountedActions)->not->toBeEmpty();

    $value = $component->instance()->mountedActions[0]['data']['calculated_percentage_rent'] ?? null;

    return $value === null ? null : (float) $value;
}

it('shows a locked declaration the figure it was billed at, not a fresh estimate', function () {
    $declaration = salesDeclarationFor($this->lease->id, '2026-03', 100_000);
    app(PercentageRentCalculationService::class)->lock($declaration, $this->operator);

    expect((float) $declaration->fresh()->calculated_percentage_rent)->toBe(2_500.0);

    // An ordinary amendment, months later. Nothing about March changes: an invoice was raised for
    // 2,500 and is still owed at 2,500.
    $this->lease->update(['percentage_rent_rate' => 10]);

    expect(viewModalPercentageRent($declaration->fresh()))->toBe(2_500.0);
});

it('does not blank the billed figure when the lease stops carrying percentage rent', function () {
    $declaration = salesDeclarationFor($this->lease->id, '2026-03', 100_000);
    app(PercentageRentCalculationService::class)->lock($declaration, $this->operator);

    // `refreshDerived()` answers null for a lease with no percentage-rent terms — so the worst
    // reading is not a wrong number, it is an EMPTY box beside an invoice for 2,500.
    $this->lease->update(['has_percentage_rent' => false]);

    expect(viewModalPercentageRent($declaration->fresh()))->toBe(2_500.0);
});

it('still previews what a lock would charge on a declaration nobody has locked', function () {
    // The control. The stored column is 0.00 until the lock writes it, so filling this field on open
    // is the whole reason the hook exists; a guard that fired on every record would delete it.
    $this->lease->update(['percentage_rent_rate' => 10]);

    $declaration = salesDeclarationFor($this->lease->id, '2026-04', 100_000);

    expect((float) $declaration->calculated_percentage_rent)->toBe(0.0)
        ->and(viewModalPercentageRent($declaration))->toBe(5_000.0);
});
