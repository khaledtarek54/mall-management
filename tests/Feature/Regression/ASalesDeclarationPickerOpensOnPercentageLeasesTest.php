<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\TenantSalesDeclarations\Pages\CreateTenantSalesDeclaration;
use App\Models\Lease;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Livewire\Livewire;

/**
 * THE LEASE PICKER OPENS ON THE LEASES A DECLARATION USUALLY BELONGS TO, AND STILL REACHES THE REST.
 *
 * A declaration is normally filed against a lease carrying a percentage-rent clause, and the
 * dropdown offered every active lease in the mall with nothing to say which those were. It now
 * opens on them and carries the clause on the option itself — the rate, the breakpoint, and whether
 * the breakpoint is monthly or annual, which are the three figures that decide the charge and which
 * the person keying the turnover otherwise has to go and look up.
 *
 * SUGGESTED, NOT FILTERED. A mall collects turnover from tenants who owe no percentage rent — that
 * is what the Sales analytics screen is for, and this database already holds such a declaration.
 * A hard filter would refuse a legitimate record, and worse: Filament resolves a Select's value by
 * LABELLING it through the same query, so an existing declaration on a non-percentage lease would
 * fail to open for editing at all.
 *
 * The natural breakpoint is DERIVED and the badge says so in figures: rent ÷ rate. Nothing in the
 * lease record holds it, so an operator reading a "5.5%" clause has no way to know the line is at
 * 909,091 until the system tells them.
 */
beforeEach(function (): void {
    $this->seed(RolesPermissionsSeeder::class);

    $user = User::factory()->create();
    $user->assignRole('super_admin');
    $this->actingAs($user);

    $this->onPercentage = Lease::factory()->create([
        'status' => 'active',
        'commencement_date' => CarbonImmutable::parse('2026-01-01'),
        'expiry_date' => CarbonImmutable::parse('2028-12-31'),
        'base_rent_monthly' => 50_000,
        'has_percentage_rent' => true,
        'percentage_rent_rate' => 5.5,
        'percentage_rent_calculation_type' => 'natural_breakpoint',
        'percentage_rent_frequency' => 'monthly',
        'escalation_type' => 'none',
    ]);

    $asset = $this->onPercentage->unit->asset;
    Filament::setTenant($asset);

    $this->plain = Lease::factory()->create([
        'status' => 'active',
        'unit_id' => Unit::factory()->create(['asset_id' => $asset->id])->id,
        'commencement_date' => CarbonImmutable::parse('2026-01-01'),
        'expiry_date' => CarbonImmutable::parse('2028-12-31'),
        'has_percentage_rent' => false,
        'escalation_type' => 'none',
    ]);
});

function leasePicker(): Select
{
    return Livewire::test(CreateTenantSalesDeclaration::class)
        ->instance()
        ->getSchema('form')
        ->getFlatFields()['lease_id'];
}

it('opens on the leases that carry a percentage-rent clause', function (): void {
    $options = leasePicker()->getOptions();

    expect(array_keys($options))->toContain($this->onPercentage->getKey())
        ->and(array_keys($options))->not->toContain($this->plain->getKey());
});

it('carries the clause on the option, with a derived breakpoint', function (): void {
    $label = strip_tags((string) leasePicker()->getOptions()[$this->onPercentage->getKey()]);

    // rent ÷ rate = 50,000 ÷ 5.5% = 909,090.91 — nothing on the lease record holds this figure,
    // so an operator reading "5.5%" cannot know where the line is until the badge says so.
    expect($label)->toContain('5.5%')
        ->and($label)->toContain('909,091');
});

it('still LABELS a lease with no clause, so an existing declaration opens', function (): void {
    // The half a hard filter would break. Filament resolves a stored value by asking the picker for
    // its label; a picker that cannot label it refuses the record with `Rule::in([])`.
    // The label is resolved from the component's STATE, not passed as an argument — Filament's
    // `getOptionLabel()` takes a `$withDefault` flag, which is the trap this line first fell into.
    $picker = leasePicker();
    $picker->state($this->plain->getKey());

    expect($picker->getOptionLabel())->not->toBeNull();
});

it('reaches a lease with no clause by searching', function (): void {
    // `->suggest()` narrows what is SHOWN. Search runs on the modifier alone, so the rest of the
    // register is still one search away — the difference between a suggestion and a filter.
    $results = leasePicker()->getSearchResults($this->plain->reference);

    expect(array_keys($results))->toContain($this->plain->getKey());
});
