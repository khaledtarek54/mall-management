<?php

use App\Filament\Admin\Resources\AccountMappings\Pages\ListAccountMappings;
use App\Models\AccountMapping;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

/**
 * **The posting map names its accounts in the READER's language.**
 *
 * `/admin/account-mappings` is where an accountant decides which chart account each semantic role
 * posts to — the screen that will carry the whole handover when Jawad's real Egyptian chart lands.
 * Its account column read `account.name_ar` regardless of who was looking, so an operator working
 * the panel in English was choosing between accounts named in Arabic.
 *
 * This screen's own PICKER had already been corrected for exactly this, with the reason written
 * into `AccountMappingForm`; the TABLE beside it was not. That is the shape this repo keeps
 * recording — one half of a screen fixed and the other half left — and it is why both directions
 * are asserted here rather than only the English one: a fix that hardcoded `name_en` would satisfy
 * an English-only test and re-break the Arabic panel.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);

    $this->asset = makeAsset();
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));

    $this->mapping = AccountMapping::query()
        ->where('key', 'accounts_receivable')
        ->whereNull('asset_id')
        ->firstOrFail();
});

afterEach(fn () => app()->setLocale(config('app.locale')));

it('names the account in English for an English reader', function () {
    $account = $this->mapping->account;

    // The control that makes both assertions mean anything: if the shipped chart ever named an
    // account identically in both languages, this file would pass on the bug.
    expect($account->name_en)->not->toBe($account->name_ar);

    app()->setLocale('en');

    asTenant($this->asset, function () use ($account) {
        Livewire::test(ListAccountMappings::class)
            ->assertTableColumnStateSet('account.name_ar', $account->name_en, $this->mapping);
    });
});

it('names the same account in Arabic for an Arabic reader', function () {
    $account = $this->mapping->account;

    app()->setLocale('ar');

    asTenant($this->asset, function () use ($account) {
        Livewire::test(ListAccountMappings::class)
            ->assertTableColumnStateSet('account.name_ar', $account->name_ar, $this->mapping);
    });
});
