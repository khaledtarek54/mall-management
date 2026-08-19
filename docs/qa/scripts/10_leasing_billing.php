<?php

require __DIR__.'/boot.php';
use App\Models\Asset;
use App\Models\Charge;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Tenant;
use App\Models\Unit;
use App\Services\MonthlyBillingService;
use App\Support\Vat;
use Carbon\CarbonImmutable;

$billing = app(MonthlyBillingService::class);
$asset = Asset::where('code', 'AW')->firstOrFail();
$tenant = Tenant::where('name', 'not like', '%Owner%')->whereDoesntHave('unitOwnerships')->firstOrFail();
$freeUnits = Unit::where('asset_id', $asset->id)->where('status', 'vacant')->orderBy('id')->get();
$n = 0;
$mkLease = function (array $attrs) use (&$n, $freeUnits, $asset, $tenant): Lease {
    $u = $freeUnits[$n++];

    return Lease::create(array_merge([
        'asset_id' => $asset->id, 'tenant_id' => $tenant->id, 'unit_id' => $u->id,
        'reference' => 'QA-'.strtoupper(bin2hex(random_bytes(3))), 'status' => 'active',
        'currency' => 'EGP', 'billing_frequency' => 'monthly', 'billing_day' => 1,
        'payment_terms_days' => 7, 'escalation_type' => 'none',
    ], $attrs));
};
$mkCharges = function (Lease $l, float $rent, float $svc, ?string $from = null) {
    $from = $from ?? $l->commencement_date->toDateString();
    if ($rent > 0) {
        Charge::create(['lease_id' => $l->id, 'name' => 'Base Rent', 'type' => 'base_rent', 'amount' => $rent,
            'currency' => 'EGP', 'frequency' => 'monthly', 'vat_applicable' => Vat::rateForType('base_rent') > 0,
            'vat_rate' => null, 'start_date' => $from, 'is_active' => true]);
    }
    if ($svc > 0) {
        Charge::create(['lease_id' => $l->id, 'name' => 'Service Charge', 'type' => 'service_charge', 'amount' => $svc,
            'currency' => 'EGP', 'frequency' => 'monthly', 'vat_applicable' => Vat::rateForType('service_charge') > 0,
            'vat_rate' => null, 'start_date' => $from, 'is_active' => true]);
    }

    return $l->fresh('charges');
};
$plan = fn (Lease $l, string $m, bool $pro = false) => $billing->planInvoiceForLease(
    $l->fresh('charges'), CarbonImmutable::parse($m.'-01'), CarbonImmutable::parse($m.'-01')->endOfMonth(), $pro);

qa_section('BILLING 1 — VAT treatment (rent exempt, service charge taxed)');
qa_eq('base_rent rate from the catalogue', 0.0, Vat::rateForType('base_rent'));
qa_eq('service_charge rate from the catalogue', 14.0, Vat::rateForType('service_charge'));
qa_eq('marketing levy is VAT-exempt', 0.0, Vat::rateForType('marketing'));

$l1 = $mkCharges($mkLease(['commencement_date' => '2026-01-01', 'expiry_date' => '2027-12-31', 'term_months' => 24,
    'base_rent_monthly' => 30000, 'service_charge_monthly' => 10000]), 30000, 10000);
$p = $plan($l1, '2026-09');
qa_ok('full month is billable', $p['billable'], $p['reason'] ?? '');
qa_eq('subtotal = 30,000 rent + 10,000 service', 40000.00, $p['subtotal']);
qa_eq('VAT = 14% of the service charge only', 1400.00, $p['vat_amount']);
qa_eq('total = 41,400', 41400.00, $p['total']);
qa_eq('factor is a whole month', 1.0, $p['factor']);
$rentLine = collect($p['items'])->firstWhere('type', 'base_rent');
qa_eq('rent line carries 0% VAT', 0.0, $rentLine['vat_rate']);

qa_section('BILLING 2 — leading edge: mid-month commencement');
$l2 = $mkCharges($mkLease(['commencement_date' => '2026-09-16', 'expiry_date' => '2028-09-15', 'term_months' => 24,
    'base_rent_monthly' => 30000, 'service_charge_monthly' => 0]), 30000, 0, '2026-09-16');
$noPro = $plan($l2, '2026-09', false);
qa_eq('WITHOUT prorate the commencement month bills in full', 30000.00, $noPro['subtotal']);
$pro = $plan($l2, '2026-09', true);
// 16..30 Sept inclusive = 15 days of 30
qa_eq('WITH prorate it bills 15/30 of the month', 15000.00, $pro['subtotal']);
qa_eq('period_start moves to the commencement date', '2026-09-16', $pro['period_start']->toDateString());
qa_ok('the line says it is pro-rated', str_contains($pro['items'][0]['description'], 'pro-rated'), $pro['items'][0]['description']);

