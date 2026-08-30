<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\TenantSalesDeclarations\Pages\CreateTenantSalesDeclaration;
use App\Filament\Admin\Resources\TenantSalesDeclarations\Pages\EditTenantSalesDeclaration;
use App\Filament\Admin\Resources\TenantSalesDeclarations\TenantSalesDeclarationResource;
use App\Models\Charge;
use App\Models\Lease;
use App\Models\TaxCode;
use App\Models\TaxRate;
use App\Models\TenantSalesDeclaration;
use App\Models\User;
use App\Services\PercentageRentCalculationService;
use App\Support\SalesExclusions;
use App\Support\Vat;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * THE TWO DERIVED FIGURES MUST BE ON SCREEN BEFORE THE OPERATOR COMMITS.
 *
 * `declared_sales` was computed by the model's `saving` hook and
 * `calculated_percentage_rent` by the lock, so both fields sat EMPTY while the operator keyed a
 * gross figure and its deductions. The helper text underneath even spelled the derivation out —
 * "1,368,000.00 gross less 218,000.00 deducted" — beside a blank box, which is the shape that
 * gets read as broken.
 *
 * It matters most for the figure that costs money. Percentage rent is charged on the sales ABOVE
 * a breakpoint, so a 12% error in the gross becomes a ~50% error in the charge: seeing the charge
 * before committing to it is the only cheap check an operator has, and locking is not reversible
 * without voiding an invoice.
 *
 * The second test is the one with money in it: the VAT deduction was taken at TODAY's rate, with
 * no date. `SalesExclusions::vatWithin()` falls back to `Vat::standardRate()` and this call site
 * passed nothing — so a declaration keyed after a rate change deducted the NEW rate from an OLD
 * month's turnover. A rate is a dated rung everywhere else in this system; here it was not.
 */
