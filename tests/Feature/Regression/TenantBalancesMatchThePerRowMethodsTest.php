<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The batched figures must equal the per-row ones, exactly
|--------------------------------------------------------------------------
| `TenantBalances` exists so the tenants LIST stops recomputing the four settlement channels once
| per row — measured 2026-09-01 at ~125 queries for a 25-row page. It deliberately does not restate
| the rule: it runs the same filters and the same `Invoice::collectableBalanceSql()` over a SET, and
| for the credit balance it batches only the fetch and reuses the model's per-payment clamp.
|
| The only real hazard is DRIFT. A batched arrears figure that quietly disagrees with the record
| page beside it is worse than a slow list, because the list is what an operator decides from — who
| to chase first. So every figure is asserted against the model method it stands in for, on tenants
| shaped differently enough that a naive SUM would diverge.
*/

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Support\TenantBalances;
use Database\Seeders\RolesPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
});

it('agrees with the per-row methods for every tenant on the page', function () {
    $asset = makeAsset();
    $ids = [];

    // A tenant with nothing at all — the row that must come back 0/0/false rather than absent.
    $ids[] = makeTenant(['name' => 'Empty Co'])->getKey();

    // A tenant owing money, overdue.
    $overdue = makeTenant(['name' => 'Overdue Co']);
    $lease = makeLease(makeUnit($asset), $overdue);
    makeInvoice($lease, ['status' => 'issued', 'due_date' => now()->subMonth(), 'total' => 5000, 'balance' => 5000]);
    $ids[] = $overdue->getKey();

    // A tenant owing money that is NOT yet due — owes, but is not delinquent.
    $current = makeTenant(['name' => 'Current Co']);
    $lease2 = makeLease(makeUnit($asset), $current);
    makeInvoice($lease2, ['status' => 'issued', 'due_date' => now()->addMonth(), 'total' => 3000, 'balance' => 3000]);
    $ids[] = $current->getKey();

    // A tenant with an unapplied credit note, which REDUCES the outstanding figure.
    $credited = makeTenant(['name' => 'Credited Co']);
    $lease3 = makeLease(makeUnit($asset), $credited);
    makeInvoice($lease3, ['status' => 'issued', 'due_date' => now()->subWeek(), 'total' => 4000, 'balance' => 4000]);
    CreditNote::factory()->create([
        'tenant_id' => $credited->getKey(),
        'asset_id' => $asset->getKey(),
        'status' => 'issued',
        'balance' => 1500,
    ]);
    $ids[] = $credited->getKey();

    $batched = app(TenantBalances::class)->for($ids, null);

    foreach ($ids as $id) {
        $tenant = Tenant::find($id);

        expect($batched[$id]['outstanding'])
            ->toBe($tenant->outstandingBalance(null), "outstanding for {$tenant->name}");
        expect($batched[$id]['credit'])
            ->toBe($tenant->creditBalance(null), "credit for {$tenant->name}");
        expect($batched[$id]['delinquent'])
            ->toBe($tenant->isDelinquent(null), "delinquent for {$tenant->name}");
    }

    // The control: the fixtures must actually differ, or the assertions above are vacuous.
    expect(collect($batched)->pluck('outstanding')->unique())->toHaveCount(4);
    expect(collect($batched)->pluck('delinquent')->unique()->values()->all())->toEqualCanonicalizing([true, false]);
});

it('honours the property scope exactly as the per-row methods do', function () {
    // A cross-property AR leak is the failure this scoping exists to prevent, so the batch has to
    // narrow identically — not merely return the same total when unscoped.
    $mallA = makeAsset(['code' => 'AA']);
    $mallB = makeAsset(['code' => 'BB']);

    $shared = makeTenant(['name' => 'Shared Co']);
    makeInvoice(makeLease(makeUnit($mallA), $shared), ['status' => 'issued', 'due_date' => now()->subMonth(), 'total' => 1000, 'balance' => 1000]);
    makeInvoice(makeLease(makeUnit($mallB), $shared), ['status' => 'issued', 'due_date' => now()->subMonth(), 'total' => 7000, 'balance' => 7000]);

    $scoped = [$mallA->getKey()];
    $batched = app(TenantBalances::class)->for([$shared->getKey()], $scoped);

    expect($batched[$shared->getKey()]['outstanding'])->toBe($shared->outstandingBalance($scoped));

    // And it is genuinely narrower than the unscoped figure — otherwise the scope did nothing.
    expect($batched[$shared->getKey()]['outstanding'])
        ->toBeLessThan($shared->outstandingBalance(null));
});

it('issues a handful of queries for a page, not five per row', function () {
    $asset = makeAsset();
    $ids = [];
    for ($i = 0; $i < 12; $i++) {
        $t = makeTenant(['name' => "Co {$i}"]);
        makeInvoice(makeLease(makeUnit($asset), $t), ['status' => 'issued', 'due_date' => now()->subDay(), 'total' => 100 * ($i + 1), 'balance' => 100 * ($i + 1)]);
        $ids[] = $t->getKey();
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    app(TenantBalances::class)->for($ids, null);
    $batched = count(DB::getQueryLog());

    DB::flushQueryLog();
    foreach ($ids as $id) {
        $t = Tenant::find($id);
        $t->outstandingBalance(null);
        $t->creditBalance(null);
        $t->isDelinquent(null);
    }
    $perRow = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($batched)->toBeLessThan(10);
    // The point of the change, asserted rather than assumed.
    expect($batched)->toBeLessThan($perRow / 4);
});
