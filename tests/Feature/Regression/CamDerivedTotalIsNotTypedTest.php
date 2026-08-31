<?php

use App\Filament\Admin\Resources\CamExpensePools\Pages\CreateCamExpensePool;
use App\Models\CamExpensePool;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Regression: A DERIVED TOTAL IS NOT A FIELD YOU TYPE.
 *
 * `total_actual_expense` and `total_estimated_collected` were `->required()` unconditionally, so a
 * pool on `Posted ledger accounts` and `What tenants were invoiced` — the two bases whose entire
 * point is that nobody re-keys the figure — could not be created without keying it. Reported from
 * the panel: the browser refused to submit with "Please fill out this field" on a column the
 * operator had just told the system to compute. Both columns are NOT NULL `default 0.00`, so the
 * requirement protected nothing.
 *
 * Worse than an obstacle. Whatever is typed STAYS until somebody presses Sync, and
 * `CamExpensePool::needsSourcing()` exists because that exact figure once read 0 while the tenants
 * had been invoiced 346,000 — the pool header lying while every allocation was right, which the
 * module doc calls the harder kind of wrong to notice. Requiring a hand-keyed seed for a derived
 * column is how that row gets created in the first place.
 *
 * So a derived total is READ-ONLY, not merely optional: it is an output, and a second way to write
 * it is a second truth about the same money.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'CAM-DERIV']);
    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);

    $this->base = fn (array $o = []) => array_merge([
        'period_year' => 2031,
        'pool_code' => CamExpensePool::CODE_CAM,
        'status' => 'draft',
        'participant_scope' => CamExpensePool::PARTICIPANTS_ALL,
        'denominator_basis' => CamExpensePool::DENOMINATOR_OCCUPIED,
        'recovery_vat_rate' => 14,
    ], $o);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('creates a ledger-sourced pool without either total being typed', function () {
    Livewire::test(CreateCamExpensePool::class)
        ->fillForm(($this->base)([
            'expense_basis' => CamExpensePool::BASIS_LEDGER,
            'estimate_basis' => CamExpensePool::BASIS_BILLED,
            'estimate_charge_codes' => ['service_charge'],
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    $pool = CamExpensePool::where('asset_id', $this->asset->id)->where('period_year', 2031)->sole();

    // The columns take their own default and the pool reports, on its own screen, that neither
    // figure has been sourced yet — which is the honest state, not a seeded number pretending to be one.
    expect((float) $pool->total_actual_expense)->toBe(0.0)
        ->and((float) $pool->total_estimated_collected)->toBe(0.0)
        ->and($pool->needsSourcing())->toBeTrue();
});

it('still demands both totals when the operator is the one stating them', function () {
    // The control. Making a derived total optional must not make a STATED one optional: on
    // `A figure typed here` the figure is the operator's claim and a blank pool would reconcile
    // every tenant against nothing.
    Livewire::test(CreateCamExpensePool::class)
        ->fillForm(($this->base)([
            'period_year' => 2032,
            'expense_basis' => CamExpensePool::BASIS_STATED,
            'estimate_basis' => CamExpensePool::BASIS_STATED,
            'total_actual_expense' => null,
            'total_estimated_collected' => null,
        ]))
        ->call('create')
        ->assertHasFormErrors(['total_actual_expense', 'total_estimated_collected']);

    expect(CamExpensePool::where('period_year', 2032)->exists())->toBeFalse();
});

it('demands each total independently, per its own basis', function () {
    // The two bases are separate questions and one may be derived while the other is stated.
    Livewire::test(CreateCamExpensePool::class)
        ->fillForm(($this->base)([
            'period_year' => 2033,
            'expense_basis' => CamExpensePool::BASIS_LEDGER,   // derived → not required
            'estimate_basis' => CamExpensePool::BASIS_STATED,  // typed   → required
            'total_estimated_collected' => null,
        ]))
        ->call('create')
        ->assertHasFormErrors(['total_estimated_collected'])
        ->assertHasNoFormErrors(['total_actual_expense']);
});

it('does not let a derived total be typed at all', function () {
    // Optional is not enough — a derived column with a second writer is a second truth. The field
    // is disabled, and a disabled Filament field is not dehydrated, so a value sent for it is
    // discarded rather than stored.
    Livewire::test(CreateCamExpensePool::class)
        ->fillForm(($this->base)([
            'period_year' => 2034,
            'expense_basis' => CamExpensePool::BASIS_LEDGER,
            'estimate_basis' => CamExpensePool::BASIS_BILLED,
            'estimate_charge_codes' => ['service_charge'],
            'total_actual_expense' => 999_999,
            'total_estimated_collected' => 888_888,
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    $pool = CamExpensePool::where('period_year', 2034)->sole();

    expect((float) $pool->total_actual_expense)->toBe(0.0)
        ->and((float) $pool->total_estimated_collected)->toBe(0.0);
});
