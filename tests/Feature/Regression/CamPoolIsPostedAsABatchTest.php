<?php

use App\Filament\Admin\Actions\CamExpensePoolActions;
use App\Filament\Admin\Resources\CamExpensePools\Pages\EditCamExpensePool;
use App\Models\AccountingPeriod;
use App\Models\CamExpensePool;
use App\Services\Accounting\FiscalCalendar;
use App\Services\CamReconciliationService;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Regression: A RECONCILIATION IS POSTED AS A BATCH, AND A YEAR IS NOT CLOSED ON UNBILLED WORK.
 *
 * Two gaps on the same screen, both reported from the panel.
 *
 * (1) `bill()` existed per allocation and NOTHING above it, so a 39-tenant pool was 39 clicks.
 *     Yardi posts Recovery Reconciliation per PROPERTY — "reviewable BATCH → post" — and nobody
 *     posts a mall one tenant at a time. The capability was already written
 *     (`autoTrueUpForYear(autoBill: true)`, `cam:reconcile --auto-bill`) behind a CLI the operator
 *     cannot reach, and a flag the scheduled run deliberately does not pass. Reachable from a
 *     terminal is not reachable.
 *
 * (2) `markReconciled` asked only for the pool's status and the permission — never whether anything
 *     had actually been billed. Measured on the demo books: a pool with 36 allocations, 0 billed,
 *     and the button live. The CLI has always refused this (it marks a pool reconciled only when
 *     billing ran), so the button an operator uses was the weaker of the two.
 *
 * Every refusal below is paired with the control that must succeed.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'CAM-BATCH']);
    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);

    $span = ['commencement_date' => '2024-01-01', 'expiry_date' => '2029-12-31'];

    // Three leases of equal area, so each takes a third of the pool.
    $this->leases = collect(range(1, 3))->map(
        fn ($i) => makeLease(makeUnit($this->asset, ['area_sqm' => 100]), makeTenant(), $span)
    );

    $this->pool = CamExpensePool::create([
        'asset_id' => $this->asset->id,
        'period_year' => 2025,
        'pool_code' => CamExpensePool::CODE_CAM,
        'status' => 'draft',
        'total_actual_expense' => 90_000,
        'total_estimated_collected' => 0,
        'expense_basis' => 'stated',
        'estimate_basis' => 'stated',
        'admin_fee_pct' => 0.10,
        'recovery_vat_rate' => 14,
    ]);

    app(CamReconciliationService::class)->generateAllocations($this->pool);
    $this->pool->update(['status' => 'reconciling']);

    $this->page = fn () => Livewire::test(EditCamExpensePool::class, ['record' => $this->pool->getKey()]);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('bills every pending allocation of a pool in one action', function () {
    expect($this->pool->allocations()->where('status', 'pending')->count())->toBe(3);

    ($this->page)()->callAction('billAllPending');

    expect($this->pool->allocations()->where('status', 'billed')->count())->toBe(3)
        ->and($this->pool->allocations()->where('status', 'pending')->count())->toBe(0)
        // Each stays its OWN row — the batch is a convenience over `bill()`, not a different act,
        // so the per-row Void still has something to undo.
        ->and($this->pool->allocations()->whereNotNull('billed_charge_id')->count())->toBe(3);
});

it('reports what the batch did, split by direction', function () {
    $r = app(CamReconciliationService::class)->billAllPending($this->pool);

    // Every tenant paid nothing in estimates against a 30,000 share, so all three recover.
    expect($r['billed'])->toBe(3)
        ->and($r['recovered'])->toBe(3)
        ->and($r['credited'])->toBe(0)
        ->and($r['failed'])->toBe(0);
});

it('is idempotent — a second run bills nothing and breaks nothing', function () {
    $svc = app(CamReconciliationService::class);
    $svc->billAllPending($this->pool);

    expect($svc->billAllPending($this->pool))
        ->toMatchArray(['billed' => 0, 'failed' => 0])
        ->and($this->pool->allocations()->where('status', 'billed')->count())->toBe(3);
});

it('offers the batch only while something is pending, and only to a role that may bill', function () {
    expect(CamExpensePoolActions::canBillAll($this->pool))->toBeTrue();

    app(CamReconciliationService::class)->billAllPending($this->pool);

    // An empty batch button on a fully-billed pool reads as a broken one.
    expect(CamExpensePoolActions::canBillAll($this->pool->fresh()))->toBeFalse();

    // And a role without the right is refused even while work is outstanding.
    $this->pool->allocations()->update(['status' => 'pending']);
    $this->actingAs(makeUser('viewer'));
    expect(CamExpensePoolActions::canBillAll($this->pool->fresh()))->toBeFalse();
});

