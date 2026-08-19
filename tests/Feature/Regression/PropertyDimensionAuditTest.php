<?php

use App\Models\Department;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Support\PropertyIsolation;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * The last way a property-less money document can still reach the books.
 *
 * No panel screen can produce one since `PropertyField` pinned the pickers — a blank property is a
 * bare 403 from `assertAssetInScope()`. What that leaves is the two paths that run before anyone
 * looks at a screen: a CSV import, and a migration off the operator's previous system. A row from
 * either shows on EVERY mall's list (`portfolioRowsWhenNull: true`) and lands in no mall's owner
 * statement, and nothing about it looks wrong.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
});

it('passes when every money document names a property', function () {
    $asset = makeAsset(['code' => 'HW']);

    Expense::create([
        'asset_id' => $asset->id, 'expense_date' => now(), 'category' => 'maintenance',
        'paid_from' => 'cash', 'amount' => 500, 'vat_amount' => 0, 'total' => 500,
    ]);

    $this->artisan('atriom:audit-property-dimension')->assertExitCode(0);
});

it('fails and names a money document filed against no property', function () {
    $asset = makeAsset(['code' => 'HW']);

    // The control first: a correctly-filed expense must NOT be reported, or a command that named
    // every row would satisfy the assertion below for the wrong reason.
    Expense::create([
        'asset_id' => $asset->id, 'expense_date' => now(), 'category' => 'maintenance',
        'paid_from' => 'cash', 'amount' => 500, 'vat_amount' => 0, 'total' => 500, 'number' => 'EXP-FILED',
    ]);

    // The offender — the shape an import leaves behind.
    Expense::create([
        'asset_id' => null, 'expense_date' => now(), 'category' => 'maintenance',
        'paid_from' => 'cash', 'amount' => 750, 'vat_amount' => 0, 'total' => 750, 'number' => 'EXP-ORPHAN',
    ]);

    $this->artisan('atriom:audit-property-dimension')
        ->expectsOutputToContain('EXP-ORPHAN')
        ->assertExitCode(1);

    // Asserted on its own run: two expectsOutputToContain calls match against the same line, so a
    // pair of them in one chain cannot both be satisfied.
    $this->artisan('atriom:audit-property-dimension')
        ->doesntExpectOutputToContain('EXP-FILED')
        ->assertExitCode(1);
});

it('forgives the models whose blank property is a real answer', function () {
    // Department is the hybrid: a null asset_id is an operator-wide department every mall shares,
    // and DepartmentForm is registered in PropertyField::PORTFOLIO_LEVEL precisely for that.
    Department::create(['name' => 'Finance', 'asset_id' => null, 'is_active' => true]);

    $this->artisan('atriom:audit-property-dimension')->assertExitCode(0);

    // And the derivation is what forgives it, not an empty list waving everything through. If
    // `modelsWhoseBlankIsMeaningful()` resolved to nothing, this global department would have been
    // reported — so prove the same row FAILS once it is the kind of row nobody registered.
    JournalEntry::create([
        'entry_date' => now(), 'status' => 'draft', 'asset_id' => null,
        'description_en' => 'imported, property unknown',
    ]);

    $this->artisan('atriom:audit-property-dimension')->assertExitCode(1);
});

it('sweeps every model that may carry a null property', function () {
    // A sweep that visited nothing would pass every assertion above it. The register is derived
    // from `#[PropertyOwned(portfolioRowsWhenNull: true)]`, so a new hybrid model is covered the day
    // it is declared — and this notices if that ever resolves to an empty set.
    $hybrids = PropertyIsolation::hybridModels();

    expect($hybrids)->not->toBeEmpty()
        ->and($hybrids)->toContain(JournalEntry::class)
        ->and($hybrids)->toContain(Expense::class)
        ->and($hybrids)->toContain(Department::class);
});
