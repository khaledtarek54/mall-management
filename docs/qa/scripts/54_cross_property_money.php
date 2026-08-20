<?php

require __DIR__.'/boot.php';
use App\Models\Asset;
use App\Models\CreditNote;
use App\Models\JournalEntry;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\Unit;
use App\Services\ApplyTenantCreditService;
use App\Services\CreditNoteService;
use App\Services\LeaseCreationService;
use App\Services\MonthlyBillingService;
use App\Services\Reconciliation\BooksReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;

$aw = Asset::where('code', 'AW')->firstOrFail();
$pa = Asset::where('code', 'PA')->firstOrFail();
$tenant = Tenant::whereDoesntHave('unitOwnerships')->firstOrFail();
$billing = app(MonthlyBillingService::class);

qa_section('CROSS-PROPERTY — one tenant trading in BOTH malls');
$mk = function (Asset $a) use ($tenant): Lease {
    $u = Unit::where('asset_id', $a->id)->where('status', 'vacant')->firstOrFail();
    $l = Lease::create(['tenant_id' => $tenant->id, 'unit_id' => $u->id,
        'reference' => 'QA-XP-'.strtoupper(bin2hex(random_bytes(3))), 'status' => 'active', 'currency' => 'EGP',
        'commencement_date' => '2026-01-01', 'expiry_date' => '2028-12-31', 'term_months' => 36,
        'base_rent_monthly' => 50000, 'service_charge_monthly' => 0, 'has_marketing_levy' => false,
        'billing_frequency' => 'monthly', 'payment_terms_days' => 7, 'escalation_type' => 'none']);
    LeaseCreationService::seedStandardCharges($l, 50000, 0, $l->commencement_date);

    return $l->fresh('charges');
};
$awLease = $mk($aw);
$paLease = $mk($pa);
$awInv = $billing->generateForLease($awLease, CarbonImmutable::parse('2026-08-01'))['invoice'];
$paInv = $billing->generateForLease($paLease, CarbonImmutable::parse('2026-08-01'))['invoice'];
qa_eq('the AW invoice carries the AW property', $aw->id, (int) $awInv->asset_id);
qa_eq('the PA invoice carries the PA property', $pa->id, (int) $paInv->asset_id);
qa_ok('…the same tenant, two properties, two receivables', $awInv->tenant_id === $paInv->tenant_id);

qa_section('a credit note raised in one mall may not settle the other mall invoice');
$cn = CreditNote::create(['tenant_id' => $tenant->id, 'lease_id' => $paLease->id, 'invoice_id' => $paInv->id,
    'asset_id' => $pa->id, 'issue_date' => '2026-08-10', 'reason' => 'adjustment', 'reason_notes' => 'QA', 'status' => 'draft',
    'subtotal' => 10000, 'vat_amount' => 0, 'total' => 10000, 'balance' => 10000]);
$cn->items()->create(['description' => 'QA', 'quantity' => 1, 'unit_price' => 10000, 'amount' => 10000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 10000]);
app(CreditNoteService::class)->issue($cn->fresh());
qa_refuses('a PA credit note is refused against an AW invoice',
    fn () => app(CreditNoteService::class)->applyToInvoice($cn->fresh(), $awInv->fresh()));
qa_eq('…and it still settles its OWN property invoice', 10000.00,
    app(CreditNoteService::class)->applyToInvoice($cn->fresh(), $paInv->fresh()));

qa_section('on-account credit does not cross a property boundary');
$smallPa = $billing->generateForLease($mk($pa), CarbonImmutable::parse('2026-07-01'))['invoice'];
$adv = Payment::create(['tenant_id' => $tenant->id, 'amount' => 80000, 'payment_date' => '2026-07-02',
    'method' => 'bank_transfer', 'status' => 'captured', 'reference' => 'QA-XPADV-'.uniqid()]);
$adv->invoices()->attach($smallPa->id, ['allocated_amount' => 50000]);
$smallPa->fresh()->recomputeTotals();
$paCredit = $tenant->fresh()->creditBalance([$pa->id]);
$awCredit = $tenant->fresh()->creditBalance([$aw->id]);
printf("  surplus 30,000 paid into PA → PA credit %s · AW credit %s · global %s\n",
    number_format($paCredit, 2), number_format($awCredit, 2), number_format($tenant->fresh()->creditBalance(null), 2));
qa_eq('the surplus sits under the property it was paid into', 30000.00, $paCredit);
qa_eq('…and is NOT available in the other mall', 0.00, $awCredit);
qa_refuses('applying it to the AW invoice is refused',
    fn () => app(ApplyTenantCreditService::class)->applyToInvoice($awInv->fresh(), 30000));

qa_section('the GL keeps the property dimension');
Artisan::call('accounting:sync-ledger', ['--all' => true]);
$awEntry = JournalEntry::where('source_type', $awInv->getMorphClass())->where('source_id', $awInv->id)->where('status', 'posted')->first();
$paEntry = JournalEntry::where('source_type', $paInv->getMorphClass())->where('source_id', $paInv->id)->where('status', 'posted')->first();
qa_eq('the AW invoice entry is filed under AW', $aw->id, (int) $awEntry?->asset_id);
qa_eq('the PA invoice entry is filed under PA', $pa->id, (int) $paEntry?->asset_id);
qa_ok('every line carries the property too',
    $awEntry->lines->every(fn ($l) => (int) $l->asset_id === $aw->id),
    $awEntry->lines->pluck('asset_id')->unique()->join(','));

qa_section('no money document is filed against NO property');
$exit = Artisan::call('atriom:audit-property-dimension');
$out = Artisan::output();
printf("  atriom:audit-property-dimension exit=%d\n%s\n", $exit, implode("\n", array_map(fn ($l) => '    '.$l, array_slice(explode("\n", trim($out)), -12))));
qa_eq('the property-dimension audit is clean', 0, $exit);

qa_section('tie-out with two properties in the book');
qa_assert_tb('AW only', $aw->id);
qa_assert_tb('PA only', $pa->id);
qa_assert_tb('whole book');
$rec = app(BooksReconciliationService::class);
$tie = $rec->glTieOut();
qa_eq('AR ties across both properties', 0.0, $tie['ar']['delta']);
qa_eq('AP ties across both properties', 0.0, $tie['ap']['delta']);

qa_summary();
