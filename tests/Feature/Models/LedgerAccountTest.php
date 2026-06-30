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

it('scopes to postable and active accounts', function () {
    LedgerAccount::create(['code' => '8101', 'name_en' => 'P', 'name_ar' => 'ر', 'type' => 'asset', 'is_postable' => true, 'is_active' => true]);
    LedgerAccount::create(['code' => '8102', 'name_en' => 'S', 'name_ar' => 'ت', 'type' => 'asset', 'is_postable' => false, 'is_active' => true]);
    LedgerAccount::create(['code' => '8103', 'name_en' => 'I', 'name_ar' => 'غ', 'type' => 'asset', 'is_postable' => true, 'is_active' => false]);

    expect(LedgerAccount::query()->postable()->count())->toBe(2);
    expect(LedgerAccount::query()->postable()->active()->count())->toBe(1);
});
