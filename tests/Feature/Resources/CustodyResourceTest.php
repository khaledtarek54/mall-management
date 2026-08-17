<?php

use App\Filament\Admin\RelationManagers\CustodyTransactionsRelationManager;
use App\Filament\Admin\Resources\Custodies\CustodyResource;
use App\Filament\Admin\Resources\Custodies\Pages\EditCustody;
use App\Models\Custody;
use App\Models\CustodyTransaction;
use App\Models\Employee;
use App\Settings\ModulesSettings;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
});

function custodyFor(int $assetId, array $attrs = []): Custody
{
    $employee = Employee::create([
        'asset_id' => $assetId, 'code' => 'E-'.uniqid(), 'name' => 'Karim Nabil',
        'hire_date' => '2026-01-01', 'base_salary' => 7000, 'payment_method' => 'bank',
    ]);

    return $employee->custodies()->create(array_merge([
        'asset_id' => $assetId, 'amount' => 5000, 'custody_date' => now()->toDateString(), 'paid_from' => 'cash',
    ], $attrs));
}

function custodyRM(Custody $custody)
{
    return Livewire::test(CustodyTransactionsRelationManager::class, [
        'ownerRecord' => $custody,
        'pageClass' => EditCustody::class,
    ]);
}

/* ---- RBAC + module ------------------------------------------------------- */

it('gates the custody resource on custodies permissions', function () {
    // accounting owns custodies.* — view + create; leasing none.
    $this->actingAs(makeUser('accounting'));
    expect(CustodyResource::canViewAny())->toBeTrue();
    expect(CustodyResource::canCreate())->toBeTrue();

    $this->actingAs(makeUser('leasing'));
    expect(CustodyResource::canViewAny())->toBeFalse();

    // viewer sees, cannot create.
    $this->actingAs(makeUser('viewer'));
    expect(CustodyResource::canViewAny())->toBeTrue();
    expect(CustodyResource::canCreate())->toBeFalse();
});

it('hides the custody resource when the module is disabled', function () {
    $this->actingAs(makeUser('super_admin'));
    expect(CustodyResource::canViewAny())->toBeTrue();

    $settings = app(ModulesSettings::class);
    $settings->custodies = false;
    $settings->save();

    expect(CustodyResource::canViewAny())->toBeFalse();
});

it('scopes custodies to the current property', function () {
    $assetA = makeAsset(['code' => 'CUA']);
    $assetB = makeAsset(['code' => 'CUB']);
    custodyFor($assetA->id, ['reference' => 'CU-A']);
    custodyFor($assetB->id, ['reference' => 'CU-B']);

    $this->actingAs(makeUser('super_admin'));

    asTenant($assetA, function () {
        expect(scopedResourceQuery(CustodyResource::class)->pluck('reference')->all())
            ->toContain('CU-A')->not->toContain('CU-B');
    });
});

/* ---- Settlement actions -------------------------------------------------- */

it('records an expense settlement and reduces outstanding (accounting)', function () {
    $asset = makeAsset();
    $custody = custodyFor($asset->id, ['amount' => 5000]);
    $this->actingAs(makeUser('accounting', [$asset->id]));

    custodyRM($custody)
        ->callTableAction('record_expense', data: [
            'amount' => 1200, 'category' => 'maintenance', 'transaction_date' => now()->toDateString(),
        ])
        ->assertHasNoTableActionErrors();

    expect(CustodyTransaction::where('custody_id', $custody->id)->where('type', 'expense')->count())->toBe(1);
    expect($custody->fresh()->outstanding())->toBe(3800.0);
});

it('records a cash return', function () {
    $asset = makeAsset();
    $custody = custodyFor($asset->id, ['amount' => 5000]);
    $this->actingAs(makeUser('accounting', [$asset->id]));

    custodyRM($custody)
        ->callTableAction('record_return', data: [
            'amount' => 800, 'method' => 'cash', 'transaction_date' => now()->toDateString(),
        ])
        ->assertHasNoTableActionErrors();

    expect(CustodyTransaction::where('custody_id', $custody->id)->where('type', 'return')->count())->toBe(1);
    expect($custody->fresh()->outstanding())->toBe(4200.0);
});

it('rejects settling more than the outstanding (maxValue guard)', function () {
    $asset = makeAsset();
    $custody = custodyFor($asset->id, ['amount' => 5000]);
    $this->actingAs(makeUser('accounting', [$asset->id]));

    custodyRM($custody)
        ->callTableAction('record_expense', data: [
            'amount' => 9000, 'category' => 'other', 'transaction_date' => now()->toDateString(),
        ])
        ->assertHasTableActionErrors(['amount']);

    expect(CustodyTransaction::where('custody_id', $custody->id)->count())->toBe(0);
});

it('hides the settle actions from a role without custodies.settle', function () {
    $asset = makeAsset();
    $custody = custodyFor($asset->id);
    // viewer has custodies.view but not custodies.settle.
    $this->actingAs(makeUser('viewer', [$asset->id]));

    custodyRM($custody)
        ->assertTableActionHidden('record_expense')
        ->assertTableActionHidden('record_return');
});