beforeEach(function (): void {
    $this->seed(RolesPermissionsSeeder::class);

    $user = User::factory()->create();
    $user->assignRole('super_admin');
    $this->actingAs($user);

    $this->lease = Lease::factory()->create([
        'status' => 'active',
        'commencement_date' => CarbonImmutable::parse('2026-01-01'),
        'expiry_date' => CarbonImmutable::parse('2028-12-31'),
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

    Filament::setTenant($this->lease->unit->asset);
});

function declarationForm(Lease $lease, array $exclusions = []): array
{
    $page = Livewire::test(CreateTenantSalesDeclaration::class);

    // Set field by field, the way an operator does — the page carries its own defaults, and a
    // single fillForm() is overwritten by them.
    $page->set('data.lease_id', $lease->getKey());
    $page->set('data.period_start', '2026-06-01');
    $page->set('data.period_end', '2026-06-30');
    $page->set('data.gross_sales', 1_368_000);

    if ($exclusions !== []) {
        $page->set('data.sales_exclusions', $exclusions);
    }

    return $page->get('data');
}

it('shows the declared sales and the charge as the deductions are keyed', function (): void {
    $data = declarationForm($this->lease, [
        'vat' => 168_000,
        'returns' => 30_000,
        'employee_discounts' => 20_000,
    ]);

    // 1,368,000 − 218,000 = 1,150,000; (1,150,000 − 800,000) × 7% = 24,500.
    expect(round((float) $data['declared_sales'], 2))->toBe(1_150_000.0)
        ->and(round((float) $data['calculated_percentage_rent'], 2))->toBe(24_500.0);
});

it('moves both figures when a deduction is removed', function (): void {
    $withVat = declarationForm($this->lease, ['vat' => 168_000, 'returns' => 30_000]);
    $without = declarationForm($this->lease, ['returns' => 30_000]);

    // With VAT: 1,368,000 − 198,000 = 1,170,000 → (1,170,000 − 800,000) × 7% = 25,900.
    // Without:   1,368,000 −  30,000 = 1,338,000 → (1,338,000 − 800,000) × 7% = 37,660.
    // Dropping one deduction moves the charge by 45% — which is the whole reason it has to be
    // visible before the operator commits.
    expect(round((float) $withVat['calculated_percentage_rent'], 2))->toBe(25_900.0)
        ->and(round((float) $without['calculated_percentage_rent'], 2))->toBe(37_660.0);
});

it('deducts VAT at the rate in force for the PERIOD, not today', function (): void {
    $code = TaxCode::where('code', 'VAT_14')->firstOrFail();

    // A rise effective after the declared period — the Law 157/2025 case this system is being
    // prepared for, and the one that makes an undated rate wrong rather than merely imprecise.
    TaxRate::create([
        'tax_code_id' => $code->id,
        'rate' => 20,
        'effective_from' => CarbonImmutable::parse('2026-08-01'),
    ]);

    cache()->flush();

    expect(Vat::standardRate(CarbonImmutable::parse('2026-09-01')))->toBe(20.0)
        ->and(Vat::standardRate(CarbonImmutable::parse('2026-06-15')))->toBe(14.0);

    $page = Livewire::test(CreateTenantSalesDeclaration::class);
    $page->set('data.lease_id', $this->lease->getKey());
    $page->set('data.period_start', '2026-06-01');
    $page->set('data.period_end', '2026-06-30');
    $page->set('data.gross_sales', 1_368_000);
    $page->set('data.gross_includes_vat', true);

    $exclusions = (array) ($page->get('data')['sales_exclusions'] ?? []);
    $vat = (float) ($exclusions['vat'] ?? collect($exclusions)->firstWhere('key', 'vat')['value'] ?? 0);

    // 14% within 1,368,000 = 168,000. At 20% it would be 228,000, understating the charge by 21%.
    expect(round($vat, 2))->toBe(168_000.0);
});

it('computes the VAT WITHIN the figure, never on top of it', function (): void {
    // 1,368,000 − 1,368,000 ÷ 1.14 = 168,000. `× 14%` gives 191,520 — over-deducting by a factor
    // of 1.14, which the comment at the call site warns about and this pins.
    expect(SalesExclusions::vatWithin(1_368_000, 14.0))->toBe(168_000.0)
        ->and(SalesExclusions::vatWithin(1_368_000, 14.0))->not->toBe(round(1_368_000 * 0.14, 2));
});

it('leaves the charge blank on a lease with no percentage rent', function (): void {
    $plain = Lease::factory()->create([
        'status' => 'active',
        'commencement_date' => CarbonImmutable::parse('2026-01-01'),
        'expiry_date' => CarbonImmutable::parse('2028-12-31'),
        'has_percentage_rent' => false,
        'escalation_type' => 'none',
    ]);

    Filament::setTenant($plain->unit->asset);

    // The control: a preview that showed a figure here would be inventing a charge the lease does
    // not provide for.
    expect(declarationForm($plain, ['returns' => 30_000])['calculated_percentage_rent'] ?? null)->toBeNull();
});

it('opens an edit page with the VAT toggle reflecting the deduction that is there', function (): void {
    $declaration = TenantSalesDeclaration::create([
        'lease_id' => $this->lease->id,
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-30',
        'gross_sales' => 1_368_000,
        'sales_exclusions' => ['vat' => 168_000, 'returns' => 30_000, 'employee_discounts' => 20_000],
        'declared_at' => '2026-07-05',
        'status' => 'submitted',
    ]);

    $data = Livewire::test(EditTenantSalesDeclaration::class, ['record' => $declaration->getKey()])
        ->get('data');

    // The toggle stores nothing of its own, so without hydrating it from the deductions an edit
    // page opens stating the OPPOSITE of the record it is showing.
    expect($data['gross_includes_vat'])->toBeTrue()
        ->and(round((float) $data['declared_sales'], 2))->toBe(1_150_000.0)
        ->and(round((float) $data['calculated_percentage_rent'], 2))->toBe(24_500.0);
});

it('shows the toggle off when no VAT was deducted', function (): void {
    $declaration = TenantSalesDeclaration::create([
        'lease_id' => $this->lease->id,
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-30',
        'gross_sales' => 1_368_000,
        'sales_exclusions' => ['returns' => 30_000],
        'declared_at' => '2026-07-05',
        'status' => 'submitted',
    ]);

    // The control. A toggle hydrated to `true` unconditionally would be just as wrong in the other
    // direction, and would read as "VAT was taken" on a declaration where it was not.
    expect(Livewire::test(EditTenantSalesDeclaration::class, ['record' => $declaration->getKey()])
        ->get('data')['gross_includes_vat'])->toBeFalse();
});

it('does not open a LOCKED declaration for editing at all', function (): void {
    $declaration = TenantSalesDeclaration::create([
        'lease_id' => $this->lease->id,
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-30',
        'gross_sales' => 1_368_000,
        'sales_exclusions' => ['vat' => 168_000, 'returns' => 30_000, 'employee_discounts' => 20_000],
        'declared_at' => '2026-07-05',
        'status' => 'submitted',
    ]);

    app(PercentageRentCalculationService::class)->lock($declaration, auth()->user());

    // The reason the preview needs no "is it locked" branch: the resource refuses the page. A
    // locked declaration has already raised an invoice, and its frozen figure is read on the view
    // page and in the table rather than in a form.
    expect(TenantSalesDeclarationResource::canEdit($declaration->fresh()))->toBeFalse();
});

it('previews the charge when the NET figure is typed straight in', function (): void {
    // The form only derives `declared_sales` when a gross is stated. With no gross it is the field
    // the operator types, it is what older declarations carry, and it is what the tenant portal
    // sends — so a preview that answered only on the gross went blank on the SIMPLER path.
    // Reported from the panel on a declaration keyed exactly this way.
    $page = Livewire::test(CreateTenantSalesDeclaration::class);
    $page->set('data.lease_id', $this->lease->getKey());
    $page->set('data.period_start', '2026-06-01');
    $page->set('data.period_end', '2026-06-30');
    $page->set('data.declared_sales', 1_150_000);

    expect(round((float) $page->get('data')['calculated_percentage_rent'], 2))->toBe(24_500.0);
});

it('previews a natural breakpoint below and above its own line', function (): void {
    $zara = Lease::factory()->create([
        'status' => 'active',
        'commencement_date' => CarbonImmutable::parse('2026-01-01'),
        'expiry_date' => CarbonImmutable::parse('2028-12-31'),
        'base_rent_monthly' => 50_000,
        'has_percentage_rent' => true,
        'percentage_rent_rate' => 5.5,
        'percentage_rent_calculation_type' => 'natural_breakpoint',
        'percentage_rent_threshold' => null,
        'percentage_rent_frequency' => 'monthly',
        'escalation_type' => 'none',
    ]);

    Filament::setTenant($zara->unit->asset);

    $preview = function (float $net) use ($zara): float {
        $page = Livewire::test(CreateTenantSalesDeclaration::class);
        $page->set('data.lease_id', $zara->getKey());
        $page->set('data.period_start', '2026-07-01');
        $page->set('data.period_end', '2026-07-31');
        $page->set('data.declared_sales', $net);

        return round((float) $page->get('data')['calculated_percentage_rent'], 2);
    };

    // The breakpoint is DERIVED, not agreed: rent ÷ rate = 50,000 ÷ 5.5% = 909,090.91. Below it the
    // percentage has not yet covered the rent, so nothing is owed — and ZERO must be shown rather
    // than left blank, because a blank reads as "not computed" on the figure being decided.
    expect($preview(640_000))->toBe(0.0)
        ->and($preview(1_000_000))->toBe(5_000.0);
});
