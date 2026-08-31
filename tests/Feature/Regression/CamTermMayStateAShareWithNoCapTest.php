<?php

use App\Filament\Admin\RelationManagers\LeaseCamTermsRelationManager;
use App\Filament\Admin\Resources\Leases\Pages\EditLease;
use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Models\LeaseCamTerm;
use App\Services\CamReconciliationService;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Regression: A CAM TERM MAY STATE A SHARE WITHOUT STATING A CAP — FROM THE PANEL.
 *
 * `lease_cam_terms.cap_type` was made nullable on 2026-08-23 precisely so a term could carry only
 * `stated_share_pct`, the percentage an Egyptian lease often simply names. The service honours it
 * and `CamDenominatorTest` pins the arithmetic — but the RELATION MANAGER kept `->required()` on
 * `cap_type` and offered exactly `absolute|yoy|both`, so the row the table exists to hold could not
 * be written from the only screen that writes it. Measured: the create action came back with
 * `mountedActions.0.data.cap_type` required.
 *
 * That is worse than a missing feature. To record "your share is 8%" the operator had to pick a cap
 * type and fill its now-mandatory figures (`LeaseCamTerm::REQUIRED_BY_TYPE`) — inventing a ceiling
 * that then really bites, on the tenant-facing recovery invoice. The shape CLAUDE.md records as
 * reachable-but-not-configurable, with a wrong number at the end of it rather than a dead screen.
 *
 * Null is also the only way to END a cap: a later term stating none supersedes an earlier ceiling
 * from its own year on, which `camTermFor()` resolves by effective date. Deleting the old term
 * cannot express that — it would uncap the years already reconciled under it too.
 *
 * Every refusal here is paired with a control that must succeed: a form that saved nothing would
 * satisfy "no error" just as happily.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'CAM-NOCAP']);
    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);

    $this->manager = fn ($lease) => Livewire::test(LeaseCamTermsRelationManager::class, [
        'ownerRecord' => $lease,
        'pageClass' => EditLease::class,
    ]);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('saves a stated share with no cap type through the real relation manager', function () {
    $lease = makeLease(makeUnit($this->asset), makeTenant());

    ($this->manager)($lease)
        ->callTableAction('create', data: [
            'effective_year' => 2025,
            'cap_type' => null,
            'stated_share_pct' => 8,
        ])
        ->assertHasNoTableActionErrors();

    $term = LeaseCamTerm::where('lease_id', $lease->id)->sole();

    expect($term->cap_type)->toBeNull()
        ->and((float) $term->stated_share_pct)->toBe(8.0)
        // The whole point: it states a share and imposes no ceiling.
        ->and($term->resolveCeiling(2025))->toBeNull()
        ->and($lease->fresh()->statedCamSharePct(2025))->toBe(8.0);
});

it('still refuses a cap type whose own figures are missing', function () {
    // The control on the other side. Making cap_type optional must not make an INCOMPLETE cap
    // saveable — that is the defect `LeaseCamTerm::REQUIRED_BY_TYPE` exists to stop, and a cap
    // that resolves to nothing while the lease shows a cap term is worse than no cap at all.
    $lease = makeLease(makeUnit($this->asset), makeTenant());

    ($this->manager)($lease)
        ->callTableAction('create', data: [
            'effective_year' => 2025,
            'cap_type' => 'absolute',
            'cap_absolute_amount' => null,
        ])
        ->assertHasTableActionErrors();

    expect(LeaseCamTerm::where('lease_id', $lease->id)->count())->toBe(0);
});

it('renders a no-cap term in the table rather than blanking or throwing', function () {
    // A term with no ceiling SUPERSEDES an earlier one, so the cell has to read as a decision.
    $lease = makeLease(makeUnit($this->asset), makeTenant());
    LeaseCamTerm::create([
        'lease_id' => $lease->id,
        'effective_year' => 2025,
        'cap_type' => null,
        'stated_share_pct' => 8,
    ]);

    ($this->manager)($lease)
        ->assertSuccessful()
        ->assertCanSeeTableRecords(LeaseCamTerm::where('lease_id', $lease->id)->get())
        ->assertSee(__('admin.lease_cam_terms.no_cap'));
});

it('lets a later no-cap term END an earlier ceiling from its own year on', function () {
    $lease = makeLease(makeUnit($this->asset), makeTenant());

    $lease->camTerms()->create([
        'effective_year' => 2025,
        'cap_type' => 'absolute',
        'cap_absolute_amount' => 20_000,
    ]);
    $lease->camTerms()->create([
        'effective_year' => 2027,
        'cap_type' => null,
    ]);

    $lease = $lease->fresh();

    expect($lease->resolveCamCeiling(2025))->toBe(20_000.0)
        // 2026 still has no term of its own, so the 2025 ceiling still governs it.
        ->and($lease->resolveCamCeiling(2026))->toBe(20_000.0)
        // From 2027 the later term supersedes it and there is no ceiling.
        ->and($lease->resolveCamCeiling(2027))->toBeNull()
        ->and($lease->resolveCamCeiling(2028))->toBeNull();
});

it('bills a no-cap stated share in full, and the pool still ties out', function () {
    // End to end through the real service: the share is honoured, nothing is absorbed, and
    // Σ allocated + unrecovered = actual — the invariant every clause here must leave standing.
    // The term has to span the pool's year — `makeLease` commences 2026 by default and a lease
    // that never traded in 2025 is not a participant, which is the whole point of day-weighting.
    $span = ['commencement_date' => '2024-01-01', 'expiry_date' => '2029-12-31'];
    $tenantA = makeLease(makeUnit($this->asset, ['area_sqm' => 100]), makeTenant(), $span);
    $tenantB = makeLease(makeUnit($this->asset, ['area_sqm' => 100]), makeTenant(), $span);

    $tenantA->camTerms()->create([
        'effective_year' => 2025,
        'cap_type' => null,
        'stated_share_pct' => 30,
    ]);

    $pool = CamExpensePool::create([
        'asset_id' => $this->asset->id,
        'period_year' => 2025,
        'pool_code' => CamExpensePool::CODE_CAM,
        'status' => 'draft',
        'total_actual_expense' => 100_000,
        'total_estimated_collected' => 0,
        'expense_basis' => 'stated',
        'estimate_basis' => 'stated',
        'admin_fee_pct' => 0,
    ]);

    app(CamReconciliationService::class)->generateAllocations($pool);

    $a = CamAllocation::where('cam_expense_pool_id', $pool->id)->where('lease_id', $tenantA->id)->sole();
    $b = CamAllocation::where('cam_expense_pool_id', $pool->id)->where('lease_id', $tenantB->id)->sole();

    expect((float) $a->pro_rata_share_pct)->toBe(30.0)
        ->and((float) $a->allocated_amount)->toBe(30_000.0)
        // No ceiling, so the landlord absorbs nothing off this lease.
        ->and($a->cap_amount)->toBeNull()
        ->and((float) $a->cap_absorbed_amount)->toBe(0.0)
        ->and((float) $a->capped_cost_amount)->toBe(30_000.0)
        // The neighbour keeps its OWN area share — a stated share never re-cuts anybody else.
        ->and((float) $b->pro_rata_share_pct)->toBe(50.0);

    $pool->refresh();

    expect(round(
        (float) $pool->allocations()->sum('allocated_amount') + (float) $pool->landlord_unrecovered_amount,
        2
    ))->toBe(100_000.0);
});
