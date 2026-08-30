<?php

use App\Filament\Admin\Actions\SalesDeclarationActions;
use App\Filament\Admin\Resources\TenantSalesDeclarations\Pages\ListTenantSalesDeclarations;
use App\Models\Invoice;
use App\Models\TenantSalesDeclaration;
use App\Services\PercentageRentCalculationService;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The lock / dispute / voidLocked acts gated permission + status ONLY in `visible()`. Every role
 * that can reach the screen holds `tenant_sales.view`, so a crafted dispatch ran the action: a
 * read-only auditor or owner Jawad could LOCK (billing an overage invoice and posting to the GL),
 * DISPUTE, or VOID a locked declaration.
 *
 * CORRECTED 2026-07-31: `isDisabled()` returns true for a hidden action, so a `mountAction` test
 * exercises `visible()` ONLY — deleting the `abort_unless` left those green. The direct-call tests
 * below reach the actual gate. See `FilamentActionDispatchContractTest`.
 *
 * UPDATED 2026-08-30: the gate is asserted against `SalesDeclarationActions` — the single
 * definition both the list and the record page compose — rather than against whichever screen
 * happens to render it. Three tests here had been calling `TenantSalesDeclarationsTable::canLock()`
 * and friends, which moved to that class when the acts were extracted and were never updated: they
 * had been erroring, not passing, since the extraction.
 *
 * The four acts deliberately STAY on the list row. `leasing` is the role that holds
 * `tenant_sales.lock`, and it does NOT hold `tenant_sales.edit` — so it is refused the
 * declaration's Edit page with a 403, and an act that lived only there could not be performed by
 * the role that owns it. A LOCKED declaration is un-editable for everyone besides, so `voidLocked`
 * could never be reached from that page at all. See App\Support\RowActionPolicy.
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

/** The act as the registry defines it — the one definition every surface composes. */
function salesAct(string $name): Action
{
    $action = collect(SalesDeclarationActions::all())->first(fn (Action $a) => $a->getName() === $name);

    expect($action)->not->toBeNull("act [{$name}] is not defined in SalesDeclarationActions::all()");

    return $action;
}

/**
 * Invoke an act's own closure, bypassing the visibility/disabled short-circuit.
 *
 * `mountAction()` refuses DISABLED actions, and an action hidden by `visible()` is disabled
 * (`CanBeDisabled::isDisabled` → `isHidden`), so a mountAction test never reaches the
 * `abort_unless` inside `action()`. `Action::call()` evaluates the closure directly, which does.
 */
function callSalesAction(string $name, TenantSalesDeclaration $declaration): void
{
    salesAct($name)->record($declaration)->call();
}

/** The list, which is where the four acts render — see the note above on why they stay there. */
function declarationPage(TenantSalesDeclaration $declaration)
{
    return Livewire::test(ListTenantSalesDeclarations::class);
}

function salesActAs(string $role): void
{
    test()->actingAs(makeUser($role, [test()->asset->id]));
    Filament::setTenant(test()->asset);
}

/* ---- dispatched at the list, which is where they render --------------------- */

it('refuses a read-only VIEWER locking a declaration (would bill an overage invoice), even dispatched directly', function () {
    salesActAs('viewer'); // holds tenant_sales.view but NOT tenant_sales.lock
    expect(SalesDeclarationActions::canLock($this->decl))->toBeFalse();

    declarationPage($this->decl)
        ->mountAction(TestAction::make('lock')->table($this->decl))
        ->callMountedAction();

    expect($this->decl->fresh()->status)->toBe('submitted')                  // never locked
        ->and(Invoice::where('lease_id', $this->lease->id)->count())->toBe(0); // no overage invoice billed
});

it('refuses a read-only OWNER disputing a declaration, even dispatched directly', function () {
    salesActAs('owner');
    expect(SalesDeclarationActions::canDispute($this->decl))->toBeFalse();

    declarationPage($this->decl)
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
    expect(SalesDeclarationActions::canVoid($this->decl->fresh()))->toBeFalse();

    declarationPage($this->decl->fresh())
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
