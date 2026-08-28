<?php

/*
|--------------------------------------------------------------------------
| A row that binds every mall may only be written by someone who holds every mall
|--------------------------------------------------------------------------
| Two registers allow a NULL `asset_id` meaning "every property" rather than "no property": the
| working-calendar `holidays` register and `departments`. Both shipped a guard that read only the
| SUBMITTED value, and both got the "may I write portfolio-wide?" test backwards.
|
| **The one-directional hole.** `HolidayResource::getEloquentQuery()` deliberately shows a restricted
| admin the national rows. So a `mall_admin` pinned to Mall A could open the national Eid row, set
| Property = Mall A, and save — the submitted value is in scope, the guard passes, and Malls B, C
| and D silently lose the date from their working calendars, taking their SLA deadlines and (once
| the working clock is on) their penalty amounts with it. Taking a date AWAY from a mall is a write
| to that mall. `UserResource::enforceGrantableAssetsRule()` — cited by name in the guard's own
| docblock — reverts both directions; the code implemented half of it.
|
| **The inverted test.** The portfolio-wide check was `AssignedAssets::idsForCurrentUser() === null`.
| Null means super_admin or a never-scoped user, so it refused everyone WITH an assignment —
| including a user assigned to every property, which is exactly the condition it is meant to grant.
| The panel produces that state by default (`UserForm` pre-selects every property the grantor
| holds), so assigning staff to the malls they run silently removed a right they had, and in a
| single-mall deployment it refused everyone but super_admin — while the field's helper text and the
| screen guide both say "leave blank for a national holiday".
|
| Every refusal below is paired with a control that must SUCCEED. A guard that refuses everyone
| satisfies the refusals on its own and reads as a pass.
*/

use App\Filament\Admin\Resources\Departments\DepartmentResource;
use App\Filament\Admin\Resources\Holidays\HolidayResource;
use App\Filament\Admin\Resources\Tenants\Pages\EditTenant;
use App\Services\TenantStatementPdfService;
use App\Support\Pdf\DocumentLocale;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    // The role catalogue: without it every actingAs throws RoleDoesNotExist, and a test that
    // errors is not a test that refuses.
    $this->seed(RolesPermissionsSeeder::class);

    $this->mine = makeAsset(['name' => 'Mall A']);
    $this->theirs = makeAsset(['name' => 'Mall B']);

    // The Livewire case below mounts a real admin page, which needs the panel and its property
    // route parameter. Harmless for the pure-guard cases above.
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->mine, isQuiet: true);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('refuses to re-home a national holiday onto one mall', function () {
    $this->actingAs(makeUser('mall_admin', [$this->mine->id]));

    // Submitted = my own mall, which passes a one-directional guard cleanly. The ORIGINAL is the
    // portfolio, and moving the row off it deletes the date for every other property.
    expect(fn () => HolidayResource::assertMayWrite($this->mine->id, null))
        ->toThrow(DomainException::class);
});

it('lets that same admin write a holiday for their own mall — the control', function () {
    $this->actingAs(makeUser('mall_admin', [$this->mine->id]));

    // Identical actor, identical submitted value. Only the original differs, so the refusal above
    // is about the move and not about the person.
    HolidayResource::assertMayWrite($this->mine->id, $this->mine->id);

    expect(true)->toBeTrue();
});

it('lets an admin who holds EVERY mall write a national holiday', function () {
    // The inverted test refused this user: they have an assignment, so `idsForCurrentUser()` was
    // not null. Holding every property IS "can see every mall" — and it is what the user form
    // produces by default.
    $this->actingAs(makeUser('mall_admin', [$this->mine->id, $this->theirs->id]));

    HolidayResource::assertMayWrite(null, null);

    expect(true)->toBeTrue();
});

it('still refuses a national holiday from an admin who holds only some malls', function () {
    // The control for the case above: with a mall they cannot see, the right is withheld. Two
    // properties exist and they hold one.
    $this->actingAs(makeUser('mall_admin', [$this->mine->id]));

    expect(fn () => HolidayResource::assertMayWrite(null, null))
        ->toThrow(DomainException::class);
});

it('lets a super_admin write a national holiday while working inside one mall', function () {
    // Measured against `AssignedAssets`, not `TenantScope::visibleAssetIds()` — the latter
    // collapses to the SELECTED property, which would refuse this.
    $this->actingAs(makeUser('super_admin'));

    HolidayResource::assertMayWrite(null, null);

    expect(true)->toBeTrue();
});

it('guards the department register the same way, because it has the same shape', function () {
    $this->actingAs(makeUser('mall_admin', [$this->mine->id]));

    // A global department every mall routes requests to, re-homed onto one property.
    expect(fn () => DepartmentResource::assertMayWriteAcrossPortfolio(
        $this->mine->id, null, 'admin.errors.department_needs_every_property',
    ))->toThrow(DomainException::class);

    // The control: their own mall's department, untouched.
    DepartmentResource::assertMayWriteAcrossPortfolio(
        $this->mine->id, $this->mine->id, 'admin.errors.department_needs_every_property',
    );

    expect(true)->toBeTrue();
});

it('scopes the tenant statement downloaded from the Edit page', function () {
    // A different register, the same class of miss: `TenantsTable` and `ArCollections` both pass
    // `TenantScope::visibleAssetIds()` to the statement service, each with a comment saying why —
    // and the Edit page's identically-labelled button did not. Every filter in
    // `TenantStatementPdfService::data()` is `->when($visibleAssetIds !== null, …)`, so null is
    // UNRESTRICTED: a tenant leasing in two malls is legitimately on either mall's register, and a
    // Mall-A operator's download then listed Mall B's invoices, payments, credit notes and rollups.
    // `leasing`, `manager` and `mall_admin` all hold `tenants.view` and can all be property-pinned.
    //
    // Asserted on the ARGUMENT rather than the PDF: mpdf returns a binary blob, and a test that
    // greps it would pass for whatever reason the bytes happened to differ.
    $tenant = makeTenant(['asset_id' => $this->mine->id]);

    $this->actingAs(makeUser('mall_admin', [$this->mine->id]));

    $spy = Mockery::mock(TenantStatementPdfService::class);
    $spy->shouldReceive('build')->once()->with(
        Mockery::on(fn ($subject) => $subject->is($tenant)),
        // The scope, present and narrowed to the one mall this operator holds.
        Mockery::on(fn ($scope) => $scope === [$this->mine->id]),
        // `from` and `to`: this call site states no window, so the statement takes its own default.
        null,
        null,
        // The LANGUAGE the document is written in (2026-08-27). Matched rather than waved through
        // with `Mockery::any()`: an unsupported value here would render the document in the fallback
        // locale silently, which is the exact failure `DocumentLocale` clamps for — and a spy that
        // accepted anything would not notice the day this call site stops resolving it.
        Mockery::on(fn ($locale) => in_array($locale, DocumentLocale::supported(), true)),
    )->andReturn('%PDF-1.4 stub');
    $spy->shouldReceive('filename')->andReturn('statement.pdf');
    app()->instance(TenantStatementPdfService::class, $spy);

    Livewire::test(EditTenant::class, [
        'record' => $tenant->getRouteKey(),
    ])->callAction('statement');
});
