<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Charge;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Lease;
use App\Models\Operator;
use App\Models\Tenant;
use App\Models\Unit;
use App\Services\Eta\EtaJsonBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EtaJsonBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_build_produces_required_eta_fields(): void
    {
        $operator = Operator::create(['name' => 'Op', 'slug' => 'op', 'primary_color' => '#000', 'is_active' => true]);
        $asset = Asset::create([
            'operator_id' => $operator->id, 'name' => 'A', 'code' => 'A',
            'type' => 'mall', 'city' => 'Cairo', 'country' => 'EG',
            'total_area_sqm' => 100, 'leasable_area_sqm' => 100,
            'currency' => 'EGP', 'is_active' => true,
        ]);
        $unit = Unit::create(['asset_id' => $asset->id, 'code' => 'U1', 'area_sqm' => 100, 'status' => 'occupied']);
        $tenant = Tenant::create([
            'name' => 'Acme Co', 'legal_name' => 'Acme Trading LLC',
            'email' => 'acme@test.local', 'tax_id' => '111-222-333',
            'type' => 'company', 'status' => 'active',
            'address' => '5 Tahrir St',
        ]);
        $lease = Lease::create([
            'reference' => 'LSE-001', 'unit_id' => $unit->id, 'tenant_id' => $tenant->id,
            'status' => 'active', 'commencement_date' => '2026-01-01',
            'expiry_date' => '2027-01-01', 'term_months' => 12,
            'base_rent_monthly' => 10000, 'currency' => 'EGP', 'payment_terms_days' => 7,
        ]);

        $invoice = Invoice::create([
            'number' => 'INV-ETA-001', 'lease_id' => $lease->id, 'tenant_id' => $tenant->id,
            'status' => 'issued', 'issue_date' => '2026-02-01', 'due_date' => '2026-02-08',
            'period_start' => '2026-02-01', 'period_end' => '2026-02-28',
            'subtotal' => 10000, 'vat_amount' => 1400, 'total' => 11400,
            'paid_amount' => 0, 'balance' => 11400, 'currency' => 'EGP',
        ]);
        $charge = Charge::create([
            'lease_id' => $lease->id, 'name' => 'Rent', 'type' => 'base_rent',
            'amount' => 10000, 'currency' => 'EGP', 'frequency' => 'monthly',
            'vat_applicable' => true, 'vat_rate' => 14, 'start_date' => '2026-01-01',
            'is_active' => true,
        ]);
        InvoiceItem::create([
            'invoice_id' => $invoice->id, 'charge_id' => $charge->id,
            'description' => 'Rent Feb',
            'type' => 'base_rent', 'amount' => 10000, 'vat_rate' => 14,
            'vat_amount' => 1400, 'total' => 11400,
        ]);

        $invoice->refresh();
        $doc = app(EtaJsonBuilder::class)->build($invoice->fresh(['lease.tenant', 'items']));

        $this->assertEquals('i', $doc['documentType']);
        $this->assertEquals('1.0', $doc['documentTypeVersion']);
        $this->assertEquals($invoice->number, $doc['internalID']);
        $this->assertEquals('B', $doc['receiver']['type'], 'company tenant maps to B');
        $this->assertEquals('111-222-333', $doc['receiver']['id']);
        $this->assertEquals('Acme Trading LLC', $doc['receiver']['name']);
        $this->assertCount(1, $doc['invoiceLines']);
        $this->assertEquals(11400.0, $doc['totalAmount']);
        $this->assertEquals(10000.0, $doc['netAmount']);
        $this->assertEquals('T1', $doc['taxTotals'][0]['taxType']);
        $this->assertEquals(1400.0, $doc['taxTotals'][0]['amount']);
        // EGS line code mapping
        $this->assertEquals('EG-6820-001', $doc['invoiceLines'][0]['itemCode']);
        $this->assertEquals('V009', $doc['invoiceLines'][0]['taxableItems'][0]['subType']);
    }

    public function test_individual_tenant_maps_to_person_type(): void
    {
        $operator = Operator::create(['name' => 'Op', 'slug' => 'op-i', 'primary_color' => '#000', 'is_active' => true]);
        $asset = Asset::create([
            'operator_id' => $operator->id, 'name' => 'A', 'code' => 'AI',
            'type' => 'mall', 'city' => 'Cairo', 'country' => 'EG',
            'total_area_sqm' => 100, 'leasable_area_sqm' => 100,
            'currency' => 'EGP', 'is_active' => true,
        ]);
        $unit = Unit::create(['asset_id' => $asset->id, 'code' => 'U2', 'area_sqm' => 100, 'status' => 'occupied']);
        $tenant = Tenant::create([
            'name' => 'Sara', 'email' => 's@test.local',
            'type' => 'individual', 'status' => 'active',
        ]);
        $lease = Lease::create([
            'reference' => 'LSE-002', 'unit_id' => $unit->id, 'tenant_id' => $tenant->id,
            'status' => 'active', 'commencement_date' => '2026-01-01',
            'expiry_date' => '2027-01-01', 'term_months' => 12,
            'base_rent_monthly' => 5000, 'currency' => 'EGP', 'payment_terms_days' => 7,
        ]);
        $invoice = Invoice::create([
            'number' => 'INV-ETA-002', 'lease_id' => $lease->id, 'tenant_id' => $tenant->id,
            'status' => 'issued', 'issue_date' => '2026-02-01', 'due_date' => '2026-02-08',
            'period_start' => '2026-02-01', 'period_end' => '2026-02-28',
            'subtotal' => 5000, 'vat_amount' => 0, 'total' => 5000,
            'paid_amount' => 0, 'balance' => 5000, 'currency' => 'EGP',
        ]);
        InvoiceItem::create([
            'invoice_id' => $invoice->id, 'description' => 'Rent Feb',
            'type' => 'base_rent', 'amount' => 5000, 'vat_rate' => 0,
            'vat_amount' => 0, 'total' => 5000,
        ]);

        $doc = app(EtaJsonBuilder::class)->build($invoice->fresh(['lease.tenant', 'items']));

        $this->assertEquals('P', $doc['receiver']['type']);
        $this->assertEquals([], $doc['taxTotals'], 'no VAT line should produce empty taxTotals');
    }
}
