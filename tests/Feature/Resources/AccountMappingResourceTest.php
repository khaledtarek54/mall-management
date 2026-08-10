<?php

use App\Filament\Admin\Resources\AccountMappings\AccountMappingResource;
use App\Filament\Admin\Resources\AccountMappings\Pages\EditAccountMapping;
use App\Filament\Admin\Resources\AccountMappings\Pages\ListAccountMappings;
use App\Models\AccountMapping;
use App\Models\LedgerAccount;
use App\Services\Accounting\AccountResolver;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * The posting map — the screen that lets an accountant re-point a role at a different account.
 *
 * Until this shipped the `account_mappings` table was seeded and thereafter unreachable, so
 * re-pointing rent revenue meant SQL against production, even though the seeder's own docblock
 * promised the accountant could do it "from the UI without touching code".
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant(makeAsset());
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('renders the posting map', function () {
    Livewire::test(ListAccountMappings::class)->assertOk();
});

it('re-points a role at a different account, and the ledger follows', function () {
    // The whole point of the screen: what the accountant saves is what the next journal entry uses.
    $mapping = AccountMapping::query()->whereNull('asset_id')->where('key', 'rent_revenue')->sole();
    $other = LedgerAccount::query()->where('code', '41106001')->sole(); // marketing revenue

    Livewire::test(EditAccountMapping::class, ['record' => $mapping->getKey()])
        ->fillForm(['ledger_account_id' => $other->id])
        ->call('save')
        ->assertHasNoFormErrors();

    // A fresh resolver: the running one memoises per request, which is correct at runtime and would
    // hide the change here.
    expect(app(AccountResolver::class)->account('rent_revenue')->code)->toBe('41106001');
});

it('refuses a second global default for a role', function () {
    // The hazard the screen itself introduces. `unique(['key','asset_id'])` cannot catch this,
    // because SQL treats every NULL as distinct — and a duplicate does not break anything visibly:
    // the resolver takes the older row, so the accountant's change silently does nothing.
    $account = LedgerAccount::query()->where('code', '41106001')->sole();

    expect(fn () => AccountMapping::create([
        'key' => 'rent_revenue',
        'ledger_account_id' => $account->id,
        'asset_id' => null,
    ]))->toThrow(DomainException::class);

    expect(AccountMapping::query()->whereNull('asset_id')->where('key', 'rent_revenue')->count())->toBe(1);
});

it('refuses a second override for the same role and property', function () {
    $asset = makeAsset();
    $account = LedgerAccount::query()->where('code', '41106001')->sole();

    AccountMapping::create(['key' => 'rent_revenue', 'ledger_account_id' => $account->id, 'asset_id' => $asset->id]);

    expect(fn () => AccountMapping::create([
        'key' => 'rent_revenue',
        'ledger_account_id' => $account->id,
        'asset_id' => $asset->id,
    ]))->toThrow(DomainException::class);
});

it('lets the same role be overridden for a DIFFERENT property', function () {
    // The control for the two refusals above — the guard must not block the feature it protects.
    $here = makeAsset();
    $elsewhere = makeAsset();
    $account = LedgerAccount::query()->where('code', '41106001')->sole();

    AccountMapping::create(['key' => 'rent_revenue', 'ledger_account_id' => $account->id, 'asset_id' => $here->id]);
    AccountMapping::create(['key' => 'rent_revenue', 'ledger_account_id' => $account->id, 'asset_id' => $elsewhere->id]);

    expect(AccountMapping::query()->where('key', 'rent_revenue')->count())->toBe(3); // global + two overrides
    expect(app(AccountResolver::class)->account('rent_revenue', $here->id)->code)->toBe('41106001')
        // …and the global default is untouched.
        ->and(app(AccountResolver::class)->account('rent_revenue')->code)->toBe('41101001');
});

it('refuses to delete a global default, which has nothing behind it', function () {
    $mapping = AccountMapping::query()->whereNull('asset_id')->where('key', 'accounts_receivable')->sole();

    expect(fn () => $mapping->delete())->toThrow(DomainException::class);
    expect(AccountMapping::query()->whereKey($mapping->getKey())->exists())->toBeTrue();

    // The button is hidden too, so the operator is never offered a click that can only fail.
    Livewire::test(EditAccountMapping::class, ['record' => $mapping->getKey()])
        ->assertActionHidden('delete');
});

it('deletes an override and falls back to the global default', function () {
    // The control for the refusal above: removing an override is exactly what an operator should be
    // able to do, and the role keeps resolving afterwards.
    $asset = makeAsset();
    $account = LedgerAccount::query()->where('code', '41106001')->sole();
    $override = AccountMapping::create([
        'key' => 'rent_revenue',
        'ledger_account_id' => $account->id,
        'asset_id' => $asset->id,
    ]);

    Livewire::test(EditAccountMapping::class, ['record' => $override->getKey()])
        ->assertActionVisible('delete')
        ->callAction('delete');

    expect(AccountMapping::query()->whereKey($override->getKey())->exists())->toBeFalse();
    expect(app(AccountResolver::class)->account('rent_revenue', $asset->id)->code)->toBe('41101001');
});

it('lets only the books-owning roles CHANGE where a role posts', function () {
    // Viewing is not the line here: `viewer` is granted every `.view` in the project by design, and
    // reading the posting map is as harmless as reading the chart of accounts it points at. What
    // must be gated is re-pointing a role, because that silently moves every future posting.
    $this->actingAs(makeUser('viewer'));

    expect(AccountMappingResource::canViewAny())->toBeTrue()
        ->and(AccountMappingResource::canCreate())->toBeFalse();

    $mapping = AccountMapping::query()->whereNull('asset_id')->where('key', 'rent_revenue')->sole();

    expect(AccountMappingResource::canEdit($mapping))->toBeFalse();

    // The control — a refusal test passes just as happily when nobody can do the thing at all.
    $this->actingAs(makeUser('accounting'));

    expect(AccountMappingResource::canEdit($mapping))->toBeTrue()
        ->and(AccountMappingResource::canCreate())->toBeTrue();
});
