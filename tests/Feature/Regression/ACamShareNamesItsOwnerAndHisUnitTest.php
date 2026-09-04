<?php

use App\Enums\UnitOwnershipStatus;
use App\Filament\Admin\RelationManagers\CamAllocationsRelationManager;
use App\Filament\Portal\Resources\CamAllocations\Pages\ViewCamAllocation;
use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Models\Tenant;
use App\Models\UnitOwnership;
use App\Services\CamReconciliationService;
use App\Services\CamStatementPdfService;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Regression: A UNIT OWNER'S CAM SHARE NAMES HIM, AND NAMES HIS SHOP.
 *
 * A pool apportions to LEASES and to the OWNERS of sold units, and the two hold their party and
 * their unit differently — a lease has `tenant` and `unit`, an ownership has `owner` and `unit`.
 * Every reader written as `lease?->tenant ?? unitOwnership?->tenant` therefore LOOKS like it
 * handles both agreements and answers null for every owner, because `UnitOwnership` has no
 * `tenant` relation at all and an undefined relation resolves to NULL rather than throwing.
 *
 * Measured 2026-09-05 against `mall_management_qa`, `unit_ownerships#1`:
 *   owner->name = "Ashraf El-Gindy" · ->tenant = NULL · method_exists($o, 'tenant') = false
 *
 * Three readers had that shape and the worst is the document the owner audits.
 * `CamStatementPdfService::document()` carries a seven-line comment saying it was written to stop
 * an ownership statement rendering "a blank party" — and then reads the relation that does not
 * exist, so the fix has been inert since the day it shipped. `OwnerDocumentsStateTheirPartyTest`,
 * whose preamble promises "each case asserts a value only the fix can produce", asserts the area
 * and the denominator and never the party.
 *
 * The fourth reader is the one SW-165 reported: the portal's View page named the STATE PATH
 * `lease.unit.code`, and a path cannot branch, so the owner opened the screen that exists to
 * explain his true-up and read a blank unit — four lines below a `getEloquentQuery()` whose own
 * comment says his allocation has no lease.
 *
 * `CamAllocation::counterparty()` and `::unitCode()` are the two seams. Every case is paired with
 * its LEASE control: a fix that answered the ownership side by breaking the lease side would
 * satisfy the owner assertions on its own.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->asset = makeAsset(['code' => 'CAMP', 'leasable_area_sqm' => 200]);

    // The trading tenant — the control on every assertion below.
    $this->tenant = makeTenant(['name' => 'Zahra Coffee Roasters']);
    $this->lease = makeLease(
        makeUnit($this->asset, ['area_sqm' => 100, 'code' => 'LEASED-1']),
        $this->tenant,
        ['commencement_date' => '2026-01-01', 'expiry_date' => '2029-12-31'],
    );

    // The unit owner — a `tenants` row carrying an ownership, with no lease anywhere.
    $this->owner = makeTenant(['name' => 'Ashraf El-Gindy Holdings']);
    $this->ownership = UnitOwnership::create([
        'asset_id' => $this->asset->id,
        'unit_id' => makeUnit($this->asset, ['area_sqm' => 100, 'code' => 'OWNED-9'])->id,
        'tenant_id' => $this->owner->id,
        'status' => UnitOwnershipStatus::HandedOver->value,
        'started_at' => '2026-01-01',
    ]);

    $this->pool = CamExpensePool::create([
        'asset_id' => $this->asset->id,
        'period_year' => 2026,
        'pool_code' => CamExpensePool::CODE_CAM,
        'name' => 'Common area maintenance 2026',
        'status' => 'draft',
        'total_actual_expense' => 200000,
        'total_estimated_collected' => 0,
        'expense_basis' => 'stated',
        'estimate_basis' => 'stated',
        'admin_fee_pct' => 0,
    ]);

    app(CamReconciliationService::class)->generateAllocations($this->pool);

    $this->ownerShare = CamAllocation::where('cam_expense_pool_id', $this->pool->id)
        ->whereNull('lease_id')->sole();
    $this->leaseShare = CamAllocation::where('cam_expense_pool_id', $this->pool->id)
        ->where('lease_id', $this->lease->id)->sole();

    // The premise the whole file rests on, stated rather than assumed: the relation those four
    // readers named genuinely does not exist, so every one of them answered null.
    expect(method_exists($this->ownership, 'tenant'))->toBeFalse()
        ->and($this->ownership->tenant)->toBeNull()
        ->and($this->ownership->owner?->name)->toBe('Ashraf El-Gindy Holdings');

    // One portal View, opened by one party. Each case signs in for itself: swapping `actingAs()`
    // inside a single case is the trap CLAUDE.md records for the role matrix.
    $this->viewShareAs = function (Tenant $party, CamAllocation $share) {
        Filament::setCurrentPanel(Filament::getPanel('portal'));
        test()->actingAs(makeTenantUser($party), 'portal');

        return Livewire::test(ViewCamAllocation::class, ['record' => $share->getKey()]);
    };
});

