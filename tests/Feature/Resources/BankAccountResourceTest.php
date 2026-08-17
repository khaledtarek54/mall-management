<?php

use App\Filament\Admin\Resources\BankAccounts\BankAccountResource;
use App\Filament\Admin\Resources\BankAccounts\Pages\ListBankAccounts;
use App\Models\BankAccount;
use App\Models\JournalEntry;
use App\Services\Accounting\LedgerPoster;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The bank-account register — slice 1 of bank reconciliation.
 *
 * `bank`/`cash` are posting ROLES resolved one-per-property; a reconciliation is always OF one named
 * account, so the account has to exist before a statement can belong to anything. Nothing posts
 * through it yet, and the most important test here is the one asserting that.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);

    $this->actingAs(makeUser('accounting'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->asset = makeAsset();
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('registers an account and shows it, masked', function () {
    $account = BankAccount::create([
        'asset_id' => $this->asset->id,
        'name' => 'CIB — current',
        'bank_name' => 'CIB',
        'account_number' => '100020003004821',
    ]);

    // The whole number is stored, because a statement quotes it and a truncated one cannot be
    // matched back; only the last four are shown.
    expect($account->maskedNumber())->toBe('···4821')
        ->and($account->displayName())->toBe('CIB — current ···4821');

    Livewire::test(ListBankAccounts::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$account]);
});

it('changes nothing about posting — which is the whole claim of slice 1', function () {
    // If registering a bank account moved so much as one entry, this slice would not be additive
    // and could not ship on its own. The registry that decides what posts must not have gained a
    // source, and the model must not be one.
    BankAccount::create([
        'asset_id' => $this->asset->id,
        'name' => 'CIB — current',
    ]);

    expect(array_key_exists(BankAccount::class, LedgerPoster::JOURNALIZERS))->toBeFalse()
        ->and(JournalEntry::count())->toBe(0);
});

it('scopes the list to the property, and never falls back to unscoped', function () {
    $mine = BankAccount::create(['asset_id' => $this->asset->id, 'name' => 'Mine']);
    $other = BankAccount::create(['asset_id' => makeAsset()->id, 'name' => 'Another mall']);

    Livewire::test(ListBankAccounts::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$other]);
});

it('refuses to file an account against a property outside the user\'s reach', function () {
    // The server-side half. The Select is scoped, but its value is client-supplied in
    // All-Properties mode, so the write path re-validates — a scoped picker alone is a layer-3
    // guard and the API/import walks past it.
    $foreign = makeAsset();
    $restricted = makeUser('accounting', [$this->asset->id]);
    $this->actingAs($restricted);

    expect(fn () => BankAccountResource::assertAssetInScope($foreign->id))
        ->toThrow(HttpException::class);

    // Paired control: their own property still passes, or the guard could be refusing everything.
    BankAccountResource::assertAssetInScope($this->asset->id);
    expect(true)->toBeTrue();
});

it('is gated on its own permission', function () {
    expect(BankAccountResource::canViewAny())->toBeTrue(); // accounting, from beforeEach

    $this->actingAs(makeUser('leasing'));
    expect(BankAccountResource::canViewAny())->toBeFalse();
});
