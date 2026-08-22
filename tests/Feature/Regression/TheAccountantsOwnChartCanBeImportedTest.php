<?php

use App\Filament\Imports\LedgerAccountImporter;
use App\Models\LedgerAccount;
use App\Support\CashFlowSection;

/**
 * EG-28's other half — the operator's own chart of accounts can be loaded (finding S-4).
 *
 * Atriom ships a chart so a box can post on day one, but the accountant has theirs, and until now
 * adopting it meant typing a few hundred accounts into a form. That is how a chart acquires the
 * typo that misfiles revenue for a year, and it is the one importer a first deploy actually needs
 * that did not exist.
 *
 * **The hazard this file mostly exists for is ORDER.** `LedgerAccount::resolveParentIdFromCode()`
 * looks BACKWARD for a parent that already exists — complete when parents precede children, which
 * is true of the seeder (it sorts by code) and false of a CSV in whatever order another system
 * exported it. Filament streams rows in file order and offers no after-import hook, so a file
 * listing `11101` before `111` used to leave the child parented to null: the rollup silently loses
 * a branch and nothing on screen says so.
 */
it('links the tree even when a child arrives before its parent', function () {
    // Deliberately backwards — the deepest account first.
    $child = LedgerAccount::create([
        'code' => '11101', 'name_en' => 'Cash on hand', 'name_ar' => 'نقدية بالخزينة',
        'type' => 'asset', 'is_postable' => true, 'is_active' => true,
    ]);

    expect($child->parent_id)->toBeNull();

    $mid = LedgerAccount::create([
        'code' => '111', 'name_en' => 'Cash & bank', 'name_ar' => 'النقدية والبنوك',
        'type' => 'asset', 'is_postable' => false, 'is_active' => true,
    ]);

    // Adopted on the parent's save, which is the reverse direction `saving` cannot do.
    expect($child->fresh()->parent_id)->toBe($mid->id);

    $top = LedgerAccount::create([
        'code' => '1', 'name_en' => 'Assets', 'name_ar' => 'الأصول',
        'type' => 'asset', 'is_postable' => false, 'is_active' => true,
    ]);

    // …and the grandparent takes the mid account WITHOUT stealing the grandchild from it.
    expect($mid->fresh()->parent_id)->toBe($top->id)
        ->and($child->fresh()->parent_id)->toBe($mid->id);
});

it('does not steal a grandchild from a closer parent', function () {
    // The ordering rule that makes adoption safe: claim a descendant only when we are CLOSER than
    // its current parent. Inserting a shallow account must not flatten a branch beneath it.
    $deep = LedgerAccount::create([
        'code' => '1110123', 'name_en' => 'Petty cash', 'name_ar' => 'نثرية',
        'type' => 'asset', 'is_postable' => true, 'is_active' => true,
    ]);
    $mid = LedgerAccount::create([
        'code' => '11101', 'name_en' => 'Cash on hand', 'name_ar' => 'نقدية بالخزينة',
        'type' => 'asset', 'is_postable' => false, 'is_active' => true,
    ]);

    expect($deep->fresh()->parent_id)->toBe($mid->id);

    LedgerAccount::create([
        'code' => '111', 'name_en' => 'Cash & bank', 'name_ar' => 'النقدية والبنوك',
        'type' => 'asset', 'is_postable' => false, 'is_active' => true,
    ]);

    // Still parented to 11101, not flattened onto 111.
    expect($deep->fresh()->parent_id)->toBe($mid->id);
});

it('imports a chart that is not ours, with its own cash-flow classification', function () {
    // The point of the pairing with EG-28: a chart arriving from another system is exactly when the
    // cash-flow section has to be STATED rather than inferred from how somebody numbered it.
    $account = LedgerAccount::create([
        'code' => '1900', 'name_en' => 'Plant & equipment', 'name_ar' => 'آلات ومعدات',
        'type' => 'asset', 'cash_flow_section' => CashFlowSection::INVESTING,
        'is_postable' => true, 'is_active' => true,
    ]);

    expect($account->cash_flow_section)->toBe(CashFlowSection::INVESTING)
        // …and normal_balance is still derived, never taken from the file.
        ->and($account->normal_balance)->toBe('debit');
});

it('keeps the code as identity, so a second pass corrects rather than duplicates', function () {
    LedgerAccount::create([
        'code' => '41001', 'name_en' => 'Rent income', 'name_ar' => 'إيراد إيجار',
        'type' => 'revenue', 'is_postable' => true, 'is_active' => true,
    ]);

    $again = LedgerAccount::firstOrNew(['code' => '41001']);
    $again->fill(['name_en' => 'Rental income', 'name_ar' => 'إيراد الإيجار', 'type' => 'revenue']);
    $again->save();

    expect(LedgerAccount::where('code', '41001')->count())->toBe(1)
        ->and($again->fresh()->name_en)->toBe('Rental income');
});

it('declares the columns a chart file needs, and none the system derives', function () {
    $columns = collect(LedgerAccountImporter::getColumns())->map(fn ($c) => $c->getName())->all();

    expect($columns)->toContain('code', 'name_en', 'name_ar', 'type', 'cash_flow_section')
        // Both are derived in `LedgerAccount::saving` — a column for either would be a second,
        // conflicting truth, and the model's own docblock says normal_balance is never set by hand.
        ->and($columns)->not->toContain('parent_id')
        ->and($columns)->not->toContain('normal_balance');
});
