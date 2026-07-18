<?php

use App\Filament\Admin\Resources\CamExpensePools\CamExpensePoolResource;
use App\Filament\Admin\Resources\CreditNotes\CreditNoteResource;
use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Filament\Admin\Resources\Leases\LeaseResource;
use App\Filament\Admin\Resources\TenantRequests\TenantRequestResource;
use App\Filament\Admin\Resources\Payments\PaymentResource;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Filament\Admin\Resources\TenantSalesDeclarations\TenantSalesDeclarationResource;
use App\Filament\Admin\Resources\Units\UnitResource;
use App\Filament\Admin\Resources\UtilityMeters\UtilityMeterResource;
use App\Models\Asset;
use App\Models\CamExpensePool;
use App\Models\CreditNote;
use App\Models\Payment;
use App\Models\TenantRequest;
use App\Models\TenantSalesDeclaration;
use App\Models\UtilityMeter;
use Database\Seeders\RolesPermissionsSeeder;

beforeEach(function () {
    // Roles must be real here. This file asserts PROPERTY scoping using a super_admin as the
    // "unrestricted user" — but makeUser('super_admin') only creates the role; without the seeder
    // it carries no permissions. That was harmless while scoping was purely structural, and became
    // visible when FR-USR-04 put a permission check in the query layer (AssignmentScope restricts
    // whoever lacks `*.view_all`, which fails closed — correctly — for a user holding nothing).
    // Seeding makes the fixture mean what it says.
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->hw = makeAsset(['code' => 'HW']);
    $this->pa = makeAsset(['code' => 'PA']);

    $this->hwUnit = makeUnit($this->hw, ['code' => 'HW-01']);
    $this->paUnit = makeUnit($this->pa, ['code' => 'PA-01']);

    $this->hwLease = makeLease($this->hwUnit);
    $this->paLease = makeLease($this->paUnit);

    $this->hwInvoice = makeInvoice($this->hwLease);
    $this->paInvoice = makeInvoice($this->paLease);
});

describe('Unit scoping', function () {
    it('shows only HW units inside HW context', function () {
        asTenant($this->hw, function () {
            $codes = scopedResourceQuery(UnitResource::class)->pluck('code')->all();
            expect($codes)->toContain('HW-01')->not->toContain('PA-01');
        });
    });

    it('shows only PA units inside PA context', function () {
        asTenant($this->pa, function () {
            $codes = scopedResourceQuery(UnitResource::class)->pluck('code')->all();
            expect($codes)->toContain('PA-01')->not->toContain('HW-01');
        });
    });

    it('shows every unit inside All Properties context', function () {
        $all = Asset::where('code', 'ALL')->first();
        asTenant($all, function () {
            $codes = scopedResourceQuery(UnitResource::class)->pluck('code')->all();
            expect($codes)->toContain('HW-01')->toContain('PA-01');
        });
    });
});

describe('Lease scoping', function () {
    it('scopes leases to the current property', function () {
        asTenant($this->hw, function () {
            $ids = scopedResourceQuery(LeaseResource::class)->pluck('id')->all();
            expect($ids)->toContain($this->hwLease->id)->not->toContain($this->paLease->id);
        });
    });

    it('shows all leases under All Properties', function () {
        $all = Asset::where('code', 'ALL')->first();
        asTenant($all, function () {
            $ids = scopedResourceQuery(LeaseResource::class)->pluck('id')->all();
            expect($ids)->toContain($this->hwLease->id)->toContain($this->paLease->id);
        });
    });
});

describe('Invoice scoping', function () {
    it('scopes invoices via lease.unit.asset_id', function () {
        asTenant($this->hw, function () {
            $ids = scopedResourceQuery(InvoiceResource::class)->pluck('id')->all();
            expect($ids)->toContain($this->hwInvoice->id)->not->toContain($this->paInvoice->id);
        });
    });
});

describe('Payment scoping', function () {
    it('scopes payments via invoice→lease→unit', function () {
        $payment = Payment::create([
            'reference' => 'PAY-'.uniqid(),
            'tenant_id' => $this->hwInvoice->tenant_id,
            'payment_date' => '2026-02-15',
            'amount' => 1000,
            'method' => 'cash',
            'status' => 'captured',
            'currency' => 'EGP',
        ]);
        $payment->invoices()->attach($this->hwInvoice->id, ['allocated_amount' => 1000]);

        asTenant($this->hw, function () use ($payment) {
            $ids = scopedResourceQuery(PaymentResource::class)->pluck('id')->all();
            expect($ids)->toContain($payment->id);
        });

        asTenant($this->pa, function () use ($payment) {
            $ids = scopedResourceQuery(PaymentResource::class)->pluck('id')->all();
            expect($ids)->not->toContain($payment->id);
        });
    });
});

describe('CreditNote scoping', function () {
    it('scopes credit notes via lease.unit and always shows standalone ones', function () {
        $linked = CreditNote::create([
            'number' => 'CN-LINKED-'.uniqid(),
            'tenant_id' => $this->hwInvoice->tenant_id,
            'lease_id' => $this->hwLease->id,
            'reason' => 'adjustment',
            'status' => 'draft',
            'issue_date' => '2026-02-01',
            'subtotal' => 100, 'vat_amount' => 14, 'total' => 114,
            'applied_amount' => 0, 'balance' => 114,
            'currency' => 'EGP',
        ]);

        $standalone = CreditNote::create([
            'number' => 'CN-STAND-'.uniqid(),
            'tenant_id' => $this->paInvoice->tenant_id,
            'lease_id' => null,
            'reason' => 'adjustment',
            'status' => 'draft',
            'issue_date' => '2026-02-01',
            'subtotal' => 100, 'vat_amount' => 14, 'total' => 114,
            'applied_amount' => 0, 'balance' => 114,
            'currency' => 'EGP',
        ]);

        asTenant($this->hw, function () use ($linked, $standalone) {
            $ids = scopedResourceQuery(CreditNoteResource::class)->pluck('id')->all();
            expect($ids)->toContain($linked->id)->toContain($standalone->id);
        });

        asTenant($this->pa, function () use ($linked, $standalone) {
            $ids = scopedResourceQuery(CreditNoteResource::class)->pluck('id')->all();
            expect($ids)->not->toContain($linked->id)->toContain($standalone->id);
        });
    });
});

