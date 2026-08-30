<?php

declare(strict_types=1);

use App\Filament\Admin\RelationManagers\LeaseSalesDeclarationsRelationManager;
use App\Filament\Admin\Resources\Leases\Pages\EditLease;
use App\Filament\Admin\Resources\TenantSalesDeclarations\Pages\CreateTenantSalesDeclaration;
use App\Filament\Admin\Resources\TenantSalesDeclarations\Pages\ListTenantSalesDeclarations;
use App\Models\Lease;
use App\Models\TenantSalesDeclaration;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Livewire\Livewire;

/**
 * `0.00` AND "no clause" ARE DIFFERENT FACTS AND RENDERED IDENTICALLY.
 *
 * Since a lease may now declare turnover without charging on it, the register mixes two kinds of
 * row. On a percentage lease `0.00` means "computed, and the turnover did not reach the breakpoint"
 * — Zara at 640,000 against a 909,091 natural breakpoint. On a reporting-only lease there is no
 * computation at all. An accountant reading the register FOR the percentage rent cannot tell those
 * apart from a column of zeroes, and would read the second as a tenant who earned nothing.
 *
 * A dash where there is no clause, and a filter so the register can be narrowed to either question.
 */
beforeEach(function (): void {
    $this->seed(RolesPermissionsSeeder::class);

    $user = User::factory()->create();
    $user->assignRole('super_admin');
    $this->actingAs($user);

    $this->charging = Lease::factory()->create([
        'status' => 'active',
        'commencement_date' => CarbonImmutable::parse('2026-01-01'),
        'expiry_date' => CarbonImmutable::parse('2028-12-31'),
        'base_rent_monthly' => 50_000,
        'has_percentage_rent' => true,
        'percentage_rent_rate' => 7,
        'percentage_rent_calculation_type' => 'artificial',
        'percentage_rent_threshold' => 800_000,
        'percentage_rent_frequency' => 'monthly',
        'escalation_type' => 'none',
    ]);

    $asset = $this->charging->unit->asset;
    Filament::setTenant($asset);

    $this->reportingOnly = Lease::factory()->create([
        'status' => 'active',
        'unit_id' => Unit::factory()->create(['asset_id' => $asset->id])->id,
        'commencement_date' => CarbonImmutable::parse('2026-01-01'),
        'expiry_date' => CarbonImmutable::parse('2028-12-31'),
        'has_percentage_rent' => false,
        'requires_sales_reporting' => true,
        'escalation_type' => 'none',
    ]);

    $declare = fn (Lease $lease, float $sales) => TenantSalesDeclaration::create([
        'lease_id' => $lease->id,
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-30',
        'gross_sales' => $sales,
        'declared_sales' => $sales,
        'declared_at' => '2026-07-03',
        'status' => 'submitted',
    ]);

    // Both under their respective lines, so both would print 0.00 without the fix.
    $this->charged = $declare($this->charging, 600_000);
    $this->reported = $declare($this->reportingOnly, 900_000);
});

function registerColumn(): TextColumn
{
    return Livewire::test(ListTenantSalesDeclarations::class)
        ->instance()
        ->getTable()
        ->getColumn('calculated_percentage_rent');
}

it('prints a dash where there is no percentage-rent clause', function (): void {
    $column = registerColumn();

    expect((string) $column->record($this->reported)->formatState(0))->toBe('—');
});

it('still prints the figure — including a real zero — where there is a clause', function (): void {
    $column = registerColumn();

    // The control, and the whole point: a percentage lease whose turnover fell short DID compute,
    // and 0.00 is the honest answer there.
    expect((string) $column->record($this->charged)->formatState(0))->toContain('0.00');
});

it('offers a filter so the register can be read for either question', function (): void {
    $filter = Livewire::test(ListTenantSalesDeclarations::class)
        ->instance()
        ->getTable()
        ->getFilter('has_percentage_rent');

    expect($filter)->not->toBeNull();
});

it('lets a declaration be started from the lease it belongs to', function (): void {
    $actions = Livewire::test(LeaseSalesDeclarationsRelationManager::class, [
        'ownerRecord' => $this->charging,
        'pageClass' => EditLease::class,
    ])->instance()->getTable()->getHeaderActions();

    expect(collect($actions)->map(fn ($a) => $a->getName())->all())->toContain('declare');
});

it('opens the create form on the lease that sent it', function (): void {
    $data = Livewire::withQueryParams(['lease' => $this->charging->getKey()])
        ->test(CreateTenantSalesDeclaration::class)
        ->get('data');

    expect((int) $data['lease_id'])->toBe($this->charging->getKey());
});

it('ignores a lease the reader cannot see', function (): void {
    $elsewhere = Lease::factory()->create(['status' => 'active']);

    // Filament's tenant is still the FIRST property, so the resource's scoped query cannot reach
    // this lease. Prefilling a value the form would later refuse reads as the page being broken.
    $data = Livewire::withQueryParams(['lease' => $elsewhere->getKey()])
        ->test(CreateTenantSalesDeclaration::class)
        ->get('data');

    expect($data['lease_id'] ?? null)->not->toBe($elsewhere->getKey());
});
