<?php

use App\Actions\Api\Auth\LoginTenantAction;
use App\Filament\Admin\Resources\Tenants\Pages\EditTenant;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Lang;
use Livewire\Livewire;

/**
 * THE TENANT RECORD'S PASSWORD BUTTON SETS THE MOBILE APP CREDENTIAL, NOT THE WEB PORTAL LOGIN.
 *
 * Reported from staging 2026-09-04: a tenant "always gives wrong creds" at /portal. The button on
 * the tenant Edit page was labelled *"Setup Portal Access"* and its modal said, in both languages,
 * "they will log in at /portal with their email" — while it writes `tenants.password`, which the
 * portal never reads. `/portal` resolves guard `portal` → provider `tenant_users` → TenantUser;
 * `tenants.password` is checked only by LoginTenantAction for the mobile API (`/api/v1`, Sanctum
 * `tenant-api`). So the operator handed the tenant a credential that CANNOT work on the surface
 * they were told to use it on, and Filament answers a bad password and an unknown account with the
 * same sentence — by design, so it cannot be told apart from the outside.
 *
 * What each assertion proves, because they are not interchangeable:
 *  - the behavioural pair pins the ARCHITECTURE (two surfaces, two credential stores). It cannot
 *    fail on wording alone, and it was true before the fix as well as after.
 *  - the vocabulary test is what pins the FIX: the misleading keys are gone and the replacements
 *    exist in both languages.
 *
 * NOTE FOR THE UNIFICATION (owner's decision 2026-09-04): the mobile API is to authenticate
 * TenantUser so one person has one login for both surfaces. When that lands, the refusal below
 * becomes FALSE and this file must be rewritten rather than deleted — a red here is the signal
 * that the two surfaces have merged, which is exactly when the docs and the mobile contract move.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->asset = makeAsset();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('sets a credential the mobile app accepts and the web portal refuses', function () {
    $tenant = makeTenant(['password' => null]);

    $this->actingAs(makeUser('manager', [$this->asset->id]));
    Filament::setTenant($this->asset);

    Livewire::test(EditTenant::class, ['record' => $tenant->id])
        ->mountAction(TestAction::make('mobileAppAccess'))
        ->setActionData(['password' => 'S3cretAppPass'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($tenant->fresh()->password)->not->toBeNull();

    // CONTROL — the credential is real: the mobile API accepts it. Without this the refusal below
    // would pass just as happily against a password that was never stored.
    $result = app(LoginTenantAction::class)->handle($tenant->email, 'S3cretAppPass', 'phone');
    expect($result['tenant']->id)->toBe($tenant->id);

    // THE REFUSAL — the same credential opens nothing at /portal.
    expect(Auth::guard('portal')->attempt([
        'email' => $tenant->email,
        'password' => 'S3cretAppPass',
    ]))->toBeFalse();
});

it('opens the web portal for a TenantUser, which is where portal logins live', function () {
    $tenant = makeTenant();
    $portalUser = makeTenantUser($tenant);

    expect(Auth::guard('portal')->attempt([
        'email' => $portalUser->email,
        'password' => 'password',
    ]))->toBeTrue();
});

it('no longer offers the operator a button that promises portal access', function () {
    // `fallback: false` throughout: Lang::has() falls back to English by default, so the obvious
    // spelling only ever catches a key missing from BOTH catalogues.
    foreach (['setup_mobile', 'reset_mobile', 'mobile_modal_heading', 'mobile_modal_description', 'mobile_set'] as $key) {
        expect(Lang::has("admin.tenants.{$key}", 'en', fallback: false))->toBeTrue("EN missing {$key}")
            ->and(Lang::has("admin.tenants.{$key}", 'ar', fallback: false))->toBeTrue("AR missing {$key}");
    }

    // The misleading keys are GONE, not merely unused — a dead key is what a later edit re-adopts.
    foreach (['setup_portal', 'portal_modal_description', 'portal_set'] as $retired) {
        expect(Lang::has("admin.tenants.{$retired}", 'en', fallback: false))->toBeFalse("EN still defines {$retired}")
            ->and(Lang::has("admin.tenants.{$retired}", 'ar', fallback: false))->toBeFalse("AR still defines {$retired}");
    }
});
