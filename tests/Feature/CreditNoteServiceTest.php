<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\CreditNote;
use App\Models\CreditNoteItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Lease;
use App\Models\Tenant;
use App\Models\Unit;
use App\Services\CreditNoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreditNoteServiceTest extends TestCase
{
    use RefreshDatabase;

    private function scaffold(): array
    {
        $asset = Asset::create([
            'name' => 'A', 'code' => 'A-' . uniqid(),
            'type' => 'mall', 'city' => 'Cairo', 'country' => 'EG',
            'total_area_sqm' => 100, 'leasable_area_sqm' => 100,
            'currency' => 'EGP', 'is_active' => true,
        ]);
        $unit = Unit::create(['asset_id' => $asset->id, 'code' => 'U-' . uniqid(), 'area_sqm' => 100, 'status' => 'occupied']);
        $tenant = Tenant::create(['name' => 'T', 'email' => uniqid() . '@t.test', 'status' => 'active']);
        $lease = Lease::create([
            'reference' => 'L-' . uniqid(), 'unit_id' => $unit->id, 'tenant_id' => $tenant->id,
            'status' => 'active', 'commencement_date' => '2026-01-01', 'expiry_date' => '2026-12-31',
            'term_months' => 12, 'base_rent_monthly' => 5000, 'currency' => 'EGP', 'payment_terms_days' => 7,
        ]);

        $invoice = Invoice::create([
            'lease_id' => $lease->id, 'tenant_id' => $tenant->id,
            'status' => 'issued', 'issue_date' => '2026-02-01', 'due_date' => '2026-02-08',
            'period_start' => '2026-02-01', 'period_end' => '2026-02-28',
            'subtotal' => 10000, 'vat_amount' => 0, 'total' => 10000,
            'paid_amount' => 0, 'balance' => 10000, 'currency' => 'EGP',
        ]);
        InvoiceItem::create([
            'invoice_id' => $invoice->id, 'description' => 'Rent', 'type' => 'base_rent',
            'amount' => 10000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 10000,
        ]);

        return compact('tenant', 'invoice', 'lease');
    }

    private function makeNote(int $tenantId, int $invoiceId, float $total): CreditNote
    {
        $note = CreditNote::create([
            'tenant_id' => $tenantId, 'invoice_id' => $invoiceId,
            'status' => 'draft', 'issue_date' => '2026-02-15',
            'reason' => 'adjustment',
            'subtotal' => $total, 'vat_amount' => 0,
            'total' => $total, 'applied_amount' => 0, 'balance' => $total,
            'currency' => 'EGP',
        ]);
        CreditNoteItem::create([
            'credit_note_id' => $note->id,
            'description' => 'Adjustment',
            'amount' => $total, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => $total,
        ]);
        return $note->refresh();
    }

    public function test_issue_moves_draft_to_issued_and_sets_balance(): void
    {
        ['tenant' => $t, 'invoice' => $i] = $this->scaffold();
        $note = $this->makeNote($t->id, $i->id, 3000);

        $issued = app(CreditNoteService::class)->issue($note);

        $this->assertEquals('issued', $issued->status);
        $this->assertEquals(3000.0, (float) $issued->balance);
    }

    public function test_apply_to_invoice_reduces_invoice_balance_and_note_balance(): void
    {
        ['tenant' => $t, 'invoice' => $i] = $this->scaffold();
        $svc = app(CreditNoteService::class);
        $note = $svc->issue($this->makeNote($t->id, $i->id, 3000));

        $applied = $svc->applyToInvoice($note->fresh(), $i->fresh(), 1500);

        $this->assertEquals(1500.0, $applied);
        $this->assertEquals(1500.0, (float) $note->fresh()->applied_amount);
        $this->assertEquals(1500.0, (float) $note->fresh()->balance);
        $this->assertEquals('issued', $note->fresh()->status);

        $iAfter = $i->fresh();
        $this->assertEquals(1500.0, (float) $iAfter->paid_amount);
        $this->assertEquals(8500.0, (float) $iAfter->balance);
        $this->assertEquals('partially_paid', $iAfter->status);
    }

    public function test_apply_caps_at_minimum_of_note_and_invoice_balance(): void
    {
        ['tenant' => $t, 'invoice' => $i] = $this->scaffold();
        $svc = app(CreditNoteService::class);
        $note = $svc->issue($this->makeNote($t->id, $i->id, 15000)); // bigger than the 10000 invoice

        $applied = $svc->applyToInvoice($note->fresh(), $i->fresh()); // no requested cap

        $this->assertEquals(10000.0, $applied, 'caps at invoice balance');
        $this->assertEquals(5000.0, (float) $note->fresh()->balance);
        $this->assertEquals('paid', $i->fresh()->status);
        $this->assertEquals(0.0, (float) $i->fresh()->balance);
    }

    public function test_apply_zero_when_voided(): void
    {
        ['tenant' => $t, 'invoice' => $i] = $this->scaffold();
        $svc = app(CreditNoteService::class);
        $note = $svc->issue($this->makeNote($t->id, $i->id, 2000));
        $svc->void($note->fresh());

        $applied = $svc->applyToInvoice($note->fresh(), $i->fresh());

        $this->assertEquals(0.0, $applied);
        $this->assertEquals(10000.0, (float) $i->fresh()->balance, 'invoice untouched');
    }

    public function test_void_throws_when_already_applied(): void
    {
        ['tenant' => $t, 'invoice' => $i] = $this->scaffold();
        $svc = app(CreditNoteService::class);
        $note = $svc->issue($this->makeNote($t->id, $i->id, 2000));
        $svc->applyToInvoice($note->fresh(), $i->fresh(), 500);

        $this->expectException(\DomainException::class);
        $svc->void($note->fresh());
    }

    public function test_fully_applied_note_status_flips_to_applied(): void
    {
        ['tenant' => $t, 'invoice' => $i] = $this->scaffold();
        $svc = app(CreditNoteService::class);
        $note = $svc->issue($this->makeNote($t->id, $i->id, 2000));

        $svc->applyToInvoice($note->fresh(), $i->fresh(), 2000);

        $this->assertEquals('applied', $note->fresh()->status);
        $this->assertEquals(0.0, (float) $note->fresh()->balance);
    }
}
