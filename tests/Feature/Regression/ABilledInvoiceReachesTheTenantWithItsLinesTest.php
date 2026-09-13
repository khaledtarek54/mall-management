<?php

use App\Models\Invoice;
use App\Services\InvoicePdfService;
use App\Services\LeaseCreationService;
use App\Services\MonthlyBillingService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * The invoice the billing run hands on — to the tenant's e-mail, to whoever reads `->items` off
 * it — carries its lines (2026-09-13, found while re-running the proration tests).
 *
 * `CashBalanceGuard` (2026-09-12) evaluates every posting source on `creating`, and evaluating an
 * invoice means the journalizer's `loadMissing('items')` — on a model whose lines do not exist
 * yet, which cached an EMPTY collection on the instance `IssueInvoiceService` then returned.
 * `MonthlyBillingService::notifyInvoiceIssued()` mails a PDF of that instance, and
 * `InvoicePdfService::viewData()` reaches for `items` with `loadMissing` too, so the tenant's
 * copy rendered the header totals over NO lines. Every test read `$invoice->fresh()` and saw the
 * database; the five that read the returned instance went red the day the guard shipped.
 *
 * A guard is a read; the fix is that its evaluation leaves no relation cache behind.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'LNS']);
});

it('bills an invoice whose returned instance, and whose PDF, carry the lines the run wrote', function () {
    $unit = makeUnit($this->asset, ['code' => 'LNS-01', 'status' => 'vacant']);
    $lease = makeLease($unit, makeTenant(), [
        'commencement_date' => '2027-01-01', 'expiry_date' => '2029-12-31',
        'base_rent_monthly' => 100000, 'service_charge_monthly' => 15000,
        'has_marketing_levy' => false, 'escalation_type' => 'none', 'escalation_rate' => 0,
    ]);
    LeaseCreationService::seedStandardCharges($lease, rent: 100000, service: 15000);

    $result = app(MonthlyBillingService::class)->generateForLease($lease->fresh(), CarbonImmutable::parse('2027-04-01'), prorate: true);

    /** @var Invoice $invoice */
    $invoice = $result['invoice'];

    expect($result['status'])->toBe('created')
        ->and($invoice->items->pluck('type')->sort()->values()->all())->toBe(['base_rent', 'service_charge'])
        ->and(app(InvoicePdfService::class)->viewData($invoice)['invoice']->items)->toHaveCount(2);
});

it('leaves no relation cache on a document it evaluated while UPDATING either — the sealed-period twin', function () {
    // The `updating` guard (`SealedPeriod`) asks the journalizer on a draft becoming postable, and
    // that read cached the lines as they stood — measured: raised to `issued`, given a second
    // line, the instance answered 1 against 2 in the database.
    $unit = makeUnit($this->asset, ['code' => 'LNS-03', 'status' => 'vacant']);
    $lease = makeLease($unit, makeTenant(), ['commencement_date' => '2027-01-01', 'expiry_date' => '2029-12-31']);

    $invoice = Invoice::create([
        'lease_id' => $lease->id, 'tenant_id' => $lease->tenant_id, 'asset_id' => $this->asset->id,
        'issue_date' => now()->toDateString(), 'due_date' => now()->addDays(7)->toDateString(),
        'period_start' => now()->startOfMonth()->toDateString(), 'period_end' => now()->endOfMonth()->toDateString(),
        'status' => 'draft', 'subtotal' => 0, 'vat_amount' => 0, 'total' => 0, 'paid_amount' => 0, 'balance' => 0, 'currency' => 'EGP',
    ]);
    \App\Models\InvoiceItem::create(['invoice_id' => $invoice->id, 'description' => 'Line 1', 'type' => 'base_rent', 'amount' => 100, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 100]);
    $invoice = $invoice->fresh();

    $invoice->update(['status' => 'issued']);
    \App\Models\InvoiceItem::create(['invoice_id' => $invoice->id, 'description' => 'Line 2', 'type' => 'service_charge', 'amount' => 50, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 50]);

    expect($invoice->relationLoaded('items'))->toBeFalse()
        ->and($invoice->items->count())->toBe(2);
});

it('leaves no relation cache on a document it evaluated at creation', function () {
    $unit = makeUnit($this->asset, ['code' => 'LNS-02', 'status' => 'vacant']);
    $lease = makeLease($unit, makeTenant(), ['commencement_date' => '2027-01-01', 'expiry_date' => '2029-12-31']);

    $invoice = Invoice::create([
        'lease_id' => $lease->id, 'tenant_id' => $lease->tenant_id, 'asset_id' => $this->asset->id,
        'issue_date' => '2027-04-01', 'due_date' => '2027-04-15', 'period_start' => '2027-04-01', 'period_end' => '2027-04-30',
        'status' => 'issued', 'subtotal' => 0, 'vat_amount' => 0, 'total' => 0, 'paid_amount' => 0, 'balance' => 0, 'currency' => 'EGP',
    ]);

    expect($invoice->relationLoaded('items'))->toBeFalse();
});
