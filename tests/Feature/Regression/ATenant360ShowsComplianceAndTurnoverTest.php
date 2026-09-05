<?php

use App\Filament\Admin\RelationManagers\TenantSalesDeclarationsRelationManager;
use App\Filament\Admin\RelationManagers\TenantViolationsRelationManager;
use App\Filament\Admin\Resources\Tenants\Pages\EditTenant;
use App\Models\Violation;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * The tenant 360 answers "have they been a problem?" and "is their turnover growing?" (UX5-08).
 *
 * Both were reachable only by filtering their own registers by hand — a compliance history is a
 * commercial fact about a tenancy, and turnover across a retailer's units is the question a
 * percentage-rent lease is renewed on.
 *
 * The half worth testing is the GATING. A tenant record is opened by roles that hold nothing in
 * either module, and a tab that 403s on click is worse than a tab that is not there.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->lease = makeLease(makeUnit($this->asset), makeTenant(), ['status' => 'active']);
    $this->tenant = $this->lease->tenant;

    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('shows a violation raised against this tenant on their own record', function () {
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));

    $violation = Violation::create([
        'tenant_id' => $this->tenant->id,
        'asset_id' => $this->asset->id,
        'category' => 'signage',
        'description' => 'Unapproved signage on the shopfront',
        'fine_amount' => 5000,
        'violation_date' => now()->subDays(3)->toDateString(),
        'status' => 'open',
    ]);

    asTenant($this->asset, function () use ($violation) {
        Livewire::test(TenantViolationsRelationManager::class, [
            'ownerRecord' => $this->tenant->fresh(),
            'pageClass' => EditTenant::class,
        ])->assertCanSeeTableRecords([$violation]);
    });
});

it('hides both tabs from a role that holds neither module', function () {
    // `marketing` holds no violations and no tenant-sales rights. Asked through Filament's own
    // per-record question, which is what the record page calls.
    $this->actingAs(makeUser('marketing', [$this->asset->id]));

    expect(TenantViolationsRelationManager::canViewForRecord($this->tenant, EditTenant::class))->toBeFalse()
        ->and(TenantSalesDeclarationsRelationManager::canViewForRecord($this->tenant, EditTenant::class))->toBeFalse();
});

it('shows the compliance tab to a role that does hold it — the control', function () {
    // Without this, the refusal above passes just as happily on a tab nobody ever sees.
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));

    expect(TenantViolationsRelationManager::canViewForRecord($this->tenant, EditTenant::class))->toBeTrue();
});

it('offers the turnover tab only to a tenant who actually owes a declaration', function () {
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));

    // Asked of the LEASES, not of existing rows: a tenant who owes turnover and has not yet
    // reported must still get the tab, or it hides exactly when the chase matters.
    $this->lease->update(['has_percentage_rent' => false, 'requires_sales_reporting' => false]);
    expect(TenantSalesDeclarationsRelationManager::canViewForRecord($this->tenant->fresh(), EditTenant::class))->toBeFalse();

    $this->lease->update(['requires_sales_reporting' => true]);
    expect(TenantSalesDeclarationsRelationManager::canViewForRecord($this->tenant->fresh(), EditTenant::class))->toBeTrue();
});

it('links "Record violation" at a URL that actually resolves', function () {
    // **The bug this file shipped and did not catch.** The header action was built with
    // `getUrl('create', ['tenant' => $id])`, and `tenant` is Filament's own TENANCY route
    // parameter — so the tenant's id went into the path where the mall's slug belongs
    // (`/admin/2/violations/create`) and the page 404'd. CLAUDE.md records this exact trap from
    // `CreatePayment`; the first version of this test asserted the tab rendered and the rows were
    // visible, which is true of a tab whose only button is broken.
    //
    // So: take the URL the action really produces and FOLLOW it.
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));

    $url = asTenant($this->asset, function () {
        $manager = Livewire::test(TenantViolationsRelationManager::class, [
            'ownerRecord' => $this->tenant->fresh(),
            'pageClass' => EditTenant::class,
        ])->instance();

        $actions = $manager->getTable()->getHeaderActions();
        $record = collect($actions)->first(fn ($a) => $a->getName() === 'record');

        expect($record)->not->toBeNull('the tab offers no "Record violation" action at all');

        return $record->getUrl();
    });

    // The tenant id belongs in the QUERY, never in the tenancy segment of the path — that
    // substitution is precisely what produced `/admin/2/violations/create`.
    $path = parse_url($url, PHP_URL_PATH);

    expect($path)->not->toBe('/admin/'.$this->tenant->getKey().'/violations/create')
        ->and($url)->toContain('for_tenant='.$this->tenant->getKey());

    // And the answer that settles it whatever the slug happens to be: the link opens.
    $this->get($url)->assertSuccessful();
});

it('opens that form with the tenant already chosen', function () {
    // The prefill is the whole reason the link carries an id; a link that resolves and fills
    // nothing would pass the test above and still leave the operator searching for the tenant
    // they just came from.
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));

    asTenant($this->asset, function () {
        Livewire::withQueryParams(['for_tenant' => $this->tenant->getKey()]);

        Livewire::test(\App\Filament\Admin\Resources\Violations\Pages\CreateViolation::class)
            ->assertFormSet(['tenant_id' => $this->tenant->getKey()]);
    });
});
