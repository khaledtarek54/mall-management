<?php

use App\Models\LedgerAccount;

it('derives normal_balance from type on save', function () {
    $asset = LedgerAccount::create(['code' => '90001', 'name_en' => 'A', 'name_ar' => 'أ', 'type' => 'asset', 'is_postable' => true]);
    $expense = LedgerAccount::create(['code' => '90002', 'name_en' => 'B', 'name_ar' => 'ب', 'type' => 'expense', 'is_postable' => true]);
    $liability = LedgerAccount::create(['code' => '90003', 'name_en' => 'C', 'name_ar' => 'ج', 'type' => 'liability', 'is_postable' => true]);
    $revenue = LedgerAccount::create(['code' => '90004', 'name_en' => 'D', 'name_ar' => 'د', 'type' => 'revenue', 'is_postable' => true]);
    $equity = LedgerAccount::create(['code' => '90005', 'name_en' => 'E', 'name_ar' => 'هـ', 'type' => 'equity', 'is_postable' => true]);

    expect($asset->normal_balance)->toBe('debit');
    expect($expense->normal_balance)->toBe('debit');
    expect($liability->normal_balance)->toBe('credit');
    expect($revenue->normal_balance)->toBe('credit');
    expect($equity->normal_balance)->toBe('credit');
});

it('maps types to their normal balance side', function () {
    expect(LedgerAccount::normalBalanceFor('asset'))->toBe('debit');
    expect(LedgerAccount::normalBalanceFor('expense'))->toBe('debit');
    expect(LedgerAccount::normalBalanceFor('liability'))->toBe('credit');
    expect(LedgerAccount::normalBalanceFor('equity'))->toBe('credit');
    expect(LedgerAccount::normalBalanceFor('revenue'))->toBe('credit');
});

it('builds a parent / child tree', function () {
    $parent = LedgerAccount::create(['code' => '8', 'name_en' => 'Parent', 'name_ar' => 'أب', 'type' => 'asset', 'is_postable' => false]);
    $child = LedgerAccount::create(['code' => '8001', 'name_en' => 'Child', 'name_ar' => 'ابن', 'type' => 'asset', 'is_postable' => true, 'parent_id' => $parent->id]);

    expect($child->parent->id)->toBe($parent->id);
    expect($parent->children->pluck('id')->all())->toContain($child->id);
});

it('auto-derives parent_id from the code (deepest existing ancestor)', function () {
    LedgerAccount::create(['code' => '4', 'name_en' => 'Revenue', 'name_ar' => 'إيرادات', 'type' => 'revenue', 'is_postable' => false]);
    LedgerAccount::create(['code' => '41', 'name_en' => 'Operating', 'name_ar' => 'تشغيل', 'type' => 'revenue', 'is_postable' => false]);
    $group = LedgerAccount::create(['code' => '411', 'name_en' => 'Property', 'name_ar' => 'عقار', 'type' => 'revenue', 'is_postable' => false]);

    $leaf = LedgerAccount::create(['code' => '41101001', 'name_en' => 'Rent', 'name_ar' => 'إيجار', 'type' => 'revenue', 'is_postable' => true]);

    expect($leaf->parent_id)->toBe($group->id); // deepest existing prefix (411), not 4/41
});

it('leaves a single-character (top-level) code with no parent', function () {
    $top = LedgerAccount::create(['code' => '1', 'name_en' => 'Assets', 'name_ar' => 'أصول', 'type' => 'asset', 'is_postable' => false]);
    expect($top->parent_id)->toBeNull();
});

it('re-derives the parent when the code changes', function () {
    LedgerAccount::create(['code' => '5', 'name_en' => 'Expenses', 'name_ar' => 'مصروفات', 'type' => 'expense', 'is_postable' => false]);
    $sub = LedgerAccount::create(['code' => '51', 'name_en' => 'Operating', 'name_ar' => 'تشغيل', 'type' => 'expense', 'is_postable' => false]);
    $leaf = LedgerAccount::create(['code' => '5999', 'name_en' => 'X', 'name_ar' => 'س', 'type' => 'expense', 'is_postable' => true]);
    expect($leaf->parent_id)->toBe(LedgerAccount::where('code', '5')->value('id'));

    $leaf->update(['code' => '5101']);
    expect($leaf->fresh()->parent_id)->toBe($sub->id);
});

it('rejects a defined-range code whose leading digit contradicts the type', function () {
    // Leading 4 = revenue range, but typed as an expense.
    expect(fn () => LedgerAccount::create([
        'code' => '41999999', 'name_en' => 'Bad', 'name_ar' => 'خطأ', 'type' => 'expense', 'is_postable' => true,
    ]))->toThrow(InvalidArgumentException::class);
});

it('allows a custom-range code (leading digit 6-9) with any type', function () {
    $a = LedgerAccount::create(['code' => '80001', 'name_en' => 'Custom', 'name_ar' => 'مخصص', 'type' => 'liability', 'is_postable' => true]);
    expect($a->exists)->toBeTrue();
    expect(LedgerAccount::expectedTypeForCode('80001'))->toBeNull();
});

it('scopes to postable and active accounts', function () {
    LedgerAccount::create(['code' => '8101', 'name_en' => 'P', 'name_ar' => 'ر', 'type' => 'asset', 'is_postable' => true, 'is_active' => true]);
    LedgerAccount::create(['code' => '8102', 'name_en' => 'S', 'name_ar' => 'ت', 'type' => 'asset', 'is_postable' => false, 'is_active' => true]);
    LedgerAccount::create(['code' => '8103', 'name_en' => 'I', 'name_ar' => 'غ', 'type' => 'asset', 'is_postable' => true, 'is_active' => false]);

    expect(LedgerAccount::query()->postable()->count())->toBe(2);
    expect(LedgerAccount::query()->postable()->active()->count())->toBe(1);
});
