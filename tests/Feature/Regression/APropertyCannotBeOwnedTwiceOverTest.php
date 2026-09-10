<?php

use App\Filament\Admin\RelationManagers\AssetOwnersRelationManager;
use App\Filament\Admin\Resources\Assets\Pages\EditAsset;
use App\Models\Asset;
use App\Models\AssetOwner;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Lang;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * A property cannot be recorded as owned more than once over.
 *
 * Reported from the panel (Trello XrfFkqu5, Critical): attach an owner at 100%, attach a second at
 * 100%, and the Owners tab read **"Ownership recorded: 200.00%"** with nothing refusing it — only a
 * passive note saying a statement could not be finalised.
 *
 * **The money does not over-pay, and that is what makes it dangerous.**
 * `GenerateOwnerStatementRunService` weights each owner `pct / Σ pct`, so at 200% an owner RECORDED
 * at 100% is silently paid HALF the net — on a statement that prints 100% beside the figure. Then
 * `FinaliseOwnerStatementRunService` refuses the run because the total is not whole, so the property
 * stops distributing altogether and nothing on the owners screen explains why.
 *
 * **Over and under 100% are NOT symmetric, and that is the design.** Finalise deliberately enforces
 * the whole at the money path rather than on this form, for a reason worth preserving: *a 50/50
 * register cannot be built in one save — the first co-owner would be refused for totalling 50.*
 * True, and it says nothing about the other direction: no correct register has to pass THROUGH
 * 200% on its way to 100%. Adding a co-owner to a property already fully owned means reducing
 * somebody first. So under stays freely enterable and over is refused.
 *
 * Yardi and MRI both validate partner allocations to 100%; this is that rule, minus the half that
 * would make co-ownership unenterable.
 */
beforeEach(function () {
    $this->asset = makeAsset(['code' => 'OWN']);
});

/**
 * An owner `User` — ownership is held by an admin user carrying the `owner` role.
 *
 * The role is not decoration: `recordSelectOptionsQuery` narrows the Attach picker to
 * `whereHas('roles', name = owner)`, so a fixture without it is refused at validation and no test
 * driving the real action can pass. Assigned only once the catalogue has been seeded, so the
 * model-level cases stay fast and need no RBAC.
 */
function anOwner(string $name): User
{
    $user = User::create([
        'name' => $name,
        'email' => strtolower($name).uniqid().'@owner.test',
        'password' => bcrypt('password'),
    ]);

    if (Role::where('name', 'owner')->exists()) {
        $user->assignRole('owner');
    }

    return $user;
}

function ownAsset(Asset $asset, User $owner, float $pct, ?string $from = null, ?string $to = null): AssetOwner
{
    return AssetOwner::create([
        'asset_id' => $asset->id,
        'user_id' => $owner->id,
        'ownership_percentage' => $pct,
        'started_at' => $from,
        'ended_at' => $to,
    ]);
}

it('refuses a second owner that would take the property past 100% — the tester s exact case', function () {
    ownAsset($this->asset, anOwner('ShareHolder'), 100);

    expect(fn () => ownAsset($this->asset, anOwner('PropertyOwner'), 100))
        ->toThrow(DomainException::class);

    expect($this->asset->fresh()->ownershipRecordedOn())->toBe(100.0);
});

it('still lets a co-owned register be built up piece by piece', function () {
    // The control that decides whether this rule is usable at all. A 50/50 register is entered one
    // owner at a time, so it is BELOW 100% in between — refusing that would make co-ownership
    // impossible to record, which is why Finalise enforces the whole and this does not.
    ownAsset($this->asset, anOwner('First'), 50);
    ownAsset($this->asset, anOwner('Second'), 50);

    expect($this->asset->fresh()->ownershipRecordedOn())->toBe(100.0);
});

it('allows a resale, where two owners each hold the whole property at different times', function () {
    // The false alarm the naive fix creates. A sale is TWO rows — the seller ended, the buyer
    // started — and summing the column outright would refuse every property that ever changed
    // hands. Only OVERLAPPING tenures are counted.
    $seller = ownAsset($this->asset, anOwner('Seller'), 100, '2020-01-01', '2024-12-31');
    $buyer = ownAsset($this->asset, anOwner('Buyer'), 100, '2025-01-01', null);

    expect($seller->exists)->toBeTrue()
        ->and($buyer->exists)->toBeTrue()
        // …and the tab reports the CURRENT owner only, not both.
        ->and($this->asset->fresh()->ownershipRecordedOn())->toBe(100.0)
        ->and($this->asset->fresh()->ownershipRecordedOn('2024-06-01'))->toBe(100.0);
});

