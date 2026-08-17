<?php

use App\Filament\Admin\Resources\TenantSalesDeclarations\Pages\EditTenantSalesDeclaration;
use App\Filament\Admin\Resources\TenantSalesDeclarations\TenantSalesDeclarationResource;
use App\Models\Charge;
use App\Models\Invoice;
use App\Models\TenantSalesDeclaration;
use App\Services\PercentageRentCalculationService;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Locking a sales declaration MUST go through PercentageRentCalculationService
 * (which creates the percentage_rent billing charge). A raw status='locked'
 * write via the admin edit form would silently skip billing (revenue leak), and
 * editing a locked record back to 'submitted' + re-locking would double-bill.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'PR']);
    $this->tenant = makeTenant();
    $this->actingAs(makeUser('manager', [$this->asset->id])); // holds tenant_sales.edit
    Filament::setTenant($this->asset);
});

function submittedDeclaration($asset, $tenant, float $sales = 60000): TenantSalesDeclaration
{
    $lease = makeLease(makeUnit($asset), $tenant, [
        'status' => 'active', 'has_percentage_rent' => true,
        'percentage_rent_calculation_type' => 'artificial',
        'percentage_rent_threshold' => 50000, 'percentage_rent_rate' => 5,
        'base_rent_monthly' => 10000,
    ]);

    return TenantSalesDeclaration::create([
        'lease_id' => $lease->id,
        'period_start' => '2026-01-01', 'period_end' => '2026-01-31',
        'declared_sales' => $sales, 'calculated_percentage_rent' => 0,
        'status' => 'submitted', 'declared_at' => now(),
        'declared_by_type' => $tenant::class, 'declared_by_id' => $tenant->id,
    ]);
}

it('the edit form cannot lock a declaration (no charge bypass)', function () {
    $decl = submittedDeclaration($this->asset, $this->tenant);

    Livewire::test(EditTenantSalesDeclaration::class, ['record' => $decl->getRouteKey()])
        ->fillForm(['status' => 'locked', 'declared_sales' => 60000])
        ->call('save')
        ->assertHasNoFormErrors();

    // status is read-only → never written; no percentage_rent charge created.
    expect($decl->fresh()->status)->toBe('submitted')
        ->and($decl->fresh()->locked_at)->toBeNull()
        ->and(Charge::where('lease_id', $decl->lease_id)->where('type', 'percentage_rent')->count())->toBe(0);
});

it('treats a locked declaration as immutable (canEdit is false)', function () {
    $decl = submittedDeclaration($this->asset, $this->tenant);
    app(PercentageRentCalculationService::class)->lock($decl, auth()->user());

    expect(TenantSalesDeclarationResource::canEdit($decl->fresh()))->toBeFalse();
});

it('never leaves two live overage invoices when a declaration is re-locked', function () {
    $svc = app(PercentageRentCalculationService::class);
    $decl = submittedDeclaration($this->asset, $this->tenant);

    $svc->lock($decl, auth()->user()); // bills overage invoice #1

    // Simulate the old bug path (raw status reset) then re-lock.
    $decl->forceFill(['status' => 'submitted'])->saveQuietly();
    $svc->lock($decl->fresh(), auth()->user()); // reverses #1, bills a fresh #2

    // The overage is billed as an immediate invoice, not an active monthly charge, so the
    // double-bill guard is: exactly ONE live (non-cancelled) overage invoice survives a re-lock.
    $live = Invoice::whereHas('items', fn ($q) => $q->where('type', 'percentage_rent'))
        ->where('tenant_id', $decl->lease->tenant_id)
        ->whereNotIn('status', ['cancelled', 'credited'])
        ->count();

    expect($live)->toBe(1);
});
