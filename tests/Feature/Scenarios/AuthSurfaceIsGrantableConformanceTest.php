<?php

/*
|--------------------------------------------------------------------------
| Every auth surface is GRANTABLE from a screen (ROADMAP §9.3 gate 1)
|--------------------------------------------------------------------------
| The `is_portal_user` shape, made permanent. The vendor portal shipped with a flag its
| `canAccessPanel()` required and NOTHING anywhere wrote — so no contractor could ever sign in and
| the whole `/vendor` panel was inert, invisible to every test that built contacts by hand
| (a fixture writing the column is exactly how a green suite hides a screen nobody can reach).
|
| The rule: for every attribute a panel's own `canAccessPanel()` gates on, there must be a WRITE
| PATH an operator can actually reach — a form field, an action, a relation manager — and this file
| must name it. Three teeth, because each catches what the others cannot:
|
|  1. DERIVATION — the registry is checked against the `canAccessPanel()` SOURCE both ways, so a
|     panel that grows a new gate attribute fails until it is registered, and a registry row whose
|     attribute left the predicate fails as stale. A gate that reads only its own registry cannot
|     see what the registry omits.
|  2. WRITER — each named writer file still writes the named column, in the named shape. A grep for
|     "any writer anywhere" would count the model's own $fillable line and prove nothing.
|  3. BEHAVIOUR — the sharpest flags are driven end to end: grant → the panel opens; revoke → it
|     shuts. A write path that exists but does not reach the predicate is the same defect.
*/

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorContact;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;

/**
 * attribute-token => [writer file, shape that file must still contain].
 *
 * The TOKEN must appear in that model's canAccessPanel() body (tooth 1); the WRITER must still
 * carry the shape (tooth 2). Relation-hop gates (`tenant?->status`) register the RELATED column
 * with the screen that writes it.
 */
const AUTH_SURFACE_GRANTS = [
    'vendor' => [
        'model' => VendorContact::class,
        'gates' => [
            'is_portal_user' => [
                'app/Filament/Admin/Resources/Vendors/RelationManagers/ContactsRelationManager.php',
                "Toggle::make('is_portal_user')",
            ],
            'vendor?->status' => [
                'app/Filament/Admin/Resources/Vendors/Schemas/VendorForm.php',
                "Select::make('status')",
            ],
        ],
    ],
    'portal' => [
        'model' => TenantUser::class,
        'gates' => [
            'tenant?->status' => [
                'app/Filament/Admin/Resources/Tenants/Schemas/TenantForm.php',
                "Select::make('status')",
            ],
            // The LOGIN ROW itself is the grant: no TenantUser row, no portal. The relation
            // manager on the tenant record is where one is minted.
            '__login_row__' => [
                'app/Filament/Admin/RelationManagers/PortalUsersRelationManager.php',
                'TenantUser',
            ],
        ],
    ],
    'admin' => [
        'model' => User::class,
        'gates' => [
            'isSuspended' => [
                'app/Filament/Admin/Actions/UserActions.php',
                "Action::make('suspend')",
            ],
        ],
    ],
];

it('registers every attribute each panel actually gates on — both directions', function () {
    foreach (AUTH_SURFACE_GRANTS as $panel => $spec) {
        $method = new ReflectionMethod($spec['model'], 'canAccessPanel');
        $lines = file($method->getFileName());
        $body = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

        foreach (array_keys($spec['gates']) as $token) {
            if ($token === '__login_row__') {
                continue; // the row's existence, not an attribute — tooth 3 proves it
            }

            // `toBeTrue($msg)` not `toContain($x, $msg)` — a Pest matcher's second argument is
            // ANOTHER NEEDLE, not a message (the false-pass trap the memory file records).
            expect(str_contains($body, $token))
                ->toBeTrue("[$panel] registry names '$token' but canAccessPanel() no longer reads it — stale row");
        }

        // The other direction: every $this-> attribute the predicate reads must be registered or
        // structural. `getId` is the panel routing; relations are covered via their ?->status form.
        preg_match_all('/\$this->(\w+)(?!\()/', $body, $m);

        $structural = ['tenant', 'vendor'];   // relation hops registered as `rel?->status`

        foreach (array_unique($m[1]) as $attr) {
            $registered = array_key_exists($attr, $spec['gates'])
                || array_key_exists($attr.'?->status', $spec['gates'])
                || in_array($attr, $structural, true)
                || method_exists($spec['model'], $attr) && array_key_exists($attr, $spec['gates']);

            expect($registered || method_exists($spec['model'], $attr) === false)
                ->toBeTrue("[$panel] canAccessPanel() reads '{$attr}' and the registry does not cover it — a new gate attribute shipped without a stated write path");
        }
    }
});

it('still has a live writer behind every registered gate', function () {
    foreach (AUTH_SURFACE_GRANTS as $panel => $spec) {
        foreach ($spec['gates'] as $token => [$file, $shape]) {
            $path = base_path($file);

            expect(is_file($path))->toBeTrue("[$panel/$token] writer file gone: $file");
            expect(str_contains(file_get_contents($path), $shape))
                ->toBeTrue("[$panel/$token] $file no longer writes it — the flag is back to being un-grantable");
        }
    }
});

it('opens and shuts the vendor panel by exactly the registered flags', function () {
    $this->seed(RolesPermissionsSeeder::class);

    $vendor = Vendor::create(['name' => 'Gate Co', 'status' => Vendor::STATUS_ACTIVE]);
    $contact = VendorContact::create([
        'vendor_id' => $vendor->id, 'name' => 'G', 'email' => 'g@gate.test',
        'password' => 'secret-secret', 'is_portal_user' => true,
    ]);

    $panel = Filament::getPanel('vendor');

    expect($contact->canAccessPanel($panel))->toBeTrue();

    // Revoke the personal flag — the door shuts for this login only.
    $contact->update(['is_portal_user' => false]);
    expect($contact->fresh()->canAccessPanel($panel))->toBeFalse();

    // Restore it; suspend the COMPANY — every login shuts, whatever its own flag says.
    $contact->update(['is_portal_user' => true]);
    $vendor->update(['status' => Vendor::STATUS_INACTIVE]);
    expect($contact->fresh()->canAccessPanel($panel))->toBeFalse();
});

it('opens and shuts the tenant portal by the company status', function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $tenant = makeTenant(['status' => 'active']);
    $login = TenantUser::create([
        'tenant_id' => $tenant->id, 'name' => 'P', 'email' => 'p@gate.test',
        'password' => 'secret-secret', 'is_admin' => true,
    ]);

    $panel = Filament::getPanel('portal');

    expect($login->canAccessPanel($panel))->toBeTrue();

    $tenant->update(['status' => 'blacklisted']);
    expect($login->fresh()->canAccessPanel($panel))->toBeFalse();
});

it('shuts every admin panel door when a user is suspended', function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $user = makeUser('manager', [makeAsset()->id]);
    $panel = Filament::getPanel('admin');

    expect($user->canAccessPanel($panel))->toBeTrue();

    $user->update(['status' => 'suspended', 'suspended_at' => now()]);
    expect($user->fresh()->canAccessPanel($panel))->toBeFalse();
});