it('refuses a tenure widened until it overlaps another owner', function () {
    // The door that is not the Attach button: leave the shares alone and move the DATES until two
    // 100% owners share a day. Same rule, reached from the other side.
    ownAsset($this->asset, anOwner('Seller'), 100, '2020-01-01', '2024-12-31');
    $buyer = ownAsset($this->asset, anOwner('Buyer'), 100, '2025-01-01', null);

    expect(fn () => $buyer->update(['started_at' => '2024-01-01']))
        ->toThrow(DomainException::class);

    expect($buyer->fresh()->started_at->toDateString())->toBe('2025-01-01');
});

it('lets an already-over register be CORRECTED, or the guard would deadlock it', function () {
    // The over-lock control, and the one that matters most: staging is carrying a 200% register
    // right now. Refusing every over-100 save would mean neither owner could be reduced — each
    // measured against the other's 100 — so the property could never be fixed or distributed.
    // A save that leaves it LESS over-owned is the operator fixing it.
    $first = ownAsset($this->asset, anOwner('First'), 100);
    $second = AssetOwner::withoutEvents(fn () => ownAsset($this->asset, anOwner('Second'), 100));

    expect($this->asset->fresh()->ownershipRecordedOn())->toBe(200.0);

    $second->update(['ownership_percentage' => 40]);
    expect($this->asset->fresh()->ownershipRecordedOn())->toBe(140.0);

    $first->update(['ownership_percentage' => 60]);
    expect($this->asset->fresh()->ownershipRecordedOn())->toBe(100.0);
});

it('still refuses a save that makes an over-owned register WORSE', function () {
    // The paired control on that escape: "already broken" must not become a licence.
    $first = ownAsset($this->asset, anOwner('First'), 100);
    AssetOwner::withoutEvents(fn () => ownAsset($this->asset, anOwner('Second'), 100));

    expect(fn () => $first->update(['ownership_percentage' => 120]))
        ->toThrow(DomainException::class);

    expect($this->asset->fresh()->ownershipRecordedOn())->toBe(200.0);
});

it('allows history to be back-filled AFTER the current owner is recorded', function () {
    // The other direction of the overlap test, and the one the first version of this file did not
    // exercise: here the row being saved has a bounded END and the existing row starts after it.
    // With only the `from` half of the range check, the current owner would be counted against a
    // tenure that finished before they began, and back-filling the seller would be refused.
    ownAsset($this->asset, anOwner('Buyer'), 100, '2025-01-01', null);
    $seller = ownAsset($this->asset, anOwner('Seller'), 100, '2020-01-01', '2024-12-31');

    expect($seller->exists)->toBeTrue()
        ->and($this->asset->fresh()->ownershipRecordedOn())->toBe(100.0)
        ->and($this->asset->fresh()->ownershipRecordedOn('2021-06-01'))->toBe(100.0);
});

