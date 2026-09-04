<?php

use App\Filament\Admin\Resources\Expenses\ExpenseResource;
use App\Filament\Admin\Resources\Violations\ViolationResource;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Violation;
use App\Models\ViolationCategory;

/**
 * GLOBAL SEARCH PRINTS THE CATALOGUE'S LABEL, NOT THE STORED CODE (SW-129).
 *
 * `getGlobalSearchResultDetails()` is the context under a search hit — the thing that tells an
 * operator whether the row in front of them is the one they were hunting for. Two of the 23
 * overrides in the panel printed a live `IsCodeCatalogue` code RAW while the list beside them
 * formatted the identical column through `labelFor()`: violations and expenses.
 *
 * A raw code is not merely untidy here. Both columns are catalogue-widened, so a rule or a cost
 * type the operator ADDED has no lang key of any kind — the search bar would print
 * `unauthorized_works` beside a register that says "Unauthorised works" — and a rename in the house
 * rules or the chart of cost types would reach every screen except this one.
 *
 * Each case asserts a RENAME travels, not merely that the string looks nicer: a test that only
 * compared against the shipped English label would pass against `__('admin.violations.categories.…')`
 * too, which is not what the fix does.
 */
it('labels a violation search hit from the house rules, and follows a rename', function () {
    $violation = Violation::create([
        'asset_id' => makeAsset()->id,
        'tenant_id' => makeTenant()->id,
        'category' => 'safety',
        'description' => 'Blocked fire exit on the service corridor.',
        'fine_amount' => 1000,
        'violation_date' => '2026-03-15',
    ]);

    // The operator revises the rule book — the whole reason the catalogue exists.
    ViolationCategory::create([
        'code' => 'safety',
        'name_en' => 'Fire safety breach',
        'name_ar' => 'مخالفة السلامة من الحريق',
    ]);

    $details = array_values(ViolationResource::getGlobalSearchResultDetails($violation->fresh()));

    expect($details)->toContain('Fire safety breach')
        ->and($details)->not->toContain('safety');
});

it('labels an expense search hit from the cost-type catalogue, and follows a rename', function () {
    $asset = makeAsset();

    $expense = Expense::create([
        'asset_id' => $asset->id,
        'category' => 'utilities',
        'amount' => 1000,
        'vat_amount' => 140,
        'total' => 1140,
        'paid_from' => 'cash',
        'expense_date' => '2026-03-15',
        'status' => 'recorded',
    ]);

    ExpenseCategory::create([
        'code' => 'utilities',
        'name_en' => 'Electricity and water',
        'name_ar' => 'الكهرباء والمياه',
    ]);

    $details = array_values(ExpenseResource::getGlobalSearchResultDetails($expense->fresh()));

    expect($details)->toContain('Electricity and water')
        ->and($details)->not->toContain('utilities');
});

it('still says something when the record carries no category — the control', function () {
    // `labelFor(null)` answers an em dash rather than throwing or rendering an empty cell that
    // reads as missing data. Asserted so the fix cannot be "read the catalogue and blank it".
    expect(ViolationCategory::labelFor(null))->toBe('—')
        ->and(ExpenseCategory::labelFor(null))->toBe('—');
});
