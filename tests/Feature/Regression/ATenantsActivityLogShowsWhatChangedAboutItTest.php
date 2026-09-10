<?php

use App\Filament\Admin\RelationManagers\TenantActivitiesRelationManager;
use App\Filament\Admin\Resources\Tenants\Pages\EditTenant;
use App\Models\Tenant;
use App\Models\TenantUser;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Hash;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

/**
 * "Tenant Activity Log only records changes made in Tenant Information/settings — changes made in
 * Portal & App Logins, Leases, and the other tabs under a tenant are not logged."
 *
 * TWO causes behind one symptom, and the first is the serious one:
 *
 *  1. **A portal login was audited NOWHERE in the system.** A `TenantUser` is a credential — one row
 *     opens both the tenant portal and the mobile API, and `is_admin` decides whether that person
 *     may WRITE. Creating one, promoting somebody to admin or moving their email left no trace at
 *     all: an unrecorded grant of access to a company's own billing.
 *  2. **The tab only read the tenant's own row.** Leases and documents were already audited and
 *     simply filed under their own subject.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs(makeUser('super_admin'));
    $this->asset = makeAsset();
    $this->tenant = makeTenant();
});

function tenantActivity(Tenant $tenant): Testable
{
    return Livewire::test(TenantActivitiesRelationManager::class, [
        'ownerRecord' => $tenant,
        'pageClass' => EditTenant::class,
    ]);
}

it('records a portal login being created, and shows it on the tenant', function () {
    $login = TenantUser::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Mona Adel',
        'email' => 'mona@example.test',
        'password' => Hash::make('secret-secret'),
        'is_admin' => false,
    ]);

    $rows = Activity::where('subject_type', $login->getMorphClass())
        ->where('subject_id', $login->id)
        ->get();

    expect($rows)->not->toBeEmpty();

    tenantActivity($this->tenant)->assertCanSeeTableRecords($rows);
});

it('records somebody being promoted to a login that can make changes', function () {
    // `is_admin` is the WRITE grant on both the portal and the mobile API, so this is the single
    // most consequential column on the record and it recorded nothing.
    $login = TenantUser::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Mona Adel',
        'email' => 'mona@example.test',
        'password' => Hash::make('secret-secret'),
        'is_admin' => false,
    ]);

    $login->update(['is_admin' => true]);

    $updated = Activity::where('subject_type', $login->getMorphClass())
        ->where('subject_id', $login->id)
        ->where('event', 'updated')
        ->latest('id')
        ->first();

    // `attribute_changes`, NOT `properties`. spatie writes a model diff to the first and only what
    // a caller passes to `withProperties()` to the second — `ActivityLogChangeRenderer` documents
    // that split and reads both. Asserting on `properties` here would read empty for every audited
    // model in the system and look like a defect that is not one.
    expect($updated)->not->toBeNull()
        ->and($updated->attribute_changes['attributes']['is_admin'] ?? null)->toBeTrue()
        ->and($updated->attribute_changes['old']['is_admin'] ?? null)->toBeFalse();
});

it('never writes the password into the trail', function () {
    // `password` is fillable here and is in ActivityLogging::CREDENTIALS. The trail must record THAT
    // a credential changed and never what it changed to — a hash in an audit table is a hash an
    // operator with activity_log.view can read.
    $login = TenantUser::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Mona Adel',
        'email' => 'mona@example.test',
        'password' => Hash::make('secret-secret'),
        'is_admin' => false,
    ]);

    $login->update(['password' => Hash::make('a-different-secret')]);

    $everything = Activity::where('subject_type', $login->getMorphClass())
        ->where('subject_id', $login->id)
        ->get()
        ->map(fn (Activity $a) => json_encode($a->properties).json_encode($a->attribute_changes))
        ->implode(' ');

    expect($everything)->not->toContain('password')
        ->and($everything)->not->toContain('$2y$');
});

it('shows a lease and a document on the tenant too', function () {
    $lease = makeLease(makeUnit($this->asset), $this->tenant, ['status' => 'draft']);

    $rows = Activity::where('subject_type', $lease->getMorphClass())
        ->where('subject_id', $lease->id)
        ->get();

    expect($rows)->not->toBeEmpty();

    tenantActivity($this->tenant)->assertCanSeeTableRecords($rows);
});

it('does not show another tenant s logins', function () {
    // The clause that matters. Composing an `orWhere` onto the relation's already-applied
    // `subject = this tenant` constraint binds AND-before-OR, and the child branch then escapes the
    // scope entirely — every portal login in the portfolio on every tenant's tab.
    $other = makeTenant();

    $mine = TenantUser::create([
        'tenant_id' => $this->tenant->id, 'name' => 'Mine', 'email' => 'mine@example.test',
        'password' => Hash::make('secret-secret'), 'is_admin' => false,
    ]);
    $theirs = TenantUser::create([
        'tenant_id' => $other->id, 'name' => 'Theirs', 'email' => 'theirs@example.test',
        'password' => Hash::make('secret-secret'), 'is_admin' => false,
    ]);

    $subjects = tenantActivity($this->tenant)
        ->instance()
        ->getTableQuery()
        ->get()
        ->where('subject_type', $mine->getMorphClass())
        ->pluck('subject_id');

    expect($subjects)->toContain($mine->id)
        ->and($subjects)->not->toContain($theirs->id);
});

it('still shows the tenant s own changes', function () {
    // The control: widening must not have replaced what the tab already did — which is the ONE
    // thing the tester said was working.
    $this->tenant->update(['name' => 'Renamed Retailer']);

    $own = Activity::where('subject_type', $this->tenant->getMorphClass())
        ->where('subject_id', $this->tenant->id)
        ->get();

    expect($own)->not->toBeEmpty();

    tenantActivity($this->tenant)->assertCanSeeTableRecords($own);
});

it('keeps the money registers OUT of it', function () {
    // The deliberate absence, asserted so it cannot drift. An invoice carries a `tenant_id` and is a
    // register with its own screen and its own volume; putting it here would bury the three facts
    // this tab exists for under a year of billing.
    $lease = makeLease(makeUnit($this->asset), $this->tenant, ['status' => 'active']);
    $invoice = makeInvoice($lease);

    $subjects = tenantActivity($this->tenant)
        ->instance()
        ->getTableQuery()
        ->get()
        ->pluck('subject_type')
        ->unique();

    expect($subjects)->not->toContain($invoice->getMorphClass());
});