it('reports the CURRENT total on the owners tab, not every row ever written', function () {
    // The screen the card was filed from. Summing the column outright reported 200% for any
    // property that had ever changed hands, so the false alarm and the real one looked identical —
    // which is most of why the real one sat under a passive notice nobody acted on.
    test()->seed(RolesPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    test()->actingAs(makeUser('super_admin'));

    ownAsset($this->asset, anOwner('Seller'), 100, '2020-01-01', '2024-12-31');
    ownAsset($this->asset, anOwner('Buyer'), 100, '2025-01-01', null);

    Livewire\Livewire::test(AssetOwnersRelationManager::class, [
        'ownerRecord' => $this->asset->fresh(),
        'pageClass' => EditAsset::class,
    ])
        ->assertSee(__('admin.owner_statements.ownership_total_whole', ['total' => '100.00']))
        ->assertDontSee(__('admin.owner_statements.ownership_total_over', ['total' => '200.00']));
});

it('says so out loud when a register is ALREADY over the whole property', function () {
    // Pre-existing data: the guard refuses new over-100 saves, so a register like staging's is the
    // only way to reach this — and it is exactly the operator who needs telling, because no
    // statement can be produced and nothing else on the screen would explain the silence.
    test()->seed(RolesPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    test()->actingAs(makeUser('super_admin'));

    ownAsset($this->asset, anOwner('First'), 100);
    AssetOwner::withoutEvents(fn () => ownAsset($this->asset, anOwner('Second'), 100));

    Livewire\Livewire::test(AssetOwnersRelationManager::class, [
        'ownerRecord' => $this->asset->fresh(),
        'pageClass' => EditAsset::class,
    ])->assertSee(__('admin.owner_statements.ownership_total_over', ['total' => '200.00']));
});

it('refuses a save that widens a tenure into an overlap while shaving the share', function () {
    // Found by review, and REPRODUCED on real data before it was fixed. The escape compared both
    // readings across the SAME dates, so the other owners' total cancelled out and the test
    // collapsed to "the percentage went down" — the dates went unchecked. From this CLEAN resale
    // register, one Edit clearing the seller's end date and dropping 100 → 99.99 was allowed,
    // because 199.99 < 200, leaving the property at 199.99% overlapping.
    $seller = ownAsset($this->asset, anOwner('Seller'), 100, '2020-01-01', '2024-12-31');
    ownAsset($this->asset, anOwner('Buyer'), 100, '2025-01-01', null);

    expect(fn () => $seller->update(['ended_at' => null, 'ownership_percentage' => 99.99]))
        ->toThrow(DomainException::class);

    expect($this->asset->fresh()->ownershipRecordedOn())->toBe(100.0)
        ->and($seller->fresh()->ended_at->toDateString())->toBe('2024-12-31');
});

it('lets a DATE-ONLY correction through on an over register', function () {
    // The refusal tells the operator to end a tenure, so ending one must actually work. It moves no
    // percentage, so both readings are equal — which is why the comparison is `<=` and not `<`.
    $first = ownAsset($this->asset, anOwner('First'), 100);
    AssetOwner::withoutEvents(fn () => ownAsset($this->asset, anOwner('Second'), 100));

    $first->update(['ended_at' => CarbonImmutable::yesterday()->toDateString()]);

    expect($first->fresh()->ended_at->toDateString())->toBe(CarbonImmutable::yesterday()->toDateString())
        ->and($this->asset->fresh()->ownershipRecordedOn())->toBe(100.0);
});

it('attaches the buyer after a resale through the real Attach action', function () {
    // The door the card was actually filed from, which nothing drove. It also caught a refusal the
    // guard was causing on its own: a blank "Owned since" means owned since inception, so the
    // buyer claimed the property back through the seller's closed tenure and was refused at 200%.
    // The attach form defaults the start to today.
    test()->seed(RolesPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    test()->actingAs(makeUser('super_admin'));

    ownAsset($this->asset, anOwner('Seller'), 100, '2020-01-01', CarbonImmutable::yesterday()->toDateString());
    $buyer = anOwner('Buyer');

    Livewire\Livewire::test(AssetOwnersRelationManager::class, [
        'ownerRecord' => $this->asset->fresh(),
        'pageClass' => EditAsset::class,
    ])
        ->callAction(TestAction::make('attach')->table(), data: ['recordId' => $buyer->id, 'ownership_percentage' => 100])
        ->assertHasNoActionErrors();

    expect($this->asset->fresh()->ownershipRecordedOn())->toBe(100.0)
        ->and($this->asset->fresh()->propertyOwnersOn()->pluck('users.id')->all())->toBe([$buyer->id]);
});

it('refuses a second full owner through the real Attach action', function () {
    // …and the same door still refuses the card's own sequence. `attach()` reaches the model guard
    // only because both sides of the relation declare `->using(AssetOwner::class)`; without that it
    // writes straight through the query builder and fires no model event, and this fix would be
    // inert. That is what this case pins.
    test()->seed(RolesPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    test()->actingAs(makeUser('super_admin'));

    ownAsset($this->asset, anOwner('First'), 100);
    $second = anOwner('Second');

    try {
        Livewire\Livewire::test(AssetOwnersRelationManager::class, [
            'ownerRecord' => $this->asset->fresh(),
            'pageClass' => EditAsset::class,
        ])->callAction(TestAction::make('attach')->table(), data: ['recordId' => $second->id, 'ownership_percentage' => 100]);
        $attached = true;
    } catch (Throwable) {
        $attached = false;
    }

    expect($attached)->toBeFalse()
        ->and($this->asset->fresh()->ownershipRecordedOn())->toBe(100.0);
});

it('words the refusal and the over-100 notice in both languages', function () {
    foreach ([
        'admin.refusals.ownership_exceeds_the_property',
        'admin.owner_statements.ownership_total_over',
    ] as $key) {
        expect(Lang::has($key, 'en', false))->toBeTrue($key)
            ->and(Lang::has($key, 'ar', false))->toBeTrue($key)
            ->and((bool) preg_match('/\p{Arabic}/u', __($key, [], 'ar')))->toBeTrue($key);
    }

    // The refusal names what is left and what to do — a refusal that says only "no" is a dead end.
    expect(__('admin.refusals.ownership_exceeds_the_property', ['total' => '200.00', 'remaining' => '0.00']))
        ->toContain('0.00')
        ->and(__('admin.refusals.ownership_exceeds_the_property', ['total' => '200.00', 'remaining' => '0.00']))
        ->not->toContain('admin.refusals');
});
