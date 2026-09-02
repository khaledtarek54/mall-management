<?php

use App\Filament\Admin\Actions\SalesDeclarationActions;
use App\Models\Charge;
use App\Models\Lease;
use App\Models\TenantSalesDeclaration;
use App\Models\User;
use App\Services\PercentageRentCalculationService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * `disputed` IS A STATE YOU COME BACK FROM, AND NOTHING LET YOU.
 *
 * The workflow module 09 documents is void → correct → re-bill. `voidLocked()` reverses the overage
 * invoice, deactivates the percentage-rent charge and sets the declaration to `disputed`; `dispute`
 * puts a `submitted` one there for the same reason. The operator then agrees the corrected turnover
 * with the tenant and locks it again.
 *
 * Except `SalesDeclarationActions::canLock()` required `submitted`, so from `disputed` there was no
 * forward move on any screen. The declaration sat there, the corrected percentage rent was never
 * billed, and the only way out was somebody hand-editing a status column.
 *
 * **The service was ready for it the whole time**, which is what makes this one predicate the entire
 * defect: `lock()` early-returns only on `locked`, `settleBillingPeriods()` is re-lock safe by design
 * (*"a period whose total is unchanged is left alone, payment and all"*), and a `disputed`
 * declaration is editable — `canEdit()` refuses only `locked`, and the frozen-columns hook fires
 * only when the ORIGINAL status was `locked`, so correcting the figure first is an ordinary edit.
 */
beforeEach(function (): void {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    $this->seed(RolesPermissionsSeeder::class);

    $this->operator = User::factory()->create();
    $this->operator->assignRole('super_admin');
    $this->actingAs($this->operator);

    $this->lease = Lease::factory()->create([
        'status' => 'active',
        'commencement_date' => CarbonImmutable::today()->subYear()->startOfMonth(),
        'expiry_date' => CarbonImmutable::today()->addYear()->endOfMonth(),
        'base_rent_monthly' => 44_000,
        'has_percentage_rent' => true,
        'percentage_rent_rate' => 7,
        'percentage_rent_calculation_type' => 'artificial',
        'percentage_rent_threshold' => 800_000,
        'percentage_rent_frequency' => 'monthly',
        'escalation_type' => 'none',
    ]);

    Charge::create([
        'lease_id' => $this->lease->id, 'name' => 'Base Rent', 'type' => 'base_rent',
        'amount' => 44_000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'start_date' => $this->lease->commencement_date, 'is_active' => true,
    ]);
});

/** A submitted declaration on the fixture lease, dated a couple of months back. */
function disputedLoopDeclaration(float $sales): TenantSalesDeclaration
{
    $start = CarbonImmutable::today()->subMonths(2)->startOfMonth();

    return TenantSalesDeclaration::create([
        'lease_id' => test()->lease->id,
        'period_start' => $start,
        'period_end' => $start->endOfMonth(),
        'gross_sales' => $sales,
        'declared_sales' => $sales,
        'declared_at' => $start->endOfMonth()->addDays(3),
        'status' => 'submitted',
    ]);
}

it('offers the lock again once a declaration has been disputed', function (): void {
    $declaration = disputedLoopDeclaration(1_240_000);

    // The control: it is offered on a submitted one.
    expect(SalesDeclarationActions::canLock($declaration))->toBeTrue();

    $declaration->update(['status' => 'disputed', 'audit_notes' => 'Tenant restated their figures.']);

    expect(SalesDeclarationActions::canLock($declaration->fresh()))->toBeTrue();
});

it('completes the void → correct → re-bill loop the module documents', function (): void {
    $declaration = disputedLoopDeclaration(1_240_000);

    $svc = app(PercentageRentCalculationService::class);
    $svc->lock($declaration, $this->operator);

    expect((float) $declaration->fresh()->calculated_percentage_rent)->toBe(30_800.0);

    // The tenant restates: the real turnover was lower.
    $svc->voidLocked($declaration->fresh(), $this->operator, 'the tenant restated their figures');

    expect($declaration->fresh()->status)->toBe('disputed');

    // Correcting the figure is an ordinary edit while disputed — the frozen-columns hook fires only
    // when the ORIGINAL status was `locked`.
    $declaration->fresh()->update(['gross_sales' => 1_000_000, 'declared_sales' => 1_000_000]);

    // …and the lock is reachable again, on the corrected figure.
    $relocked = $svc->lock($declaration->fresh(), $this->operator, 'Agreed with the tenant.');

    expect($relocked->fresh()->status)->toBe('locked')
        // 7% of (1,000,000 − 800,000) = 14,000, not the 30,800 that was voided.
        ->and((float) $relocked->fresh()->calculated_percentage_rent)->toBe(14_000.0)
        // …and the WHY survives. `lock()` wrote `audit_notes` unconditionally, which was harmless
        // while the only route in was from `submitted` (the column is empty there) and destroyed
        // the void reason the moment the forward move existed.
        ->and($relocked->fresh()->audit_notes)->toContain('restated their figures')
        ->and($relocked->fresh()->audit_notes)->toContain('Agreed with the tenant.');

    // **And the corrected overage is actually BILLED**, which is the whole claim. Asserting the
    // stored figure alone passes with `settleBillingPeriods()` stubbed out — measured.
    $items = \App\Models\InvoiceItem::query()
        ->where('type', 'percentage_rent')
        ->whereHas('invoice', fn ($q) => $q->whereNotIn('status', ['cancelled', 'draft']))
        ->get();

    expect($items)->toHaveCount(1)
        ->and((float) $items->first()->amount)->toBe(14_000.0);
});

it('still refuses to lock one that is already locked', function (): void {
    // The control for the control. Widening the predicate must not make `locked` re-lockable — the
    // service no-ops, but a button offering it says the declaration is not final when it is.
    $declaration = disputedLoopDeclaration(1_240_000);
    app(PercentageRentCalculationService::class)->lock($declaration, $this->operator);

    expect(SalesDeclarationActions::canLock($declaration->fresh()))->toBeFalse();
});

it('still refuses a role that may not lock', function (): void {
    // The permission half is unchanged: `leasing` holds tenant_sales.lock, `viewer` does not.
    $declaration = disputedLoopDeclaration(1_240_000);
    $declaration->update(['status' => 'disputed']);

    $this->actingAs(makeUser('viewer'));

    expect(SalesDeclarationActions::canLock($declaration->fresh()))->toBeFalse();
});
