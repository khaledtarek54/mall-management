<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\Unit;
use App\Services\Reports\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private function scaffoldLease(): Lease
    {
        $asset = Asset::create([
            'name' => 'A', 'code' => 'A-' . uniqid(),
            'type' => 'mall', 'city' => 'Cairo', 'country' => 'EG',
            'total_area_sqm' => 100, 'leasable_area_sqm' => 100,
            'currency' => 'EGP', 'is_active' => true,
        ]);
        $unit = Unit::create(['asset_id' => $asset->id, 'code' => 'U-' . uniqid(), 'area_sqm' => 100, 'status' => 'occupied']);
        $tenant = Tenant::create(['name' => 'T', 'email' => uniqid() . '@t.test', 'status' => 'active']);
        return Lease::create([
            'reference' => 'L-' . uniqid(), 'unit_id' => $unit->id, 'tenant_id' => $tenant->id,
            'status' => 'active', 'commencement_date' => '2026-01-01', 'expiry_date' => '2026-12-31',
            'term_months' => 12, 'base_rent_monthly' => 5000, 'currency' => 'EGP', 'payment_terms_days' => 7,
        ]);
    }

    private function makeInvoice(Lease $lease, array $overrides = []): Invoice
    {
        return Invoice::create(array_merge([
            'lease_id' => $lease->id, 'tenant_id' => $lease->tenant_id,
            'status' => 'issued', 'issue_date' => '2026-02-01', 'due_date' => '2026-02-10',
            'period_start' => '2026-02-01', 'period_end' => '2026-02-28',
            'subtotal' => 10000, 'vat_amount' => 1400, 'total' => 11400,
            'paid_amount' => 0, 'balance' => 11400, 'currency' => 'EGP',
        ], $overrides));
    }

    public function test_monthly_close_aggregates_invoices_payments_vat(): void
    {
        $lease = $this->scaffoldLease();

        // Two invoices in Feb 2026: one paid, one issued
        $paidInvoice = $this->makeInvoice($lease, [
            'issue_date' => '2026-02-05', 'status' => 'paid',
            'subtotal' => 10000, 'vat_amount' => 1400, 'total' => 11400,
            'paid_amount' => 11400, 'balance' => 0,
        ]);
        $openInvoice = $this->makeInvoice($lease, [
            'issue_date' => '2026-02-15', 'status' => 'issued',
            'subtotal' => 5000, 'vat_amount' => 700, 'total' => 5700,
            'paid_amount' => 0, 'balance' => 5700,
        ]);

        // A payment in Feb 2026
        Payment::create([
            'reference' => 'P-1', 'tenant_id' => $lease->tenant_id,
            'amount' => 11400, 'currency' => 'EGP', 'method' => 'card',
            'status' => 'captured', 'payment_date' => '2026-02-06',
        ]);

        $report = app(ReportService::class)->monthlyClose(CarbonImmutable::parse('2026-02-01'));

        $this->assertEquals('2026-02', $report['period']);
        $this->assertEquals(2, $report['invoices']['count']);
        $this->assertEquals(17100.0, (float) $report['invoices']['total']); // 11400 + 5700
        $this->assertEquals(2100.0, (float) $report['invoices']['vat']);    // 1400 + 700
        $this->assertEquals(1, $report['payments']['count']);
        $this->assertEquals(11400.0, (float) $report['payments']['total']);
        $this->assertEquals(['card' => 11400.0], $report['payments']['by_method']);
        // 11400 collected / 17100 invoiced = 66.7%
        $this->assertEquals(66.7, $report['collections_rate']);
    }

    public function test_ar_aging_buckets_classify_by_days_overdue(): void
    {
        $lease = $this->scaffoldLease();

        // Today is 2026-06-15 for the test
        $asOf = CarbonImmutable::parse('2026-06-15');

        // Current: due in the future
        $this->makeInvoice($lease, [
            'issue_date' => '2026-06-01', 'due_date' => '2026-06-30',
            'balance' => 1000, 'status' => 'issued',
        ]);
        // 1-30 days overdue
        $this->makeInvoice($lease, [
            'issue_date' => '2026-05-01', 'due_date' => '2026-05-25',
            'balance' => 2000, 'status' => 'issued',
        ]);
        // 31-60
        $this->makeInvoice($lease, [
            'issue_date' => '2026-04-01', 'due_date' => '2026-04-20',
            'balance' => 3000, 'status' => 'overdue',
        ]);
        // 90+
        $this->makeInvoice($lease, [
            'issue_date' => '2026-01-01', 'due_date' => '2026-01-20',
            'balance' => 5000, 'status' => 'overdue',
        ]);

        $buckets = app(ReportService::class)->arAgingBuckets($asOf);

        $this->assertEquals(1, $buckets['current']['count']);
        $this->assertEquals(1000.0, $buckets['current']['total']);
        $this->assertEquals(2000.0, $buckets['d_1_30']['total']);
        $this->assertEquals(3000.0, $buckets['d_31_60']['total']);
        $this->assertEquals(0.0, $buckets['d_61_90']['total']);
        $this->assertEquals(5000.0, $buckets['d_90_plus']['total']);
    }

    public function test_revenue_by_type_aggregates_invoice_items(): void
    {
        $lease = $this->scaffoldLease();
        $invoice = $this->makeInvoice($lease, ['issue_date' => '2026-02-10']);
        InvoiceItem::create([
            'invoice_id' => $invoice->id, 'description' => 'Rent', 'type' => 'base_rent',
            'amount' => 8000, 'vat_rate' => 14, 'vat_amount' => 1120, 'total' => 9120,
        ]);
        InvoiceItem::create([
            'invoice_id' => $invoice->id, 'description' => 'Service', 'type' => 'service_charge',
            'amount' => 2000, 'vat_rate' => 14, 'vat_amount' => 280, 'total' => 2280,
        ]);

        $report = app(ReportService::class)->monthlyClose(CarbonImmutable::parse('2026-02-01'));

        $this->assertEquals(8000.0, $report['revenue_by_type']['base_rent']);
        $this->assertEquals(2000.0, $report['revenue_by_type']['service_charge']);
    }

    public function test_ar_aging_drilldown_returns_only_invoices_in_bucket(): void
    {
        $lease = $this->scaffoldLease();
        $asOf = CarbonImmutable::parse('2026-06-15');

        // In bucket 1-30
        $a = $this->makeInvoice($lease, ['issue_date' => '2026-05-01', 'due_date' => '2026-05-25', 'balance' => 100, 'status' => 'issued']);
        // In bucket 31-60
        $b = $this->makeInvoice($lease, ['issue_date' => '2026-04-01', 'due_date' => '2026-04-20', 'balance' => 200, 'status' => 'overdue']);

        $rows = app(ReportService::class)->arAgingDrilldown('d_1_30', $asOf);

        $this->assertCount(1, $rows);
        $this->assertEquals($a->id, $rows[0]->id);
    }
}
