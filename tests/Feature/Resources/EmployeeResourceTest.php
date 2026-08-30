<?php

use App\Filament\Admin\Resources\Employees\EmployeeResource;
use App\Filament\Admin\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Admin\Resources\Employees\Pages\EditEmployee;
use App\Models\Employee;
use App\Settings\ModulesSettings;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
});

function makeEmployee(int $assetId, array $attrs = []): Employee
{
    return Employee::create(array_merge([
        'asset_id' => $assetId,
        'code' => 'E-'.uniqid(),
        'name' => 'Ahmed Hassan',
        'hire_date' => '2026-01-01',
        'base_salary' => 6000,
        'payment_method' => 'bank',
    ], $attrs));
}

/* ---- RBAC ---------------------------------------------------------------- */

it('gates the employee resource on employees permissions', function () {
    // hr owns employees.* — can view + create; leasing has none.
    $this->actingAs(makeUser('hr'));
    expect(EmployeeResource::canViewAny())->toBeTrue();
    expect(EmployeeResource::canCreate())->toBeTrue();

    $this->actingAs(makeUser('leasing'));
    expect(EmployeeResource::canViewAny())->toBeFalse();

    // accounting + viewer see, but cannot create.
    $this->actingAs(makeUser('accounting'));
    expect(EmployeeResource::canViewAny())->toBeTrue();
    expect(EmployeeResource::canCreate())->toBeFalse();

    $this->actingAs(makeUser('viewer'));
    expect(EmployeeResource::canViewAny())->toBeTrue();
    expect(EmployeeResource::canCreate())->toBeFalse();
});

it('hides the employee resource when the module is disabled', function () {
    $this->actingAs(makeUser('super_admin'));
    expect(EmployeeResource::canViewAny())->toBeTrue();

    $settings = app(ModulesSettings::class);
    $settings->employees = false;
    $settings->save();

    expect(EmployeeResource::canViewAny())->toBeFalse();
});

/* ---- Property scoping ---------------------------------------------------- */

it('scopes employees to the current property', function () {
    $assetA = makeAsset(['code' => 'EMA']);
    $assetB = makeAsset(['code' => 'EMB']);
    makeEmployee($assetA->id, ['code' => 'A-1']);
    makeEmployee($assetB->id, ['code' => 'B-1']);

    $this->actingAs(makeUser('super_admin'));

    asTenant($assetA, function () {
        expect(scopedResourceQuery(EmployeeResource::class)->pluck('code')->all())
            ->toContain('A-1')->not->toContain('B-1');
    });
});

/* ---- Uniqueness ---------------------------------------------------------- */

it('enforces a unique staff code within a property', function () {
    $asset = makeAsset();
    makeEmployee($asset->id, ['code' => 'DUP']);

    $this->actingAs(makeUser('hr', [$asset->id]));

    asTenant($asset, function () {
        Livewire::test(CreateEmployee::class)
            ->fillForm([
                'code' => 'DUP', // collides within the same property
                'name' => 'Second',
                'hire_date' => now()->toDateString(),
                'base_salary' => 5000,
                'payment_method' => 'bank',
            ])
            ->call('create')
            ->assertHasFormErrors(['code']);
    });
});

/* ---- Terminate action ---------------------------------------------------- */

it('terminates an employee via the action', function () {
    $asset = makeAsset();
    $employee = makeEmployee($asset->id);

    $this->actingAs(makeUser('hr', [$asset->id]));

    asTenant($asset, function () use ($employee) {
        // The act moved to the employee's own page on 2026-08-30 — the list FINDS, the record ACTS.
        Livewire::test(EditEmployee::class, ['record' => $employee->getRouteKey()])
            ->callAction('terminate', data: ['terminated_on' => now()->toDateString()])
            ->assertHasNoActionErrors();
    });

    expect($employee->fresh()->status)->toBe('terminated');
    expect($employee->fresh()->terminated_on)->not->toBeNull();
});

it('forbids terminating for a read-only role', function () {
    $asset = makeAsset();
    $employee = makeEmployee($asset->id);

    $this->actingAs(makeUser('viewer', [$asset->id]));

    // The act lives on the employee's own page now, and a viewer holds employees.view without
    // employees.edit — so the refusal lands one layer EARLIER than it used to: the page itself is
    // refused, and there is no surface on which the act could be dispatched at all.
    asTenant($asset, function () use ($employee) {
        Livewire::test(EditEmployee::class, ['record' => $employee->getRouteKey()])
            ->assertForbidden();
    });

    expect($employee->fresh()->status)->toBe('active');
});

/* ---- asset_id scope guard ------------------------------------------------ */

it('rejects an out-of-scope asset_id and allows an in-scope one', function () {
    $assetA = makeAsset(['code' => 'ESA']);
    $assetB = makeAsset(['code' => 'ESB']);

    $this->actingAs(makeUser('hr', [$assetA->id]));

    EmployeeResource::assertAssetInScope($assetA->id);
    expect(true)->toBeTrue();

    expect(fn () => EmployeeResource::assertAssetInScope($assetB->id))
        ->toThrow(HttpException::class);
});
