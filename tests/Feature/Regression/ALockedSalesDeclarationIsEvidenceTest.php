<?php

declare(strict_types=1);

use App\Models\Charge;
use App\Models\Lease;
use App\Models\TenantSalesDeclaration;
use App\Models\User;
use App\Services\PercentageRentCalculationService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * A LOCKED DECLARATION IS EVIDENCE, AND EVIDENCE DOES NOT GET RETYPED.
 *
 * Locking computes the overage, freezes it on the row and RAISES THE INVOICE for it. The figures
 * the tenant certified were still freely editable afterwards, and nothing recomputed.
 *
 * Measured on the demo books: a locked July declaration went from 910,000 to 2,000,000 of sales
 * while its stored overage and its invoice both stayed at 7,700, where 84,000 was due. 76,300
 * hidden — and the document a dispute would be settled on now says one thing while the money says
 * another. The other direction is no better: an operator can lower the declared figure and leave
 * the tenant billed on a number their own certificate no longer supports.
 *
 * The correction path already existed and is careful — `voidLocked()` reverses the overage, voids
 * the invoice, refuses if that invoice has been PAID, and re-trues the rest of an annual year. This
 * is the guard that makes an operator use it.
 *
 * The controls matter as much as the refusal: freezing too much would seal the door this guard
 * points at, so locking, voiding, an audit note and an unlocked declaration are all pinned as
 * still working.
 */
beforeEach(function (): void {
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

/**
 * A submitted declaration on an EXISTING lease, dated a couple of months back.
 *
 * Named for this file rather than `declarationFor`, which
 * `tests/Feature/Services/PercentageRentCalculationServiceTest.php` already declares with a
 * different signature (it MAKES the lease). Two file-scope functions of one name is a FATAL
 * redeclaration on any single-process run and invisible under `--parallel` — see
 * `TestHelperUniquenessConformanceTest`.
 */
function evidenceDeclarationFor(Lease $lease, float $sales, int $monthsAgo = 2): TenantSalesDeclaration
{
    $start = CarbonImmutable::today()->subMonths($monthsAgo)->startOfMonth();

    return TenantSalesDeclaration::create([
        'lease_id' => $lease->id,
        'period_start' => $start,
        'period_end' => $start->endOfMonth(),
        'gross_sales' => $sales,
        'declared_sales' => $sales,
        'declared_at' => $start->endOfMonth()->addDays(3),
        'status' => 'submitted',
    ]);
}

it('refuses to restate the gross a locked declaration certified', function (): void {
    $declaration = evidenceDeclarationFor($this->lease, 910_000);
    app(PercentageRentCalculationService::class)->lock($declaration, $this->operator);

    $declaration = $declaration->fresh();
    expect((float) $declaration->calculated_percentage_rent)->toBe(7_700.0);

    $declaration->gross_sales = 2_000_000;

    expect(fn () => $declaration->save())->toThrow(DomainException::class);
});

it('refuses to restate a LEGACY declaration, where the net figure is the one typed', function (): void {
    // `declared_sales` is DERIVED from `gross_sales` whenever a gross is present — the `saving`
    // hook above recomputes it, so retyping the net alone is reverted before this guard ever sees
    // it. That is safe, and it is not this rule. The rule bites on the older shape the hook leaves
    // alone: a declaration recorded with no gross, where the net figure is what somebody typed.
    $declaration = evidenceDeclarationFor($this->lease, 910_000);
    $declaration->forceFill(['gross_sales' => null])->saveQuietly();

    app(PercentageRentCalculationService::class)->lock($declaration->fresh(), $this->operator);

    $declaration = $declaration->fresh();
    $declaration->declared_sales = 2_000_000;

    expect(fn () => $declaration->save())->toThrow(DomainException::class);
});

it('refuses to move a locked declaration to another month', function (): void {
    $declaration = evidenceDeclarationFor($this->lease, 910_000);
    app(PercentageRentCalculationService::class)->lock($declaration, $this->operator);

    $declaration = $declaration->fresh();
    $declaration->period_start = $declaration->period_start->subMonth();

    expect(fn () => $declaration->save())->toThrow(DomainException::class);
});

it('still lets the lock itself happen', function (): void {
    $declaration = evidenceDeclarationFor($this->lease, 1_240_000);

    $locked = app(PercentageRentCalculationService::class)->lock($declaration, $this->operator);

    expect($locked->fresh()->status)->toBe('locked')
        ->and((float) $locked->fresh()->calculated_percentage_rent)->toBe(30_800.0);
});

it('still lets the lock be voided — the door this guard points at', function (): void {
    $declaration = evidenceDeclarationFor($this->lease, 1_240_000);
    app(PercentageRentCalculationService::class)->lock($declaration, $this->operator);

    $voided = app(PercentageRentCalculationService::class)
        ->voidLocked($declaration->fresh(), $this->operator, 'the tenant restated their figures');

    expect($voided->fresh()->status)->toBe('disputed');
});

it('still lets an audit note be written on a locked declaration', function (): void {
    $declaration = evidenceDeclarationFor($this->lease, 910_000);
    app(PercentageRentCalculationService::class)->lock($declaration, $this->operator);

    $declaration = $declaration->fresh();
    $declaration->audit_notes = 'checked against the POS export';

    expect(fn () => $declaration->save())->not->toThrow(DomainException::class);
});

it('still lets an unlocked declaration be corrected freely', function (): void {
    $declaration = evidenceDeclarationFor($this->lease, 910_000);

    $declaration->gross_sales = 1_500_000;
    $declaration->save();

    expect((float) $declaration->fresh()->declared_sales)->toBe(1_500_000.0);
});

it('does not freeze the computed share, which the annual re-true restates', function (): void {
    $declaration = evidenceDeclarationFor($this->lease, 1_240_000);
    app(PercentageRentCalculationService::class)->lock($declaration, $this->operator);

    // The tenant's DECLARATION is evidence; the system's own share is derived, and
    // `retrueAnnualYear()` rewrites it on locked months when a sibling month is voided. Freezing
    // it would break the annual basis rather than protect it.
    $declaration = $declaration->fresh();
    $declaration->calculated_percentage_rent = 12_345;

    expect(fn () => $declaration->save())->not->toThrow(DomainException::class);
});

it('words the refusal in both languages, naming the field', function (): void {
    foreach (['en', 'ar'] as $locale) {
        $message = trans('admin.validation.locked_declaration_is_evidence', ['field' => 'X'], $locale);

        expect($message)->not->toBe('admin.validation.locked_declaration_is_evidence')
            ->and($message)->toContain('X');
    }

    expect(trans('admin.validation.locked_declaration_is_evidence', ['field' => 'X'], 'ar'))
        ->toMatch('/\p{Arabic}/u');
});
