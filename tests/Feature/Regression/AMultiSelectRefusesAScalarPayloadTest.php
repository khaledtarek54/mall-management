<?php

use App\Filament\Admin\Resources\CamExpensePools\Pages\CreateCamExpensePool;
use App\Models\CamExpensePool;
use App\Models\LedgerAccount;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Regression — a field that collects MANY values refuses a payload that is not an array.
 *
 * Filament derives a Select's `Rule::in` from the options it resolved and, for a `->multiple()`
 * Select, attaches it to `{path}.*` — the CHILDREN — with no `array` rule on the path. A scalar id
 * therefore has no children to validate, passes, is wrapped into an array by the state cast and
 * syncs. That falsified "the LABEL lookup IS the write guard" for every multi-select in the panel:
 * measured on the property page's Zones tab, a staff member from another mall refused as
 * `supervisors.0` when sent as `[5]` went straight through as `5`. Found by review, 2026-09-10.
 *
 * `App\Support\Filament\MultiValueFieldIsAnArray` is the one seam (`Select::configureUsing`
 * reaches `EntitySelect` and `CatalogueAwareSelect` through `class_parents()`), and this file
 * proves it on a door with NO post-save guard — the CAM pool's account picker, whose options are
 * narrowed to postable EXPENSE accounts — so the refusal can only be the seam's. The zone tab's
 * cases in `AZoneCreatedFromThePropertyPageHasItsSupervisorsTest` are the same tooth on a
 * guarded door.
 */
beforeEach(function (): void {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    ensureAllPropertiesAsset();

    $this->asset = makeAsset(['code' => 'MSA', 'leasable_area_sqm' => 200]);
    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

function msaPool(): array
{
    return [
        'period_year' => 2031,
        'pool_code' => CamExpensePool::CODE_CAM,
        'participant_scope' => CamExpensePool::PARTICIPANTS_ALL,
        'denominator_basis' => CamExpensePool::DENOMINATOR_OCCUPIED,
        'expense_basis' => CamExpensePool::BASIS_LEDGER,
        'estimate_basis' => CamExpensePool::BASIS_STATED,
        'total_actual_expense' => 10_000,
        'total_estimated_collected' => 0,
        'recovery_vat_rate' => 14,
    ];
}

it('refuses a scalar where the picker collects many — an id the options never offered', function (): void {
    // A REVENUE account: never among the options (the picker narrows to postable expense
    // accounts), so as `[id]` it is refused as `ledgerAccounts.0`. As a bare id it used to pass.
    $revenue = LedgerAccount::query()->where('type', 'revenue')->where('is_postable', true)->firstOrFail();

    Livewire::test(CreateCamExpensePool::class)
        ->fillForm(msaPool())
        ->set('data.ledgerAccounts', $revenue->id)
        ->call('create')
        ->assertHasFormErrors(['ledgerAccounts']);

    expect(CamExpensePool::query()->where('asset_id', $this->asset->id)->where('period_year', 2031)->exists())->toBeFalse();
});

it('still accepts the same value as an array when it IS among the options — the control', function (): void {
    $expense = LedgerAccount::query()->where('type', 'expense')->where('is_postable', true)->firstOrFail();

    Livewire::test(CreateCamExpensePool::class)
        ->fillForm(msaPool() + ['ledgerAccounts' => [$expense->id]])
        ->call('create')
        ->assertHasNoFormErrors();

    $pool = CamExpensePool::query()->where('asset_id', $this->asset->id)->where('period_year', 2031)->sole();

    expect($pool->ledgerAccounts()->pluck('ledger_accounts.id')->all())->toBe([$expense->id]);
});

it('leaves an empty optional multi-select alone — the other control', function (): void {
    // Filament adds `nullable` to every non-required field, and a multi-select's empty state is
    // `[]`; the `array` rule must not turn "nothing chosen" into a refusal.
    Livewire::test(CreateCamExpensePool::class)
        ->fillForm(msaPool() + ['expense_basis' => CamExpensePool::BASIS_STATED])
        ->call('create')
        ->assertHasNoFormErrors();
});
