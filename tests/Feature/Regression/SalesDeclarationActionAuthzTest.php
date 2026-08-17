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
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The lock / dispute / voidLocked actions gated permission + status ONLY in visible(). Filament's
 * mountAction() was believed never to check isVisible() — it does, indirectly: a hidden action is
 * (incl. tenant_sales.view) — so the list renders and a crafted dispatch ran the action: a read-only
 * auditor or owner Jawad could LOCK (bill an overage invoice + post GL), DISPUTE, or VOID a locked
 * declaration. CORRECTED 2026-07-31: `isDisabled()` returns true for a hidden action, so the
 * mountAction tests below exercise visible() ONLY — deleting the abort_unless left them green.
 * The direct-call tests at the bottom reach the actual gate. See FilamentActionDispatchContractTest.
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

/**
 * Invoke a table action's own closure, bypassing the visibility/disabled short-circuit.
 *
 * mountAction() refuses DISABLED actions, and an action hidden by visible() is disabled
 * (CanBeDisabled::isDisabled → isHidden), so a mountAction test never reaches the abort_unless
 * inside action(). Action::call() evaluates the closure directly, which does.
 */
function callSalesAction(string $name, TenantSalesDeclaration $declaration): void
{
    $component = Livewire::test(ListTenantSalesDeclarations::class)->instance();

    $action = $component->getTable()->getAction($name);
    expect($action)->not->toBeNull("action [{$name}] not found on the declarations table");

    $action->record($declaration)->call();
}

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

/* ---- the abort_unless inside action(), reached directly -------------------- */

it('refuses a viewer locking a declaration when the action closure is reached directly', function () {
    // Locking bills an overage invoice, so this is the gate that matters. visible() cannot help
    // here — the closure is invoked directly.
    salesActAs('viewer');

    expect(fn () => callSalesAction('lock', $this->decl))
        ->toThrow(HttpException::class);

    expect($this->decl->fresh()->status)->not->toBe('locked');
});

it('refuses a viewer disputing a declaration when the action closure is reached directly', function () {
    salesActAs('viewer');

    expect(fn () => callSalesAction('dispute', $this->decl))
        ->toThrow(HttpException::class);

    expect($this->decl->fresh()->status)->not->toBe('disputed');
});

it('control: an authorised user CAN lock through the same path', function () {
    // Proves call() actually runs the closure — without it the refusals above prove nothing.
    // `leasing` is the role that holds tenant_sales.lock (RolesPermissionsSeeder), not accounting.
    salesActAs('leasing');

    callSalesAction('lock', $this->decl);

    expect($this->decl->fresh()->status)->toBe('locked');
});
