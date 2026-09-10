<?php

use App\Filament\Admin\Pages\Tenancy\RegisterProperty;
use App\Filament\Admin\Resources\Assets\AssetResource;
use App\Filament\Admin\Resources\Assets\Pages\CreateAsset;
use App\Models\Asset;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

/**
 * **WHOEVER ADDS A PROPERTY CAN WORK IN IT.**
 *
 * `assets.create` is held by `manager` and `mall_admin` as well as super_admin, and neither is
 * assigned to a mall by creating one. Measured before this fix, driving the real create page as a
 * `manager`: the mall was created, and then
 *
 *   creator assigned to it?   false
 *   canAccessTenant(new)?     false
 *   appears in the switcher?  false
 *   /admin/CF/assets/{new}/edit → 404
 *   /admin/VP/assets/{new}/edit → 404
 *
 * — a property they had just added, invisible and unreachable to them, rescuable only by a super
 * admin. `RegisterProperty::handleRegistration()` had always attached the creator for the
 * first-property flow; the register's create page never did, and nothing compared the two.
 *
 * `Asset::assignTo()` is that one answer, extracted on its second real call site. It is an
 * ASSIGNMENT and not a grant — the creator's authority comes from the Spatie role they already
 * hold, which is the reasoning `RegisterProperty` carried and this keeps.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    ensureAllPropertiesAsset();

    $this->home = makeAsset(['code' => 'VP', 'name' => 'Val Plaza']);
});

function createdMall(array $overrides = []): array
{
    $mall = array_merge([
        'name' => 'Cairo Festival', 'code' => 'CF', 'type' => 'mall', 'city' => 'Cairo',
        'country' => 'EG', 'currency' => 'EGP', 'total_area_sqm' => 1000,
        'leasable_area_sqm' => 800, 'is_active' => true,
    ], $overrides);

    Livewire::test(CreateAsset::class)->fillForm($mall)->call('create')->assertHasNoFormErrors();

    return $mall;
}

it('lets a manager open the mall they just created', function () {
    $manager = makeUser('manager', [$this->home->id]);
    $this->actingAs($manager);

    asTenant($this->home, fn () => createdMall());

    $new = Asset::where('code', 'CF')->sole();
    $manager = $manager->fresh();

    expect($manager->canAccessTenant($new))->toBeTrue()
        ->and($manager->getTenants(Filament::getPanel('admin'))->contains('id', $new->id))->toBeTrue();

    // The one that matters: the page opens.
    $this->get("/admin/CF/assets/{$new->id}/edit")->assertOk();
});

it('does not disturb the malls they already held', function () {
    // `syncWithoutDetaching`, not `sync` — this grants and never revokes. A `sync()` here would
    // silently drop every other assignment the moment somebody adds a second property.
    $manager = makeUser('manager', [$this->home->id]);
    $this->actingAs($manager);

    asTenant($this->home, fn () => createdMall());

    expect($manager->fresh()->assignedAssets()->pluck('assets.code')->all())
        ->toContain('VP')->toContain('CF');
});

it('writes NO assignment for a super admin, who could already reach it', function () {
    // An earlier version of this test claimed the fix "changes nothing for a super admin". Access
    // was unchanged — `AssignedAssets::idsFor()` short-circuits on the role — but a pivot row WAS
    // written, which put that super admin in the property's Assigned Staff register and in
    // `AssetStaffRecipients` for every mall they create. A grant that grants nothing is noise in
    // the one register that answers "who works at this mall".
    //
    // `assignTo()` asks `canAccessTenant()` — the same question the assignment exists to answer —
    // so no role is named, and a future role that can already reach everything is covered by being
    // what it is.
    $admin = makeUser('super_admin');
    $this->actingAs($admin);

    asTenant($this->home, fn () => createdMall(['code' => 'SA', 'name' => 'Super Added']));

    $new = Asset::where('code', 'SA')->sole();

    expect($admin->fresh()->assignedAssets()->whereKey($new->id)->exists())->toBeFalse()
        ->and($new->staff()->count())->toBe(0);

    // And they can still reach it — which is why the row was never needed.
    expect($admin->fresh()->canAccessTenant($new))->toBeTrue();
    $this->get("/admin/SA/assets/{$new->id}/edit")->assertOk();
});

it('records the grant, because an unrecorded attach is an unrecorded grant of access', function () {
    // The rule `PropertyRoster` states for the staff tab, which writes this same pivot: Laravel's
    // pivot writes go through the query builder and fire no model event, so without this the one
    // change an audit trail exists for would leave no trace anywhere. Review found this missing —
    // two writers on one table, one audited and gated on `roles.edit`, the other silent.
    $manager = makeUser('manager', [$this->home->id]);
    $this->actingAs($manager);

    asTenant($this->home, fn () => createdMall());

    $new = Asset::where('code', 'CF')->sole();

    $attached = Activity::query()
        ->where('subject_type', $new->getMorphClass())
        ->where('subject_id', $new->id)
        ->where('event', 'attached')
        ->get();

    expect($attached)->toHaveCount(1)
        // Nested under `attributes`, the shape every audited change uses — and the person travels
        // as DATA, so `ActivityVocabulary` words it in the reader's language at read time.
        ->and($attached->first()->properties['attributes']['staff'] ?? null)->toBe($manager->name);
});

it('refuses to create a property to a role that may not, on the CHANGED door', function () {
    // **The refusal control belonged on this page and was on the other one.** The first version of
    // this file drove `RegisterProperty`, whose gate predates the change — so nothing asserted that
    // a role without `assets.create` cannot reach `CreateAsset` and self-assign, which is exactly
    // the escalation `08603e36` closed. Found by review.
    $this->actingAs(makeUser('viewer'));

    expect(AssetResource::canCreate())->toBeFalse();

    // 404, not 403 — a `viewer` holds no property assignment either, so `IdentifyTenant` refuses
    // the segment before `canCreate()` is ever consulted. Both layers hold; asserting the status
    // Filament actually returns is what keeps this test honest about WHICH one answered.
    asTenant($this->home, function () {
        $this->get(AssetResource::getUrl('create'))->assertNotFound();
    });

    // So the gate itself is asserted directly, for a role that CAN reach the segment.
    $this->actingAs(makeUser('operations', [$this->home->id]));
    expect(AssetResource::canCreate())->toBeFalse();

    // And the CONTROL — a role that may create still can, or the two assertions above would be
    // satisfied by a screen nobody can reach.
    $this->actingAs(makeUser('manager', [$this->home->id]));
    expect(AssetResource::canCreate())->toBeTrue();
});

it('assigns the creator on the first-property flow too, from the same definition', function () {
    // `RegisterProperty` is the other door — where Filament sends someone with no property at all.
    // It has always attached the creator; it now reads `Asset::assignTo()` so the two cannot drift.
    $manager = makeUser('manager');
    $this->actingAs($manager);

    Livewire::test(RegisterProperty::class)->fillForm([
        'name' => 'First Mall', 'code' => 'FM', 'type' => 'mall', 'city' => 'Cairo',
        'country' => 'EG', 'currency' => 'EGP', 'total_area_sqm' => 500,
        'leasable_area_sqm' => 400, 'is_active' => true,
    ])->call('register')->assertHasNoFormErrors();

    $new = Asset::where('code', 'FM')->sole();

    expect($manager->fresh()->assignedAssets()->whereKey($new->id)->exists())->toBeTrue();
});

it('still refuses to create a property to a role that may not', function () {
    // The refusal control. `viewer` holds no `assets.create`, and `RegisterProperty` gates in
    // `handleRegistration()` rather than in the form's visibility, because a hidden form is still
    // dispatchable.
    $this->actingAs(makeUser('viewer'));

    Livewire::test(RegisterProperty::class)->fillForm([
        'name' => 'Sneaky', 'code' => 'SNK', 'type' => 'mall', 'city' => 'Cairo',
        'country' => 'EG', 'currency' => 'EGP', 'total_area_sqm' => 500,
        'leasable_area_sqm' => 400, 'is_active' => true,
    ])->call('register');

    expect(Asset::where('code', 'SNK')->exists())->toBeFalse();
});