afterEach(fn () => Filament::setCurrentPanel(Filament::getPanel('admin')));

it('answers the party and the unit from whichever agreement holds the share', function () {
    expect($this->ownerShare->counterparty()?->getKey())->toBe($this->owner->getKey())
        ->and($this->ownerShare->unitCode())->toBe('OWNED-9')
        // Through the ATTRIBUTE too: an infolist entry and a table column name a state path, and a
        // path cannot call a method.
        ->and($this->ownerShare->unit_code)->toBe('OWNED-9')
        // The control — the lease side answers exactly as it always did.
        ->and($this->leaseShare->counterparty()?->getKey())->toBe($this->tenant->getKey())
        ->and($this->leaseShare->unitCode())->toBe('LEASED-1');
});

it('shows a unit owner his own shop on the portal View page, not a blank cell', function () {
    ($this->viewShareAs)($this->owner, $this->ownerShare)
        ->assertSuccessful()
        ->assertSee('OWNED-9');
});

it('still shows a trading tenant the unit on that same portal page', function () {
    // The control for the case above. A page that had simply stopped rendering a unit at all would
    // satisfy nothing here, and a page that printed every code it could find would fail the scope.
    ($this->viewShareAs)($this->tenant, $this->leaseShare)
        ->assertSuccessful()
        ->assertSee('LEASED-1')
        ->assertDontSee('OWNED-9');
});

it('addresses the CAM statement to the owner rather than to nobody', function () {
    // Through the rendered HTML, not through the service's inputs: `build()` returns a binary blob,
    // so asserting `%PDF` passes just as happily with a null party — the template is null-safe, and
    // that is exactly how this document went on printing an empty party block while "covered".
    $html = app(CamStatementPdfService::class)->document($this->ownerShare->fresh())->html();

    expect($html)->toContain('Ashraf El-Gindy Holdings');

    // The control.
    $leaseHtml = app(CamStatementPdfService::class)->document($this->leaseShare->fresh())->html();

    expect($leaseHtml)->toContain('Zahra Coffee Roasters');
});

it('writes the owner his statement in HIS language, not the operator panel language', function () {
    // `DocumentLocale::resolve()` reads the RECIPIENT's stored locale, so a null party silently
    // loses that tier and the document falls through to whatever language the button was pressed
    // in. Resolved through the catalogue rather than pasted as a literal, so the assertion cannot
    // drift with the wording.
    $arabic = trans('admin.cam_statement.tenant', locale: 'ar');
    $english = trans('admin.cam_statement.tenant', locale: 'en');

    expect($arabic)->not->toBe($english, 'The premise: the two catalogues really do differ here.');

    $this->owner->update(['locale' => 'ar']);
    app()->setLocale('en');

    $html = app(CamStatementPdfService::class)->document($this->ownerShare->fresh())->html();

    expect($html)->toContain($arabic);

    // The control: a party with no stated preference still gets the operator's language, so the
    // assertion above is about the recipient rather than about a hardcoded locale.
    $plain = app(CamStatementPdfService::class)->document($this->leaseShare->fresh())->html();

    expect($plain)->toContain($english);
});

it('keeps the operator allocation table reading the same answer as the seam', function () {
    // The aggregate tooth. `CamAllocationsRelationManager` held its own copy of both rules — which
    // is precisely why the PDF and the portal could drift from it for months — so the screen and
    // the model must never be able to disagree again.
    expect(CamAllocationsRelationManager::participantName($this->ownerShare))
        ->toBe($this->ownerShare->counterparty()?->name)
        ->and(CamAllocationsRelationManager::participantUnit($this->ownerShare))
        ->toBe($this->ownerShare->unitCode())
        ->and(CamAllocationsRelationManager::participantName($this->leaseShare))
        ->toBe($this->leaseShare->counterparty()?->name)
        ->and(CamAllocationsRelationManager::participantUnit($this->leaseShare))
        ->toBe($this->leaseShare->unitCode());
});
