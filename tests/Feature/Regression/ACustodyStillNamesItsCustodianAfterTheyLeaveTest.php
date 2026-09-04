<?php

use App\Filament\Admin\Resources\Custodies\Pages\CreateCustody;
use App\Filament\Admin\Resources\Custodies\Pages\EditCustody;
use App\Models\Employee;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Livewire\Livewire;

/**
 * **A custody still names its custodian after that person leaves — and the record still saves.**
 *
 * `CustodyForm::employeeOptions()` offered `Employee::query()->active()` of the visible properties
 * and nothing else, so the moment the person holding the custody was terminated the edit page could
 * no longer LABEL the value it was showing.
 *
 * That is not a cosmetic blank, which is what the sweep row claimed. Filament derives an `in:` rule
 * from a Select's options and validates the CURRENT value against it — measured at HEAD against
 * filament/filament v4.11.8, `Select::getInValidationRuleValues()`
 * (vendor/filament/forms/src/Components/Select.php:1788) returns `[]` as soon as
 * `getOptionLabel(withDefault: false)` comes back blank, giving `Rule::in([])`, which nothing
 * satisfies — and a DISABLED field is still VALIDATED, because
 * `HasState::isNeitherDehydratedNorValidated()` (HasState.php:800) short-circuits on
 * `isValidatedWhenNotDehydrated`, which defaults TRUE (HasState.php:59). So the whole record went
 * read-only: the purpose and the reference too, which `Custody::saving()` deliberately leaves
 * editable so *"an operator must be able to record what it turned out to be for"*. The lockout
 * lands the day the custodian leaves, which is precisely when an outstanding custody is chased.
 *
 * Two reachable states, one mechanism: TERMINATED, and an employee whose `asset_id` has since moved
 * to another mall while the custody keeps the property it was granted under. A soft-deleted
 * employee is not a third — `Employee` is `#[DeletableWhenUnused(blockedBy: [..., 'custodies'])]`.
 *
 * The fourth case is the tooth that keeps the fix narrow: CREATE must NOT start offering people who
 * have left, and it is paired with a control that must be offered, because a picker returning
 * nothing would satisfy the refusal on its own.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    $this->actingAs(makeUser('super_admin'));

    $this->mall = makeAsset(['code' => 'CUS9']);
    Filament::setTenant($this->mall);

    $this->custodian = Employee::create([
        'asset_id' => $this->mall->id, 'code' => 'E-CUS-9', 'name' => 'Karim Nabil',
        'hire_date' => '2026-01-01', 'status' => 'active',
        'base_salary' => 7000, 'payment_method' => 'bank',
    ]);

    $this->custody = $this->custodian->custodies()->create([
        'asset_id' => $this->mall->id,
        'amount' => 5000,
        'custody_date' => now()->toDateString(),
        'paid_from' => 'cash',
        'purpose' => 'Site petty cash',
    ]);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('offers the custodian and saves while they are still on the payroll', function () {
    // The control. Without it every assertion below would pass just as happily on a picker that
    // offers nothing at all and an edit page nobody can open.
    Livewire::test(EditCustody::class, ['record' => $this->custody->getRouteKey()])
        ->assertSchemaComponentExists(
            'employee_id',
            'form',
            fn (Select $field) => array_key_exists($this->custodian->id, $field->getOptions()),
        )
        ->fillForm(['purpose' => 'Site petty cash, revised'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->custody->fresh()->purpose)->toBe('Site petty cash, revised');
});

it('still names the custodian, and still saves, after they have left the payroll', function () {
    $this->custodian->update(['status' => 'terminated', 'terminated_on' => now()->toDateString()]);

    Livewire::test(EditCustody::class, ['record' => $this->custody->getRouteKey()])
        ->assertSchemaComponentExists(
            'employee_id',
            'form',
            fn (Select $field) => array_key_exists($this->custodian->id, $field->getOptions()),
        )
        ->fillForm(['purpose' => 'Returned in full on exit'])
        ->call('save')
        ->assertHasNoFormErrors();

    // The custodian is untouched: the field is disabled, so it is never dehydrated and
    // `Custody::saving()`'s "the custodian is fixed from the moment of the grant" refusal is not
    // provoked by this fix.
    expect($this->custody->fresh()->purpose)->toBe('Returned in full on exit')
        ->and($this->custody->fresh()->employee_id)->toBe($this->custodian->id);
});

it('still names a custodian whose employment has since moved to another mall', function () {
    // The custody keeps the property it was granted under; the person does not. The scope clause in
    // employeeOptions() drops them exactly as `->active()` does, so one mechanism has to cover both.
    $this->custodian->update(['asset_id' => makeAsset(['code' => 'CUS8'])->id]);

    Livewire::test(EditCustody::class, ['record' => $this->custody->getRouteKey()])
        ->assertSchemaComponentExists(
            'employee_id',
            'form',
            fn (Select $field) => array_key_exists($this->custodian->id, $field->getOptions()),
        )
        ->fillForm(['purpose' => 'Chased with the other mall'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->custody->fresh()->purpose)->toBe('Chased with the other mall');
});

it('does not offer somebody who has left when a NEW custody is granted', function () {
    $this->custodian->update(['status' => 'terminated']);

    $stillHere = Employee::create([
        'asset_id' => $this->mall->id, 'code' => 'E-CUS-8', 'name' => 'Nourhan Adel',
        'hire_date' => '2026-02-01', 'status' => 'active',
        'base_salary' => 6000, 'payment_method' => 'bank',
    ]);

    Livewire::test(CreateCustody::class)
        ->assertSchemaComponentExists(
            'employee_id',
            'form',
            // Both halves in one closure: the refusal AND the control, because a picker that
            // offered nobody would satisfy the refusal alone.
            fn (Select $field) => array_key_exists($stillHere->id, $field->getOptions())
                && ! array_key_exists($this->custodian->id, $field->getOptions()),
        );
});
