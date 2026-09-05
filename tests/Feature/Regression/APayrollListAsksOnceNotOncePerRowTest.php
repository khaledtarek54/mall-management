<?php

use App\Filament\Admin\Resources\Payrolls\Pages\ListPayrolls;
use App\Models\Asset;
use App\Models\User;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\PayrollRuns;

/**
 * **"Does this run have lines?" is asked once for the page, not once per row.**
 *
 * The *Export register* row action is offered only for a run with per-employee lines — a lump-sum
 * run has nothing to break down — and it asked `$record->lines()->exists()` per row. Measured
 * across every admin list on 2026-09-05, that was the payroll register's single largest cost:
 * 26 queries, 8 of them this one `exists`. `withCount('lines')` makes it a subselect on the query
 * Filament already runs (26 → 18).
 *
 * **No support class here, deliberately.** `TenantBalances` exists because what a tenant owes is a
 * business rule with four settlement channels and a batched restatement could disagree with the
 * record page. A COUNT of child rows is not a rule, so the batched form cannot mean something
 * different from the per-row form — which is why this needed one line and that needed a class.
 *
 * The behaviour is what is pinned, in both directions plus the query shape: a fix that made the
 * action disappear for every run would satisfy any "no N+1" assertion on its own.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->actingAs(User::factory()->create()->assignRole('super_admin'));
    Filament::setTenant(Asset::factory()->create());
});

it('offers the register only for a run that has lines, and issues no per-row exists', function () {
    $asset = Filament::getTenant();

    // `PayrollRuns::run()` is the shared builder; a lump-sum run is the same row with no lines.
    $withLines = PayrollRuns::run($asset, lines: 1);
    $lumpSum = PayrollRuns::run($asset, lines: 0, month: '2026-07-01');

    DB::flushQueryLog();
    DB::enableQueryLog();

    Livewire::test(ListPayrolls::class)
        ->assertOk()
        // Both directions. A change that hid the action everywhere would pass a query-count
        // assertion on its own, which is the failure mode this pairing exists to refuse.
        ->assertTableActionVisible('export_register', $withLines)
        ->assertTableActionHidden('export_register', $lumpSum);

    $perRowExists = collect(DB::getQueryLog())
        ->filter(fn (array $q): bool => str_contains($q['query'], 'exists')
            && str_contains($q['query'], 'payroll_lines'))
        ->count();

    DB::disableQueryLog();

    expect($perRowExists)->toBe(0, 'The list is still asking `lines()->exists()` per row — '
        .'the `withCount` is gone, or the row action stopped reading `lines_count`.');
});