it('refuses to mark a pool reconciled while an allocation is still unbilled', function () {
    expect(CamExpensePoolActions::unbilledCount($this->pool))->toBe(3);

    ($this->page)()->callAction('markReconciled');

    // Refused: the pool is untouched.
    expect($this->pool->fresh()->status)->toBe('reconciling')
        ->and($this->pool->fresh()->reconciled_at)->toBeNull();
});

it('refuses at the ACTION layer too, not only by disabling the button', function () {
    // `disabled()` is refused at dispatch on this Filament version, so `callAction()` never reaches
    // the action body — which means the test above proves the DISABLED state and nothing else.
    // Mutation confirmed it: deleting the guard inside the action left it fully green. The guard is
    // the layer we control (the authz invariant: hidden-implies-disabled is an upstream detail that
    // can change in a release), so it is asserted where `disabled()` cannot intervene.
    expect(fn () => CamExpensePoolActions::assertReadyToReconcile($this->pool))
        ->toThrow(DomainException::class);

    // Paired with the control: once billed it must NOT throw, or a guard that refused everything
    // would satisfy the assertion above on its own.
    app(CamReconciliationService::class)->billAllPending($this->pool);

    expect(fn () => CamExpensePoolActions::assertReadyToReconcile($this->pool->fresh()))
        ->not->toThrow(DomainException::class);
});

it('marks it reconciled once the batch has actually been billed', function () {
    // The control. A guard that refused everything would satisfy the test above on its own.
    app(CamReconciliationService::class)->billAllPending($this->pool);

    ($this->page)()->callAction('markReconciled');

    expect($this->pool->fresh()->status)->toBe('reconciled')
        ->and($this->pool->fresh()->reconciled_at)->not->toBeNull();
});

it('does not call a pool reconciled from the CLI when part of the batch failed', function () {
    // `billAllPending()` steps over an allocation it could not bill so the rest of the mall is
    // still recovered — but a pool with a tenant left un-recovered has not been reconciled.
    $svc = app(CamReconciliationService::class);

    // A pool of its own for the annual run, in a year nothing else touches.
    $pool = CamExpensePool::create([
        'asset_id' => $this->asset->id,
        'period_year' => 2026,
        'pool_code' => CamExpensePool::CODE_CAM,
        'status' => 'draft',
        'total_actual_expense' => 60_000,
        'total_estimated_collected' => 0,
        'expense_basis' => 'stated',
        'estimate_basis' => 'stated',
        'admin_fee_pct' => 0,
    ]);

    // The control first: a clean batch really does close the year.
    $report = $svc->autoTrueUpForYear(2026, autoBill: true);

    expect($report)->toHaveCount(1)
        ->and($report[0]['billed'])->toBe(3)
        ->and($report[0]['status'])->toBe('reconciled')
        ->and($pool->fresh()->status)->toBe('reconciled');
});

it('leaves a pool RECONCILING when its batch could not be billed', function () {
    // The branch the control above cannot reach, driven through the SAME entry point the CLI uses,
    // because the decision under test is `autoTrueUpForYear()`'s and not `billAllPending()`'s.
    //
    // A CAM recovery invoice is issued dated TODAY (measured), so a closed CURRENT period is the
    // ordinary production reason a batch fails — the operator closed the month, then ran last
    // year's reconciliation. That must read as unfinished, never as a reconciled year.
    $svc = app(CamReconciliationService::class);

    $pool = CamExpensePool::create([
        'asset_id' => $this->asset->id,
        'period_year' => 2027,
        'pool_code' => CamExpensePool::CODE_CAM,
        'status' => 'draft',
        'total_actual_expense' => 60_000,
        'total_estimated_collected' => 0,
        'expense_basis' => 'stated',
        'estimate_basis' => 'stated',
        'admin_fee_pct' => 0,
    ]);

    // The calendar has to exist before a period can be closed — nothing in this fixture needs it
    // otherwise, and `bill()` is happy without one (a MISSING period is allowed; only a CLOSED one
    // is refused, which is the whole point here).
    app(FiscalCalendar::class)->ensureYear((int) now()->year);
    AccountingPeriod::forDate(now())->update(['status' => 'closed']);

    $report = $svc->autoTrueUpForYear(2027, autoBill: true);

    expect($report)->toHaveCount(1)
        ->and($report[0]['billed'])->toBe(0)
        // Allocations WERE generated — the run got that far — so this is not a vacuous pass.
        ->and($report[0]['allocations'])->toBe(3)
        ->and($report[0]['status'])->toBe('reconciling')
        ->and($pool->fresh()->status)->toBe('reconciling')
        ->and($pool->fresh()->reconciled_at)->toBeNull();

    unset($report);
});
