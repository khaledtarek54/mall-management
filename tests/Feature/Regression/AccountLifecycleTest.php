<?php

/*
|--------------------------------------------------------------------------
| Account lifecycle + the property-registration privilege boundary
|--------------------------------------------------------------------------
|   1. TENANT REGISTRATION WAS OPEN TO EVERYONE. Filament routes a user with zero accessible
|      properties to the tenant-registration page, and gates it on
|      `authorize('create', Asset::class)`. With no AssetPolicy registered, Filament's authorize()
|      helper defaults to ALLOWED — so a read-only `viewer` (the auditor role), a `technician`,
|      even an external `vendor` login was served a working "Create your first property" form, and
|      `handleRegistration()` then attached them to the new mall with the pivot role `manager`.
|      Only super_admin, manager and mall_admin hold `assets.create`.
|
|   2. AND THE OTHER HALF: with the hole closed the same users would get a bare 404, with no hint
|      that what they need is an assignment from an administrator.
|
|   3. NO WAY TO SUSPEND A LOGIN. The only way to stop someone signing in was to delete the user,
|      which takes their name off every record and every activity-log row they caused.
*/

use App\Support\MorphMap;
use App\Filament\Admin\Pages\Auth\Login;
use App\Filament\Admin\Pages\Tenancy\RegisterProperty;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\Asset;
use App\Models\User;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
});

/* ---- 1 + 2: who may create a property ------------------------------------ */

it('lets only the roles holding assets.create register a property', function () {
    $allowed = ['super_admin', 'manager', 'mall_admin'];
    $denied = ['viewer', 'owner', 'leasing', 'operations', 'accounting', 'marketing', 'hr',
        'technician', 'coordinator', 'customer_service', 'vendor'];

    foreach ($allowed as $role) {
        $this->actingAs(makeUser($role));
        expect(RegisterProperty::canCreateProperty())->toBeTrue("[{$role}] should be able to create a property");
    }

    foreach ($denied as $role) {
        $this->actingAs(makeUser($role));
        expect(RegisterProperty::canCreateProperty())->toBeFalse(
            "[{$role}] must NOT be able to create a property — it would attach itself as that mall's manager"
        );
    }
});

it('still shows the page to a user who cannot create, so they learn why they are stuck', function () {
    // canView() stays true on purpose: it is what makes the "no property assigned" explanation
    // reachable. The gate that matters is in handleRegistration().
    $this->actingAs(makeUser('viewer'));

    expect(RegisterProperty::canView())->toBeTrue()
        ->and(RegisterProperty::canCreateProperty())->toBeFalse();
});

it('refuses the registration itself, not just the button', function () {
    // The real attack: dispatch the submit without ever rendering the form. A hidden form action
    // is still callable over Livewire, which is why the abort lives in the handler.
    $this->actingAs(makeUser('viewer'));

    $page = new RegisterProperty;
    $handle = new ReflectionMethod($page, 'handleRegistration');
    $handle->setAccessible(true);

    expect(fn () => $handle->invoke($page, [
        'name' => 'Hijacked Mall', 'code' => 'HJK', 'type' => 'mall', 'city' => 'Cairo', 'country' => 'EG',
    ]))->toThrow(HttpException::class);

    expect(Asset::where('code', 'HJK')->exists())->toBeFalse();
});

it('offers no submit action to a user who cannot create', function () {
    $this->actingAs(makeUser('viewer'));
    $page = new RegisterProperty;
    $actions = new ReflectionMethod($page, 'getFormActions');
    $actions->setAccessible(true);

    expect($actions->invoke($page))->toBe([]);

    $this->actingAs(makeUser('manager'));
    expect($actions->invoke(new RegisterProperty))->not->toBeEmpty();
});

/* ---- 3: suspend instead of delete ---------------------------------------- */

it('blocks a suspended account from every panel', function () {
    $user = makeUser('manager');
    $panel = Filament::getPanel('admin');

    expect($user->canAccessPanel($panel))->toBeTrue();

    $user->forceFill(['status' => User::STATUS_SUSPENDED, 'suspended_at' => now()])->save();

    // Re-read: the check must be about stored state, not an in-memory flag.
    expect($user->fresh()->canAccessPanel($panel))->toBeFalse()
        ->and($user->fresh()->isSuspended())->toBeTrue();
});

it('defaults every existing and new account to active', function () {
    // The migration backfills NOT NULL DEFAULT 'active', so there is no null case to handle —
    // an account is suspended only when someone deliberately suspends it.
    $user = makeUser('manager');

    expect($user->fresh()->status)->toBe(User::STATUS_ACTIVE)
        ->and($user->fresh()->isSuspended())->toBeFalse()
        ->and($user->fresh()->canAccessPanel(Filament::getPanel('admin')))->toBeTrue();
});

it('lets users.edit holders suspend, but never themselves', function () {
    $actor = makeUser('hr');           // holds users.edit
    $other = makeUser('leasing');

    $this->actingAs($actor);

    expect(UserResource::canSuspend($other))->toBeTrue()
        // Locking yourself out of the panel you administer is not recoverable from inside it.
        ->and(UserResource::canSuspend($actor))->toBeFalse();

    // A role without users.edit cannot suspend anyone.
    $this->actingAs(makeUser('technician'));
    expect(UserResource::canSuspend($other))->toBeFalse();
});

it('keeps a suspended user and their history rather than deleting them', function () {
    $user = makeUser('leasing');
    $id = $user->id;

    $user->forceFill([
        'status' => User::STATUS_SUSPENDED,
        'suspended_at' => now(),
        'suspended_reason' => 'left the company',
    ])->save();

    // Still a row, still named, still attributable.
    expect(User::find($id))->not->toBeNull()
        ->and(User::find($id)->name)->toBe($user->name)
        ->and(User::find($id)->suspended_reason)->toBe('left the company');
});

it('tells a suspended user why they cannot sign in, but only after the password checks out', function () {
    // Filament's generic "these credentials do not match our records" is a lie here — they DO
    // match — so the person retries, resets their password, and eventually calls support.
    $user = makeUser('leasing');
    $user->forceFill([
        'password' => bcrypt('correct-horse'),
        'status' => User::STATUS_SUSPENDED,
        'suspended_at' => now(),
    ])->save();

    $right = Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'correct-horse'])
        ->call('authenticate');

    expect($right->errors()->get('data.email'))
        ->toContain(__('admin.auth.account_suspended'));

    // A WRONG password on the same account must NOT reveal the suspension — the credential check
    // fails first, so there is nothing an attacker learns that they did not already know.
    $wrong = Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'not-the-password'])
        ->call('authenticate');

    expect($wrong->errors()->get('data.email'))
        ->not->toContain(__('admin.auth.account_suspended'));
});

it('records the suspension in the activity log', function () {
    $actor = makeUser('hr');
    $this->actingAs($actor);

    $user = makeUser('leasing');
    $user->forceFill(['status' => User::STATUS_SUSPENDED, 'suspended_at' => now()])->save();

    $logged = Activity::query()
        ->where('subject_type', MorphMap::alias(User::class))
        ->where('subject_id', $user->id)
        ->get()
        ->contains(fn ($a) => str_contains(json_encode($a->attribute_changes), 'suspended'));

    expect($logged)->toBeTrue('Suspending an account must leave an audit trail — it is the change an auditor looks for.');
});
