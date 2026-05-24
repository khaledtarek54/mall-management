<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Models\Charge;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Lease;
use App\Models\Operator;
use App\Models\Tenant;
use App\Models\TenantSalesDeclaration;
use App\Models\Unit;
use App\Services\CamReconciliationService;
use App\Services\LateFeeService;
use App\Services\MonthlyBillingService;
use App\Services\PercentageRentCalculationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingMathTest extends TestCase
{
    use RefreshDatabase;

    private function makeLease(array $overrides = []): Lease
    {
        $operator = Operator::create([
            'name' => 'Test Operator',
            'slug' => 'test-' . uniqid(),
            'primary_color' => '#000000',
            'is_active' => true,
        ]);

        $asset = Asset::create([
            'operator_id' => $operator->id,
            'name' => 'Test Asset',
            'code' => 'TST-' . uniqid(),
            'type' => 'mall',
            'city' => 'Cairo',
            'country' => 'EG',
            'total_area_sqm' => 1000,
            'leasable_area_sqm' => 800,
            'currency' => 'EGP',
            'is_active' => true,
        ]);

        $unit = Unit::create([
            'asset_id' => $asset->id,
            'code' => 'U-' . uniqid(),
            'area_sqm' => $overrides['unit_area'] ?? 100,
            'status' => 'occupied',
        ]);

        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'email' => 'tenant-' . uniqid() . '@test.local',
            'status' => 'active',
        ]);

        return Lease::create(array_merge([
            'reference' => 'LSE-' . uniqid(),
            'unit_id' => $unit->id,
            'tenant_id' => $tenant->id,
            'status' => 'active',
            'commencement_date' => '2026-01-01',
            'expiry_date' => '2027-12-31',
            'term_months' => 24,
            'base_rent_monthly' => 10000,
            'service_charge_monthly' => 2000,
            'currency' => 'EGP',
            'payment_terms_days' => 7,
        ], array_diff_key($overrides, ['unit_area' => 1])));
    }

    public function test_percentage_rent_artificial_breakpoint(): void
    {
        $lease = $this->makeLease([
            'has_percentage_rent' => true,
            'percentage_rent_threshold' => 100000,
            'percentage_rent_rate' => 8,
            'percentage_rent_calculation_type' => 'artificial',
        ]);

        $declaration = TenantSalesDeclaration::create([
            'lease_id' => $lease->id,
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'declared_sales' => 250000,
            'declared_at' => '2026-02-01',
            'status' => 'submitted',
        ]);

        $owed = app(PercentageRentCalculationService::class)->calculate($declaration);

        $this->assertEquals(12000.00, $owed, '(250000 - 100000) * 8% = 12000');
    }

    public function test_percentage_rent_natural_breakpoint(): void
    {
        $lease = $this->makeLease([
            'has_percentage_rent' => true,
            'percentage_rent_rate' => 6,
            'percentage_rent_calculation_type' => 'natural_breakpoint',
            'base_rent_monthly' => 8000,
        ]);

        $declaration = TenantSalesDeclaration::create([
            'lease_id' => $lease->id,
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'declared_sales' => 200000,
            'declared_at' => '2026-02-01',
            'status' => 'submitted',
        ]);

        $owed = app(PercentageRentCalculationService::class)->calculate($declaration);

        $this->assertEquals(4000.00, $owed, '200000 * 6% - 8000 = 4000');
    }

    public function test_percentage_rent_below_threshold_is_zero(): void
    {
        $lease = $this->makeLease([
            'has_percentage_rent' => true,
            'percentage_rent_threshold' => 500000,
            'percentage_rent_rate' => 5,
            'percentage_rent_calculation_type' => 'artificial',
        ]);

        $declaration = TenantSalesDeclaration::create([
            'lease_id' => $lease->id,
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'declared_sales' => 100000,
            'declared_at' => '2026-02-01',
            'status' => 'submitted',
        ]);

        $this->assertEquals(0.0, app(PercentageRentCalculationService::class)->calculate($declaration));
    }

    public function test_monthly_billing_is_idempotent(): void
    {
        $lease = $this->makeLease();

        Charge::create([
            'lease_id' => $lease->id,
            'name' => 'Base Rent',
            'type' => 'base_rent',
            'amount' => 10000,
            'currency' => 'EGP',
            'frequency' => 'monthly',
            'vat_applicable' => true,
            'vat_rate' => 14,
            'start_date' => '2026-01-01',
            'is_active' => true,
        ]);

        $service = app(MonthlyBillingService::class);
        $period = CarbonImmutable::parse('2026-03-01');

        $first = $service->runForPeriod($period);
        $second = $service->runForPeriod($period);

        $this->assertEquals(1, $first['created']);
        $this->assertEquals(0, $second['created']);
        $this->assertEquals(1, $second['skipped']);
        $this->assertEquals(1, Invoice::where('lease_id', $lease->id)->count());
    }

    public function test_late_fee_applies_once_per_invoice(): void
    {
        $lease = $this->makeLease();

        $invoice = Invoice::create([
            'number' => 'INV-LATE-001',
            'lease_id' => $lease->id,
            'tenant_id' => $lease->tenant_id,
            'status' => 'issued',
            'issue_date' => '2026-01-01',
            'due_date' => '2026-01-10',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'subtotal' => 10000,
            'vat_amount' => 1400,
            'total' => 11400,
            'paid_amount' => 0,
            'balance' => 11400,
            'currency' => 'EGP',
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'description' => 'Rent Jan',
            'type' => 'base_rent',
            'amount' => 10000,
            'vat_rate' => 14,
            'vat_amount' => 1400,
            'total' => 11400,
        ]);

        $today = CarbonImmutable::parse('2026-02-01'); // 22 days past due

        $service = app(LateFeeService::class);
        $stats1 = $service->runForToday($today);
        $stats2 = $service->runForToday($today);

        $this->assertEquals(1, $stats1['applied']);
        $this->assertEquals(0, $stats2['applied']);
        $this->assertEquals(1, $stats2['skipped']);

        $invoice->refresh();
        $this->assertEquals('overdue', $invoice->status);
        // 11400 * 2% = 228 (above 50 minimum)
        $this->assertEquals(228.00, (float) $invoice->items()->where('type', 'late_fee')->first()->amount);
        $this->assertEquals(11628.00, (float) $invoice->balance);
    }

    public function test_late_fee_respects_grace_period(): void
    {
        $lease = $this->makeLease();

        $invoice = Invoice::create([
            'number' => 'INV-GRACE-001',
            'lease_id' => $lease->id,
            'tenant_id' => $lease->tenant_id,
            'status' => 'issued',
            'issue_date' => '2026-01-01',
            'due_date' => '2026-01-30',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'subtotal' => 5000,
            'vat_amount' => 0,
            'total' => 5000,
            'paid_amount' => 0,
            'balance' => 5000,
            'currency' => 'EGP',
        ]);

        // 3 days past due — within default 7-day grace.
        $stats = app(LateFeeService::class)->runForToday(CarbonImmutable::parse('2026-02-02'));

        $this->assertEquals(0, $stats['applied']);
        $this->assertEquals(0, $invoice->refresh()->items()->where('type', 'late_fee')->count());
    }

    public function test_cam_allocation_distributes_by_sqm(): void
    {
        $operator = Operator::create([
            'name' => 'CAM Op', 'slug' => 'cam-' . uniqid(), 'primary_color' => '#000', 'is_active' => true,
        ]);
        $asset = Asset::create([
            'operator_id' => $operator->id, 'name' => 'A', 'code' => 'CA-' . uniqid(),
            'type' => 'mall', 'city' => 'Cairo', 'country' => 'EG',
            'total_area_sqm' => 1000, 'leasable_area_sqm' => 1000,
            'currency' => 'EGP', 'is_active' => true,
        ]);

        $units = collect([100, 300])->map(function ($sqm, $i) use ($asset) {
            return Unit::create([
                'asset_id' => $asset->id,
                'code' => 'U' . $i . '-' . uniqid(),
                'area_sqm' => $sqm,
                'status' => 'occupied',
            ]);
        });

        $leases = $units->map(function ($unit) {
            $tenant = Tenant::create(['name' => 'T', 'email' => uniqid() . '@t.test', 'status' => 'active']);
            return Lease::create([
                'reference' => 'L-' . uniqid(),
                'unit_id' => $unit->id, 'tenant_id' => $tenant->id, 'status' => 'active',
                'commencement_date' => '2026-01-01', 'expiry_date' => '2026-12-31', 'term_months' => 12,
                'base_rent_monthly' => 1000, 'service_charge_monthly' => 200, 'currency' => 'EGP',
                'payment_terms_days' => 7,
            ]);
        });

        $pool = CamExpensePool::create([
            'asset_id' => $asset->id,
            'period_year' => 2026,
            'total_actual_expense' => 100000,
            'total_estimated_collected' => 80000,
            'status' => 'draft',
        ]);

        $count = app(CamReconciliationService::class)->generateAllocations($pool);

        $this->assertEquals(2, $count);

        // Total sqm = 400. Lease 1 (100 sqm) gets 25%, Lease 2 (300 sqm) gets 75%.
        $a1 = CamAllocation::where('lease_id', $leases[0]->id)->first();
        $a2 = CamAllocation::where('lease_id', $leases[1]->id)->first();

        $this->assertEquals(25000.00, (float) $a1->allocated_amount);
        $this->assertEquals(75000.00, (float) $a2->allocated_amount);
        $this->assertEquals(20000.00, (float) $a1->estimated_paid);
        $this->assertEquals(60000.00, (float) $a2->estimated_paid);
        $this->assertEquals(5000.00, (float) $a1->true_up_amount);
        $this->assertEquals(15000.00, (float) $a2->true_up_amount);
    }

    public function test_cam_bill_creates_idempotent_charge(): void
    {
        $operator = Operator::create([
            'name' => 'CAM Op', 'slug' => 'cam2-' . uniqid(), 'primary_color' => '#000', 'is_active' => true,
        ]);
        $asset = Asset::create([
            'operator_id' => $operator->id, 'name' => 'A', 'code' => 'CA-' . uniqid(),
            'type' => 'mall', 'city' => 'Cairo', 'country' => 'EG',
            'total_area_sqm' => 500, 'leasable_area_sqm' => 500,
            'currency' => 'EGP', 'is_active' => true,
        ]);
        $unit = Unit::create(['asset_id' => $asset->id, 'code' => 'U-' . uniqid(), 'area_sqm' => 500, 'status' => 'occupied']);
        $tenant = Tenant::create(['name' => 'T', 'email' => uniqid() . '@t.test', 'status' => 'active']);
        $lease = Lease::create([
            'reference' => 'L-' . uniqid(),
            'unit_id' => $unit->id, 'tenant_id' => $tenant->id, 'status' => 'active',
            'commencement_date' => '2026-01-01', 'expiry_date' => '2026-12-31', 'term_months' => 12,
            'base_rent_monthly' => 5000, 'service_charge_monthly' => 1000, 'currency' => 'EGP',
            'payment_terms_days' => 7,
        ]);

        $pool = CamExpensePool::create([
            'asset_id' => $asset->id, 'period_year' => 2026,
            'total_actual_expense' => 50000, 'total_estimated_collected' => 40000,
            'status' => 'draft',
        ]);

        $svc = app(CamReconciliationService::class);
        $svc->generateAllocations($pool);
        $allocation = CamAllocation::where('lease_id', $lease->id)->first();

        $svc->bill($allocation);
        $svc->bill($allocation->refresh());

        $this->assertEquals('billed', $allocation->refresh()->status);
        $this->assertEquals(1, Charge::where('lease_id', $lease->id)->where('type', 'other')->count());
    }
}
