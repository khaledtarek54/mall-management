<?php

use App\Filament\Admin\Resources\TenantSalesDeclarations\Pages\ListTenantSalesDeclarations;
use App\Filament\Admin\Resources\TenantSalesDeclarations\Tables\TenantSalesDeclarationsTable;
use App\Models\Invoice;
use App\Models\TenantSalesDeclaration;
use App\Services\PercentageRentCalculationService;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * The lock / dispute / voidLocked actions gated permission + status ONLY in visible(). Filament's
 * mountAction() never checks isVisible(), and the seeded `viewer` + `owner` roles hold every .view
 * (incl. tenant_sales.view) — so the list renders and a crafted dispatch ran the action: a read-only
 * auditor or owner Jawad could LOCK (bill an overage invoice + post GL), DISPUTE, or VOID a locked
 * declaration. These pin the abort_unless gate in action() via mountAction+callMountedAction (NOT
 * callAction / assertTableActionHidden, which check only visible() and would false-pass). Mirrors
 * CamActionAuthzTest.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->asset = makeAsset();
    $this->lease = makeLease(makeUnit($this->asset), makeTenant(), [
        'status' => 'active', 'has_percentage_rent' => true,
        'percentage_rent_calculation_type' => 'artificial',
        'percentage_rent_threshold' => 50000, 'percentage_rent_rate' => 5,
    ]);
    $this->decl = TenantSalesDeclaration::create([
        'lease_id' => $this->lease->id, 'period_start' => '2026-01-01', 'period_end' => '2026-01-31',
        'declared_sales' => 100000, 'calculated_percentage_rent' => 0, 'status' => 'submitted',
        'declared_at' => now(), 'declared_by_type' => $this->lease->tenant::class, 'declared_by_id' => $this->lease->tenant_id,
    ]);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

function salesActAs(string $role): void
{
    test()->actingAs(makeUser($role, [test()->asset->id]));
    Filament::setTenant(test()->asset);
}

it('refuses a read-only VIEWER locking a declaration (would bill an overage invoice), even dispatched directly', function () {
    salesActAs('viewer'); // holds tenant_sales.view but NOT tenant_sales.lock
    expect(TenantSalesDeclarationsTable::canLock($this->decl))->toBeFalse();

    Livewire::test(ListTenantSalesDeclarations::class)
        ->mountAction(TestAction::make('lock')->table($this->decl))
        ->callMountedAction();

    expect($this->decl->fresh()->status)->toBe('submitted')                  // never locked
        ->and(Invoice::where('lease_id', $this->lease->id)->count())->toBe(0); // no overage invoice billed
});

it('refuses a read-only OWNER disputing a declaration, even dispatched directly', function () {
    salesActAs('owner');
    expect(TenantSalesDeclarationsTable::canDispute($this->decl))->toBeFalse();

    Livewire::test(ListTenantSalesDeclarations::class)
        ->mountAction(TestAction::make('dispute')->table($this->decl))
        ->callMountedAction();

    expect($this->decl->fresh()->status)->toBe('submitted'); // not flipped to disputed
});

it('refuses a read-only VIEWER voiding a LOCKED declaration (would cancel its invoice), even dispatched directly', function () {
    // Lock it first as an authorized user so there is an overage invoice to protect.
    app(PercentageRentCalculationService::class)->lock($this->decl, makeUser('super_admin'), null);
    $invoice = Invoice::where('lease_id', $this->lease->id)->sole();
    expect($invoice->status)->toBe('issued');

    salesActAs('viewer');
    expect(TenantSalesDeclarationsTable::canVoid($this->decl->fresh()))->toBeFalse();

    Livewire::test(ListTenantSalesDeclarations::class)
        ->mountAction(TestAction::make('voidLocked')->table($this->decl->fresh()))
        ->callMountedAction();

    expect($this->decl->fresh()->status)->toBe('locked')      // still locked
        ->and($invoice->fresh()->status)->toBe('issued');     // invoice not cancelled
});