qa_section('BILLING 3 — trailing edge: mid-month expiry is ALWAYS prorated');
$l3 = $mkCharges($mkLease(['commencement_date' => '2025-01-01', 'expiry_date' => '2026-09-18', 'term_months' => 21,
    'base_rent_monthly' => 31000, 'service_charge_monthly' => 0]), 31000, 0, '2025-01-01');
$t = $plan($l3, '2026-09', false);
// 1..18 Sept = 18 days of 30
qa_eq('final month bills 18/30 even with prorate OFF', round(31000 * 18 / 30, 2), $t['subtotal']);
qa_eq('period_end is the expiry date, not the month end', '2026-09-18', $t['period_end']->toDateString());
$after = $plan($l3, '2026-10', false);
qa_ok('the month AFTER expiry bills nothing', ! $after['billable'], $after['reason']);
qa_eq('…and says why', 'lease_ended', $after['reason']);

qa_section('BILLING 4 — last-day commencement (the signed-diff bug class)');
$l4 = $mkCharges($mkLease(['commencement_date' => '2026-09-30', 'expiry_date' => '2028-09-29', 'term_months' => 24,
    'base_rent_monthly' => 30000, 'service_charge_monthly' => 0]), 30000, 0, '2026-09-30');
$p4 = $plan($l4, '2026-09', true);
qa_eq('a last-day commencement bills exactly one day, not zero', round(30000 / 30, 2), $p4['subtotal']);

qa_section('BILLING 5 — rent commencement (rent-free) mid-month');
$l5 = $mkLease(['commencement_date' => '2026-09-01', 'rent_commencement_date' => '2026-09-15',
    'expiry_date' => '2028-08-31', 'term_months' => 24, 'base_rent_monthly' => 30000, 'service_charge_monthly' => 10000]);
$l5 = $mkCharges($l5, 30000, 10000, '2026-09-01');
$p5 = $plan($l5, '2026-09', false);
$rent5 = collect($p5['items'])->firstWhere('type', 'base_rent');
$svc5 = collect($p5['items'])->firstWhere('type', 'service_charge');
printf("  rent line %s · service line %s\n", number_format($rent5['amount'], 2), number_format($svc5['amount'], 2));
// 15..30 Sept = 16 days of 30
qa_eq('rent is clipped to the rent-commencement date', round(30000 * 16 / 30, 2), $rent5['amount']);
qa_eq('the service charge bills the WHOLE month (grace is per charge type)', 10000.00, $svc5['amount']);

qa_section('BILLING 6 — quarterly cadence');
$l6 = $mkLease(['commencement_date' => '2026-01-01', 'expiry_date' => '2027-12-31', 'term_months' => 24,
    'base_rent_monthly' => 20000, 'service_charge_monthly' => 0, 'billing_frequency' => 'quarterly']);
$l6 = $mkCharges($l6, 20000, 0, '2026-01-01');
$jan = $plan($l6, '2026-01');
$feb = $plan($l6, '2026-02');
$apr = $plan($l6, '2026-04');
qa_ok('a cycle-start month bills', $jan['billable']);
qa_eq('one invoice covers the whole quarter (3 x 20,000)', 60000.00, $jan['subtotal']);
qa_eq('period_end is the quarter end', '2026-03-31', $jan['period_end']->toDateString());
qa_ok('a mid-cycle month bills NOTHING', ! $feb['billable'], $feb['reason']);
qa_eq('…and says why', 'off_cycle', $feb['reason']);
qa_ok('the next cycle start bills again', $apr['billable']);

qa_section('BILLING 7 — a final partial quarter is capped at expiry, not billed whole');
$l7 = $mkLease(['commencement_date' => '2026-01-01', 'expiry_date' => '2026-05-20', 'term_months' => 5,
    'base_rent_monthly' => 20000, 'service_charge_monthly' => 0, 'billing_frequency' => 'quarterly']);
$l7 = $mkCharges($l7, 20000, 0, '2026-01-01');
$q2 = $plan($l7, '2026-04');
printf("  Apr-cycle: billable=%s subtotal=%s period_end=%s\n", $q2['billable'] ? 'y' : 'n',
    number_format($q2['subtotal'], 2), $q2['period_end']?->toDateString());
// April full + May 1-20 (20/31) = 20000 + 12903.23
qa_eq('the final cycle stops at the expiry date', '2026-05-20', $q2['period_end']->toDateString());
qa_eq('…and bills April in full + 20/31 of May', round(20000 + 20000 * 20 / 31, 2), $q2['subtotal'], 0.02);
qa_ok('it does NOT bill a whole quarter past expiry', $q2['subtotal'] < 60000);

