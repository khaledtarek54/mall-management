<?php

use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\Exports;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * Thirteen export actions across seven tables carried no gate at all, and six of those tables are
 * SHARED WITH THE PORTAL — one `InvoicesTable::configure()` serves the admin panel and the tenant
 * portal alike. So a tenant saw an "Export" button on their invoices, payments, leases and credit
 * notes.
 *
 * It is not a data leak, which is why it read as harmless: Filament exports
 * `getTableQueryForExport()`, the resource's own scoped query, so an export can never return a row
 * the list would not. It is a CRASH. Filament resolves the exporting user from
 * `Filament::getAuthGuard()` — `portal`, hence a `TenantUser` — and writes its id into
 * `exports.user_id`, which is a foreign key to `users`. The click either violates the constraint or,
 * where an admin happens to hold that id, files a tenant's export under a stranger's name.
 *
 * Two halves, because either alone would pass for the wrong reason: what the predicate ANSWERS, and
 * whether the thirteen call sites actually ASK it.
 */
it('refuses a portal user', function () {
    $tenant = Tenant::factory()->create();

    // `instanceof User` and not `?->can()` — `TenantUser` has no spatie roles, so `can()` answers
    // false today for the wrong reason and would answer TRUE the day the portal grows a policy.
    $this->actingAs(makeTenantUser($tenant, isAdmin: true), 'portal');

    expect(Exports::allowed(InvoiceResource::class))->toBeFalse();
});

it('still offers the export to an admin who may read the list', function () {
    // The control, and it is not optional: a gate that refused everyone would satisfy the refusal
    // above on its own and read as a pass. Export is the WIDE door per the FRD — whoever may read
    // the list may take it away — so an ordinary manager must still get it.
    //
    // Its own test rather than a second half of the one above, because `actingAs($user, 'portal')`
    // calls `shouldUse('portal')` for the rest of the request: a later `actingAs($admin)` sets the
    // web guard while `Auth::user()` still resolves through `portal`, and the control then fails
    // for a reason that has nothing to do with the gate.
    // The catalogue, not just the role: `seedRoles()` creates role ROWS with no grants, so a
    // "manager" who was never given `invoices.view` fails this gate for a reason that is nothing
    // to do with it. That is exactly the trap the search tests carry a note about — a refusal that
    // passes because the fixture holds no permissions at all.
    $this->seed(RolesPermissionsSeeder::class);

    $this->actingAs(makeUser('manager'));

    expect(InvoiceResource::canViewAny())->toBeTrue()
        ->and(Exports::allowed(InvoiceResource::class))->toBeTrue();
});

it('refuses an admin who cannot read the list at all', function () {
    seedRoles();

    $user = User::create([
        'name' => 'no role',
        'email' => 'norole'.uniqid().'@test.local',
        'password' => bcrypt('password'),
    ]);

    $this->actingAs($user);

    expect(InvoiceResource::canViewAny())->toBeFalse()
        ->and(Exports::allowed(InvoiceResource::class))->toBeFalse();
});

it('applies the extra permission a table asks for on top of the list gate', function () {
    // Tenant requests export wider than they list: a technician sees only what is assigned to them,
    // so taking the whole table away needs `requests.view_all`.
    $this->seed(RolesPermissionsSeeder::class);

    $this->actingAs(makeUser('manager'));

    expect(Exports::allowed(InvoiceResource::class, 'a.permission.nobody.holds'))->toBeFalse();
});

it('leaves no export action in the panel ungated', function () {
    // The call-site half. Building an action in a test proves nothing about the gate — the closure
    // never runs — so this reads the shipped source instead, which is the property that actually
    // regressed: not "is the predicate right" but "did all thirteen call sites ask it".
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Filament')));

    foreach ($it as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    $chains = 0;
    $ungated = [];

    foreach ($files as $path) {
        $source = (string) file_get_contents($path);

        // A fluent chain is the `::make()` plus the run of `->…` continuation lines under it.
        preg_match_all('/Export(?:Bulk)?Action::make\(\)\s*\n(?:\s*->[^\n]*\n)*/', $source, $matches);

        foreach ($matches[0] as $chain) {
            $chains++;

            // BOTH, per the project invariant: `visible()` is the UI and `authorize()` is the gate.
            // Naming one predicate in both is what stops them drifting.
            if (! str_contains($chain, '->visible(') || ! str_contains($chain, '->authorize(')) {
                $ungated[] = str_replace(base_path().'/', '', $path);
            }
        }
    }

    // Assert the sweep found something before reporting on it. A gate that has silently stopped
    // collecting reports a clean bill of health on an empty set — this project has shipped that
    // exact failure three times.
    expect($chains)->toBeGreaterThanOrEqual(13);
    expect(implode(', ', array_unique($ungated)))->toBe('');
});
