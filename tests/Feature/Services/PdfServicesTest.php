<?php

use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Services\InvoicePdfService;
use App\Services\TenantStatementPdfService;

beforeEach(function () {
    $this->asset = makeAsset();
    $this->unit = makeUnit($this->asset);
    $this->tenant = makeTenant();
    $this->lease = makeLease($this->unit, $this->tenant);
});

it('InvoicePdfService builds a PDF binary', function () {
    $invoice = makeInvoice($this->lease);
    InvoiceItem::create([
        'invoice_id' => $invoice->id, 'type' => 'base_rent',
        'description' => 'Rent', 'amount' => 10000,
        'vat_rate' => 0, 'vat_amount' => 0,
    ]);

    $pdf = app(InvoicePdfService::class)->build($invoice);

    expect(substr($pdf, 0, 5))->toBe('%PDF-');
    expect(strlen($pdf))->toBeGreaterThan(1000);
});

it('InvoicePdfService renders RTL when locale is Arabic', function () {
    $invoice = makeInvoice($this->lease);
    app()->setLocale('ar');

    try {
        $pdf = app(InvoicePdfService::class)->build($invoice);
        expect(substr($pdf, 0, 5))->toBe('%PDF-');
    } finally {
        app()->setLocale('en');
    }
});

it('InvoicePdfService filename is invoice number + .pdf', function () {
    $invoice = makeInvoice($this->lease);

    expect(app(InvoicePdfService::class)->filename($invoice))
        ->toBe($invoice->number.'.pdf');
});

it('TenantStatementPdfService builds a PDF with summary, open + recent invoices, payments', function () {
    // One open invoice (counted in outstanding + overdue once past due)
    makeInvoice($this->lease, [
        'status' => 'issued',
        'issue_date' => now()->subMonths(2)->toDateString(),
        'due_date' => now()->subMonth()->toDateString(),
        'balance' => 5000, 'paid_amount' => 0, 'total' => 5000,
    ]);
    // One paid invoice (closes the balance side; still in recent)
    makeInvoice($this->lease, [
        'status' => 'paid',
        'issue_date' => now()->subMonths(1)->toDateString(),
        'balance' => 0, 'paid_amount' => 10000, 'total' => 10000,
    ]);
    Payment::create([
        'tenant_id' => $this->tenant->id,
        'reference' => 'P-'.uniqid(),
        'amount' => 10000,
        'method' => 'bank_transfer',
        'status' => 'captured',
        'currency' => 'EGP',
        'payment_date' => now()->subDays(20),
    ]);

    $pdf = app(TenantStatementPdfService::class)->build($this->tenant);

    expect(substr($pdf, 0, 5))->toBe('%PDF-');
    expect(strlen($pdf))->toBeGreaterThan(1000);
});

it('TenantStatementPdfService renders RTL when locale is Arabic', function () {
    app()->setLocale('ar');

    try {
        $pdf = app(TenantStatementPdfService::class)->build($this->tenant);
        expect(substr($pdf, 0, 5))->toBe('%PDF-');
    } finally {
        app()->setLocale('en');
    }
});

it('TenantStatementPdfService filename is slugged tenant name + dated', function () {
    $this->tenant->update(['name' => 'Acme Coffee Co']);

    $name = app(TenantStatementPdfService::class)->filename($this->tenant);

    expect($name)->toStartWith('Statement-acme-coffee-co-');
    expect($name)->toEndWith('.pdf');
});
