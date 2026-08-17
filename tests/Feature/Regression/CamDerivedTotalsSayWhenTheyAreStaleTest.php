<?php

/*
|--------------------------------------------------------------------------
| A derived total that was never sourced must say so (2026-08-17)
|--------------------------------------------------------------------------
| `expense_basis = ledger` and `estimate_basis = billed` both mean "this column is computed from
| documents, not typed" — and the computation only happens when someone runs Sync from ledger.
| Until then the column holds whatever it was created with, and `variance()` subtracts it anyway.
|
| Reproduced on a real pool: `estimate_basis = billed`, `total_estimated_collected = 0`, while the
| three participating tenants had been invoiced 346,000 of service charge. The list reported
|
|     Actual 500,000 · Estimated collected 0 · Variance 500,000
|
| against a true variance of 154,000. The ALLOCATIONS were right the whole time — they derive each
| lease's estimate from its own invoices — so only the header lied, which is the harder kind of wrong
| to notice. Nothing on any screen said the figure had never been sourced: `expense_synced_at` was
| null and appeared on neither the list nor the form.
|
| Caught by an operator's instinct that the pool "wasn't configured right", which is not a detection
| mechanism anyone should have to rely on.
*/

use App\Models\CamExpensePool;
use App\Services\SyncCamPoolFromLedgerService;

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
});

function sourcingPool($ctx, array $overrides = []): CamExpensePool
{
    return CamExpensePool::create(array_merge([
        'asset_id' => $ctx->asset->id,
        'period_year' => 2026,
        'pool_code' => 'cam',
        'total_actual_expense' => 500000,
        'total_estimated_collected' => 0,
        'estimate_basis' => CamExpensePool::BASIS_BILLED,
        'status' => 'draft',
    ], $overrides));
}

it('flags a derived pool that has never been sourced', function () {
    expect(sourcingPool($this)->needsSourcing())->toBeTrue();
});

it('stops flagging once the sync has actually run', function () {
    $pool = sourcingPool($this);

    // The real path writes `expense_synced_at`; that timestamp IS the answer to "has this been
    // derived yet", which is why the flag reads it rather than guessing from the value.
    $lease = makeLease(makeUnit($this->asset, ['area_sqm' => 100]), makeTenant(), [
        'status' => 'active', 'commencement_date' => '2026-01-01', 'expiry_date' => '2028-12-31',
    ]);
    makeInvoice($lease, ['issue_date' => '2026-03-01', 'period_start' => '2026-03-01', 'period_end' => '2026-03-31'])
        ->items()->create([
            'type' => 'service_charge', 'description' => 'Service charge',
            'amount' => 12000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 12000,
        ]);

    app(SyncCamPoolFromLedgerService::class)->sync($pool);

    expect($pool->fresh()->needsSourcing())->toBeFalse()
        ->and($pool->fresh()->expense_synced_at)->not->toBeNull();
});

it('never flags a pool whose totals are TYPED — there is nothing to source', function () {
    // `stated` on both bases means the operator owns the figure. Warning here would train people to
    // ignore the warning, which is how a real one gets missed.
    $stated = sourcingPool($this, [
        'estimate_basis' => CamExpensePool::BASIS_STATED,
        'expense_basis' => CamExpensePool::BASIS_STATED,
    ]);

    expect($stated->needsSourcing())->toBeFalse()
        ->and($stated->isDerived())->toBeFalse();
});

it('agrees with the Sync action about which pools are derived', function () {
    // The warning and the action that resolves it must answer to ONE definition. Warning about a
    // pool the Sync action would not even offer is a dead end for the operator.
    $derived = sourcingPool($this);
    $typed = sourcingPool($this, ['period_year' => 2025, 'estimate_basis' => CamExpensePool::BASIS_STATED]);

    expect($derived->needsSourcing())->toBe($derived->isDerived())
        ->and($typed->isDerived())->toBeFalse()
        ->and($typed->needsSourcing())->toBeFalse();
});

it('leaves the variance arithmetic alone — the flag reports, it does not correct', function () {
    $pool = sourcingPool($this);

    // Deliberately NOT "variance() returns the derived answer". The figure stays what the column
    // says; the flag exists so nobody trusts it before it has been sourced. Silently substituting a
    // computed value would make the stored column and the screen disagree, which is a second bug.
    expect($pool->variance())->toBe(500000.0)
        ->and($pool->needsSourcing())->toBeTrue();
});