describe('TenantRequest scoping', function () {
    it('scopes requests via unit.asset_id', function () {
        $hwReq = TenantRequest::create([
            'reference' => 'MR-HW-'.uniqid(),
            'unit_id' => $this->hwUnit->id,
            'tenant_id' => $this->hwLease->tenant_id,
            'title' => 'AC broken',
            'description' => 'Needs urgent fix',
            'status' => 'submitted',
            'priority' => 'high',
            'category' => 'hvac',
            'submitted_at' => now(),
        ]);

        $paReq = TenantRequest::create([
            'reference' => 'MR-PA-'.uniqid(),
            'unit_id' => $this->paUnit->id,
            'tenant_id' => $this->paLease->tenant_id,
            'title' => 'Lights out',
            'description' => 'Replace fluorescent tubes',
            'status' => 'submitted',
            'priority' => 'medium',
            'category' => 'electrical',
            'submitted_at' => now(),
        ]);

        asTenant($this->hw, function () use ($hwReq, $paReq) {
            $ids = scopedResourceQuery(TenantRequestResource::class)->pluck('id')->all();
            expect($ids)->toContain($hwReq->id)->not->toContain($paReq->id);
        });
    });
});

describe('Tenant scoping', function () {
    it('shows only tenants who have a lease in the current property', function () {
        asTenant($this->hw, function () {
            $ids = scopedResourceQuery(TenantResource::class)->pluck('id')->all();
            expect($ids)->toContain($this->hwLease->tenant_id)->not->toContain($this->paLease->tenant_id);
        });
    });

    it('keeps a lease-less (just-created) tenant visible + resolvable in a property context', function () {
        // A freshly created tenant has no lease yet. It must stay visible so the
        // list shows it and the post-create edit redirect resolves (not 404).
        $orphan = makeTenant();

        asTenant($this->hw, function () use ($orphan) {
            $ids = scopedResourceQuery(TenantResource::class)->pluck('id')->all();
            expect($ids)
                ->toContain($orphan->id)
                ->toContain($this->hwLease->tenant_id)
                ->not->toContain($this->paLease->tenant_id);

            // The edit page resolves the record through this query — confirm it finds it.
            expect(TenantResource::getEloquentQuery()->whereKey($orphan->id)->exists())->toBeTrue();
        });
    });
});

describe('UtilityMeter scoping', function () {
    it('scopes meters via direct asset_id FK', function () {
        $hwMeter = UtilityMeter::create([
            'asset_id' => $this->hw->id,
            'meter_number' => 'HW-EL-01',
            'type' => 'electric',
            'status' => 'active',
        ]);

        $paMeter = UtilityMeter::create([
            'asset_id' => $this->pa->id,
            'meter_number' => 'PA-EL-01',
            'type' => 'electric',
            'status' => 'active',
        ]);

        asTenant($this->hw, function () use ($hwMeter, $paMeter) {
            $ids = scopedResourceQuery(UtilityMeterResource::class)->pluck('id')->all();
            expect($ids)->toContain($hwMeter->id)->not->toContain($paMeter->id);
        });

        $all = Asset::where('code', 'ALL')->first();
        asTenant($all, function () use ($hwMeter, $paMeter) {
            $ids = scopedResourceQuery(UtilityMeterResource::class)->pluck('id')->all();
            expect($ids)->toContain($hwMeter->id)->toContain($paMeter->id);
        });
    });
});

describe('CamExpensePool scoping', function () {
    it('scopes CAM pools via direct asset_id FK', function () {
        $hwPool = CamExpensePool::create([
            'asset_id' => $this->hw->id,
            'period_year' => 2026,
            'total_actual_expense' => 50000,
            'total_estimated_collected' => 40000,
            'status' => 'draft',
        ]);

        $paPool = CamExpensePool::create([
            'asset_id' => $this->pa->id,
            'period_year' => 2026,
            'total_actual_expense' => 10000,
            'total_estimated_collected' => 8000,
            'status' => 'draft',
        ]);

        asTenant($this->hw, function () use ($hwPool, $paPool) {
            $ids = scopedResourceQuery(CamExpensePoolResource::class)->pluck('id')->all();
            expect($ids)->toContain($hwPool->id)->not->toContain($paPool->id);
        });
    });
});

describe('TenantSalesDeclaration scoping', function () {
    it('scopes declarations via lease.unit.asset_id', function () {
        $hwDecl = TenantSalesDeclaration::create([
            'lease_id' => $this->hwLease->id,
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'declared_sales' => 50000,
            'calculated_percentage_rent' => 0,
            'status' => 'submitted',
            'declared_at' => now(),
        ]);

        $paDecl = TenantSalesDeclaration::create([
            'lease_id' => $this->paLease->id,
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'declared_sales' => 20000,
            'calculated_percentage_rent' => 0,
            'status' => 'submitted',
            'declared_at' => now(),
        ]);

        asTenant($this->hw, function () use ($hwDecl, $paDecl) {
            $ids = scopedResourceQuery(TenantSalesDeclarationResource::class)->pluck('id')->all();
            expect($ids)->toContain($hwDecl->id)->not->toContain($paDecl->id);
        });
    });
});
