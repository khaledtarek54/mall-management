<?php

use App\Filament\Admin\RelationManagers\CamAllocationsRelationManager;
use App\Filament\Admin\Resources\CamExpensePools\Pages\EditCamExpensePool;
use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Before billing a CAM year-end, the operator can see what the batch adds up to (UX5-01).
 *
 * Every participant's working was already on this tab — share, allocated, capped cost, cap
 * absorbed, estimate paid, true-up, fee — and each row has a breakdown modal. What was missing was
 * the bottom line: only `cap_absorbed_amount` carried a summarizer, so a thirty-nine tenant pool
 * showed thirty-nine workings and no total. That is the one question asked before committing.
 *
 * Σ allocated is also the recovery identity's left side — it plus the landlord's share (shown on
 * the pool, split into vacancy and caps) is the actual expense — so it is the figure that says
 * whether the apportionment spent the pool.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('totals the four money columns an operator reads before billing', function () {
    $pool = CamExpensePool::create([
        'asset_id' => $this->asset->id,
        'name' => 'Workbench pool',
        'period_year' => 2032,
        'total_actual_expense' => 100000,
        'total_estimated_collected' => 0,
        'status' => 'draft',
        'estimate_basis' => 'stated',
        'recovery_vat_rate' => 14,
        'admin_fee_pct' => 0,
    ]);

    // One allocation per lease per pool — the table says so with a unique index, which is right:
    // a participant has ONE share of a year's pool.
    foreach ([[60000.0, 50000.0, 500.0], [40000.0, 45000.0, 250.0]] as $i => [$allocated, $paid, $fee]) {
        CamAllocation::create([
            'cam_expense_pool_id' => $pool->id,
            'lease_id' => makeLease(makeUnit($this->asset), makeTenant(), ['status' => 'active'])->id,
            'pro_rata_share_pct' => $i === 0 ? 60 : 40,
            'allocated_amount' => $allocated,
            'capped_cost_amount' => $allocated,
            'estimated_paid' => $paid,
            'true_up_amount' => $allocated - $paid,
            'admin_fee_amount' => $fee,
            'status' => 'pending',
        ]);
    }

    $summaries = asTenant($this->asset, function () use ($pool) {
        $table = Livewire::test(CamAllocationsRelationManager::class, [
            'ownerRecord' => $pool->fresh(),
            'pageClass' => EditCamExpensePool::class,
        ])->instance()->getTable();

        $out = [];
        foreach (['allocated_amount', 'estimated_paid', 'true_up_amount', 'admin_fee_amount'] as $column) {
            $out[$column] = $table->getColumn($column)?->getSummarizers() ?? [];
        }

        return $out;
    });

    // Each of the four carries a summarizer — the claim, asserted per column so a fix that
    // totalled one of them would not read as totalling the batch.
    foreach ($summaries as $column => $summarizers) {
        expect($summarizers)->not->toBeEmpty("the `{$column}` column shows no total");
    }
});

it('sums the whole pool, not the page', function () {
    // A summarizer that totalled only the visible page would be worse than none on a
    // thirty-nine-tenant pool: it would look like an answer and be one for ten rows.
    $pool = CamExpensePool::create([
        'asset_id' => $this->asset->id,
        'name' => 'Wide pool', 'period_year' => 2033,
        'total_actual_expense' => 100000, 'total_estimated_collected' => 0,
        'status' => 'draft', 'estimate_basis' => 'stated',
        'recovery_vat_rate' => 14, 'admin_fee_pct' => 0,
    ]);

    // More rows than a page holds, each 1,000 — so a page-only total would read 10,000-ish and
    // the real answer is 30,000. A lease apiece, per the unique index.
    for ($i = 0; $i < 30; $i++) {
        CamAllocation::create([
            'cam_expense_pool_id' => $pool->id,
            'lease_id' => makeLease(makeUnit($this->asset), makeTenant(), ['status' => 'active'])->id,
            'pro_rata_share_pct' => 1,
            'allocated_amount' => 1000,
            'capped_cost_amount' => 1000,
            'estimated_paid' => 0,
            'true_up_amount' => 1000,
            'admin_fee_amount' => 0,
            'status' => 'pending',
        ]);
    }

    $total = asTenant($this->asset, function () use ($pool) {
        $table = Livewire::test(CamAllocationsRelationManager::class, [
            'ownerRecord' => $pool->fresh(),
            'pageClass' => EditCamExpensePool::class,
        ])->instance()->getTable();

        $summarizer = $table->getColumn('allocated_amount')->getSummarizers()['total'];

        return (float) $summarizer->getState();
    });

    expect(round($total, 2))->toBe(30000.00);
});
