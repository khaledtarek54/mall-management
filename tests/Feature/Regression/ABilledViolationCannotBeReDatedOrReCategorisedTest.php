<?php

use App\Filament\Admin\Resources\Violations\Pages\EditViolation;
use App\Models\Violation;
use App\Services\Accounting\FiscalCalendar;
use App\Services\BillViolationFineService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * A BILLED VIOLATION MAY NOT BE RE-DATED OR RE-CATEGORISED (SW-128).
 *
 * `BillViolationFineService::bill()` writes the fine invoice's ONLY line as
 * ":reference (:category) — :date", composed from the violation's reference, its category LABEL and
 * its date; and it derives the invoice's `period_start`/`period_end` from that same date's own
 * month. The form froze the property, the tenant and the amount once billed — three copies of one
 * predicate — and left `category` and `violation_date` open, which are precisely the two the
 * DOCUMENT quotes. So the register could be edited into disagreeing with the paper the tenant is
 * holding, and neither one says so.
 *
 * The freeze is the same shape the amount already used: the correction is to CANCEL the fine
 * invoice (the one status that frees `isBilled()`) and bill it again — which the locked field now
 * says out loud, because a field that has silently stopped accepting input reads as a broken form.
 *
 * Driven through the REAL Edit page, not the schema: a disabled field is only genuinely frozen
 * because Filament's `disabled()` also sets `saved(false)`, and that is a property of the SAVE, not
 * of the component definition.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear(2026);

    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->asset = makeAsset();
    $unit = makeUnit($this->asset);
    $this->tenant = makeTenant();
    makeLease($unit, $this->tenant, ['status' => 'active', 'commencement_date' => '2026-01-01']);

    $this->actingAs(makeUser('super_admin', [$this->asset->id]));

    $this->violation = Violation::create([
        'asset_id' => $this->asset->id,
        'tenant_id' => $this->tenant->id,
        'category' => 'safety',
        'description' => 'Blocked fire exit on the service corridor.',
        'fine_amount' => 1000,
        'violation_date' => '2026-03-15',
        'status' => 'open',
    ]);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('leaves an unbilled violation fully editable — the control', function () {
    asTenant($this->asset, function () {
        Livewire::test(EditViolation::class, ['record' => $this->violation->getRouteKey()])
            ->assertFormFieldEnabled('category')
            ->assertFormFieldEnabled('violation_date')
            ->fillForm(['category' => 'noise', 'violation_date' => '2026-04-02'])
            ->call('save')
            ->assertHasNoFormErrors();
    });

    $fresh = $this->violation->fresh();

    expect($fresh->category)->toBe('noise')
        ->and($fresh->violation_date->toDateString())->toBe('2026-04-02');
});

it('freezes the category and the date once the fine invoice has been issued', function () {
    $invoice = app(BillViolationFineService::class)->bill($this->violation);

    // The premise, MEASURED rather than assumed: the document really does quote both.
    expect($invoice->items()->first()->description)->toContain('15 Mar 2026')
        ->and($invoice->period_start->toDateString())->toBe('2026-03-01');

    asTenant($this->asset, function () {
        Livewire::test(EditViolation::class, ['record' => $this->violation->fresh()->getRouteKey()])
            ->assertFormFieldDisabled('category')
            ->assertFormFieldDisabled('violation_date')
            // A locked field that says nothing reads as a broken form, so it names the escape.
            ->assertSee(__('admin.helpers.violation_locked_by_fine'))
            // And the lock is real, not decorative: fill them and save anyway.
            ->fillForm([
                'notes' => 'Tenant appealed; wording corrected.',
                'category' => 'noise',
                'violation_date' => '2026-04-02',
            ])
            ->call('save')
            ->assertHasNoFormErrors();
    });

    $fresh = $this->violation->fresh();

    // `notes` moved, so the save genuinely ran — without this the two refusals below would pass
    // just as happily on a form that refused the whole submit for some unrelated reason.
    expect($fresh->notes)->toBe('Tenant appealed; wording corrected.')
        ->and($fresh->category)->toBe('safety')
        ->and($fresh->violation_date->toDateString())->toBe('2026-03-15');
});

it('unfreezes them again once the fine invoice is cancelled — the escape the message names', function () {
    $invoice = app(BillViolationFineService::class)->bill($this->violation);
    $invoice->update(['status' => 'cancelled']);

    expect($this->violation->fresh()->isBilled())->toBeFalse();

    asTenant($this->asset, function () {
        Livewire::test(EditViolation::class, ['record' => $this->violation->fresh()->getRouteKey()])
            ->assertFormFieldEnabled('category')
            ->assertFormFieldEnabled('violation_date')
            ->assertDontSee(__('admin.helpers.violation_locked_by_fine'));
    });
});
