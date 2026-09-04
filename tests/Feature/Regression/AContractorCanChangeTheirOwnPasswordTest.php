<?php

/*
|--------------------------------------------------------------------------
| Regression — the contractor profile enforced a uniqueness the domain rejects
|--------------------------------------------------------------------------
| `vendor_contacts.email` deliberately carries NO unique index. The migration that gave a contractor
| a login says why in `up()`: two contractors can legitimately share a switchboard address, so
| uniqueness is required only among the rows that can actually SIGN IN — a partial rule MySQL cannot
| express portably, which is why it lives on the model.
|
| Filament's stock profile page did not know that. `EditProfile::getEmailFormComponent()` builds
| `->unique(ignoreRecord: true)` against the panel's model, which here compiles to
| `unique:vendor_contacts,email,"<id>",id` — the whole table. And the profile writes name, email and
| password in ONE submit, so the consequence is not a refused email change: it is a contractor who
| can never change their own password, refused on a field they never touched, over an address this
| system's own rules say is fine.
|
| Every refusal here is paired with a control that must succeed, because a rule that refused
| everything would satisfy the refusal on its own and read as a pass.
*/

use App\Filament\Vendor\Auth\EditProfile;
use App\Models\Vendor;
use App\Models\VendorContact;
use Filament\Auth\Pages\EditProfile as StockEditProfile;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

beforeEach(function () {
    $this->firm = Vendor::create(['name' => 'Cool Air Co', 'status' => Vendor::STATUS_ACTIVE]);
    $this->rival = Vendor::create(['name' => 'Rival Mechanical', 'status' => Vendor::STATUS_ACTIVE]);

    $this->contact = VendorContact::create([
        'vendor_id' => $this->firm->id,
        'name' => 'Hani',
        'email' => 'switchboard@coolair.test',
        'password' => 'secret-secret',
        'is_portal_user' => true,
    ]);

    $this->actingAs($this->contact, 'vendor');
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
});

afterEach(fn () => Filament::setCurrentPanel(Filament::getPanel('admin')));

it('lets a contractor change their own password when a colleague shares the switchboard address', function () {
    // The ordinary data the stock rule refused: a second contact on the SAME address who cannot
    // sign in. `2026_08_28_210000_a_contractor_can_sign_in` names exactly this case in `up()`.
    VendorContact::create([
        'vendor_id' => $this->firm->id,
        'name' => 'Reception',
        'email' => 'switchboard@coolair.test',
        'is_portal_user' => false,
    ]);

    Livewire::test(EditProfile::class)
        ->fillForm([
            'name' => 'Hani',
            'email' => 'switchboard@coolair.test',
            'password' => 'a-longer-new-secret',
            'passwordConfirmation' => 'a-longer-new-secret',
            'currentPassword' => 'secret-secret',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Hash::check('a-longer-new-secret', $this->contact->fresh()->password))->toBeTrue();
});

it('lets a contractor move onto an address only a non-login contact holds', function () {
    VendorContact::create([
        'vendor_id' => $this->rival->id,
        'name' => 'Rival reception',
        'email' => 'desk@rival.test',
        'is_portal_user' => false,
    ]);

    Livewire::test(EditProfile::class)
        ->fillForm([
            'name' => 'Hani',
            'email' => 'desk@rival.test',
            'currentPassword' => 'secret-secret',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->contact->fresh()->email)->toBe('desk@rival.test');
});

it('still refuses an address another LOGIN already answers to', function () {
    // The control on the narrowing. The domain rule has to keep biting, or the fix would have
    // traded a lockout for two contractors racing for one sign-in.
    VendorContact::create([
        'vendor_id' => $this->rival->id,
        'name' => 'Mostafa',
        'email' => 'mostafa@rival.test',
        'password' => 'secret-secret',
        'is_portal_user' => true,
    ]);

    // `assertHasFormErrors` checks `data.email`, which is the FORM layer. The model's own
    // `saving` guard keys its ValidationException on bare `email`, so this assertion cannot be
    // satisfied by the backstop — it proves the rule on the field.
    Livewire::test(EditProfile::class)
        ->fillForm([
            'name' => 'Hani',
            'email' => 'mostafa@rival.test',
            'currentPassword' => 'secret-secret',
        ])
        ->call('save')
        ->assertHasFormErrors(['email']);

    expect($this->contact->fresh()->email)->toBe('switchboard@coolair.test');
});

it('overrides the profile page on the one panel whose table has no unique index', function () {
    // DERIVED from the schema rather than restated: the day somebody puts a unique index on
    // `vendor_contacts.email` this goes red and says the override has become redundant, instead of
    // leaving the next reader to guess why one panel differs from the other two.
    $uniqueOnEmail = fn (string $table): bool => collect(Schema::getIndexes($table))
        ->contains(fn (array $index): bool => ($index['unique'] ?? false) && in_array('email', $index['columns'], true));

    expect($uniqueOnEmail('users'))->toBeTrue()
        ->and($uniqueOnEmail('tenant_users'))->toBeTrue()
        ->and($uniqueOnEmail('vendor_contacts'))->toBeFalse();

    expect(Filament::getPanel('vendor')->getProfilePage())->toBe(EditProfile::class)
        ->and(Filament::getPanel('admin')->getProfilePage())->toBe(StockEditProfile::class)
        ->and(Filament::getPanel('portal')->getProfilePage())->toBe(StockEditProfile::class);
});

it('answers the same question through the Eloquent scope and through a raw query builder', function () {
    // The seam, in both shapes it is asked in: the model's own scope, and the raw builder Laravel's
    // presence verifier hands a `Unique` rule's closure. The second shape is exercised by nothing
    // else in the suite, and it is the one the profile form depends on.
    VendorContact::create([
        'vendor_id' => $this->rival->id,
        'name' => 'Reception',
        'email' => 'desk@rival.test',
        'is_portal_user' => false,
    ]);

    expect(VendorContact::query()->portalUsers()->where('email', 'desk@rival.test')->exists())->toBeFalse()
        ->and(VendorContact::query()->where('email', 'desk@rival.test')->exists())->toBeTrue();

    $raw = DB::table('vendor_contacts')->where('email', 'desk@rival.test');
    VendorContact::constrainToLogins($raw);

    expect($raw->exists())->toBeFalse();
});