qa_section('BILLING 8 — fit-out grace');
// grace is keyed on rent_commencement_date: handover 1 Sep, rent commences 1 Nov = 2 free months
$l8 = $mkLease(['commencement_date' => '2026-09-01', 'rent_commencement_date' => '2026-11-01',
    'expiry_date' => '2028-08-31', 'term_months' => 24,
    'base_rent_monthly' => 30000, 'service_charge_monthly' => 10000, 'fit_out_scope' => 'gross']);
$l8 = $mkCharges($l8, 30000, 10000, '2026-09-01');
$g = $plan($l8, '2026-10');
qa_ok('a GROSS fit-out grace suppresses the whole invoice', ! $g['billable'], $g['reason']);
qa_eq('…reason is fit_out', 'fit_out', $g['reason']);
$l8->update(['fit_out_scope' => 'rent_only']);
$g2 = $plan($l8, '2026-10');
qa_ok('a RENT-ONLY grace still bills the service charge', $g2['billable']);
qa_eq('…and only the service charge', 10000.00, $g2['subtotal']);
$after8 = $plan($l8->fresh(), '2026-11');
qa_eq('after the grace, rent + service bill in full', 40000.00, $after8['subtotal']);

qa_section('BILLING 9 — idempotency: the run never double-bills');
$res = $billing->generateForLease($l1->fresh(), CarbonImmutable::parse('2026-09-01'));
qa_ok('first generate creates an invoice', ($res['invoice'] ?? null) !== null, json_encode(array_diff_key($res, ['invoice' => 1])));
$res2 = $billing->generateForLease($l1->fresh(), CarbonImmutable::parse('2026-09-01'));
qa_ok('second generate creates nothing', ($res2['invoice'] ?? null) === null, json_encode(array_diff_key($res2, ['invoice' => 1])));
qa_eq('exactly one invoice for the lease-month', 1,
    Invoice::where('lease_id', $l1->id)->whereDate('period_start', '2026-09-01')->where('status', '!=', 'cancelled')->count());

qa_section('BILLING 10 — an ambiguous (overlapping) schedule is refused, not silently mis-billed');
$l9 = $mkCharges($mkLease(['commencement_date' => '2026-01-01', 'expiry_date' => '2027-12-31', 'term_months' => 24,
    'base_rent_monthly' => 25000, 'service_charge_monthly' => 0]), 25000, 0, '2026-01-01');
qa_refuses('an overlapping rent row is refused AT THE WRITE (the earliest seam)',
    fn () => Charge::create(['lease_id' => $l9->id, 'name' => 'Base Rent (dup)', 'type' => 'base_rent', 'amount' => 27000,
        'currency' => 'EGP', 'frequency' => 'monthly', 'vat_applicable' => false, 'vat_rate' => null,
        'start_date' => '2026-01-01', 'is_active' => true]));
// …and if legacy/imported data got past that, billing must still refuse rather than mis-bill
DB::table('charges')->insert(['lease_id' => $l9->id, 'name' => 'Base Rent (legacy dup)', 'type' => 'base_rent',
    'amount' => 27000, 'currency' => 'EGP', 'frequency' => 'monthly', 'vat_applicable' => 0, 'vat_rate' => null,
    'start_date' => '2026-01-01', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
qa_refuses('…and a raw legacy overlap is refused at BILLING time too',
    fn () => $plan($l9->fresh(), '2026-09'));

qa_section('BILLING 11 — invoice totals + AR seeding on the persisted document');
$inv = Invoice::where('lease_id', $l1->id)->whereDate('period_start', '2026-09-01')->first();
if ($inv) {
    qa_eq('stored subtotal', 40000.00, (float) $inv->subtotal);
    qa_eq('stored vat', 1400.00, (float) $inv->vat_amount);
    qa_eq('stored total', 41400.00, (float) $inv->total);
    qa_eq('paid_amount starts at zero', 0.00, (float) $inv->paid_amount);
    qa_eq('balance = total', 41400.00, (float) $inv->balance);
    qa_eq('the debtor is the lease tenant', $l1->tenant_id, $inv->tenant_id);
    qa_eq('the invoice carries the property dimension', $asset->id, $inv->asset_id);
    qa_ok('due date is not in the past', $inv->due_date->gte(now()->startOfDay()), $inv->due_date->toDateString());
    qa_eq('issue date = period start', '2026-09-01', $inv->issue_date->toDateString());
}

qa_summary();
