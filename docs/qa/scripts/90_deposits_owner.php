<?php

require __DIR__.'/boot.php';
use App\Models\AccountingPeriod;
use App\Models\Asset;
use App\Models\DepositTransaction;
use App\Models\JournalEntry;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\Accounting\AccountResolver;
use App\Services\BillSecurityDepositService;
use App\Services\LeaseCreationService;
use App\Services\LeaseRentChangeService;
use App\Services\MonthlyBillingService;
use App\Services\OwnerAccounting\FinaliseOwnerStatementRunService;
use App\Services\OwnerAccounting\GenerateOwnerStatementRunService;
use App\Services\Reconciliation\BooksReconciliationService;
use App\Support\DepositHoldings;
use Illuminate\Support\Facades\Artisan;

$asset = Asset::where('code', 'AW')->firstOrFail();
$tenant = Tenant::whereDoesntHave('unitOwnerships')->firstOrFail();
$free = Unit::where('asset_id', $asset->id)->where('status', 'vacant')->orderBy('id')->get();
$billing = app(MonthlyBillingService::class);

qa_section('DEPOSITS 1 — a deposit billed as a charge is a LIABILITY, not revenue');
$l = Lease::create(['tenant_id' => $tenant->id, 'unit_id' => $free[0]->id, 'reference' => 'QA-DEP-'.uniqid(),
    'status' => 'active', 'currency' => 'EGP', 'commencement_date' => '2026-01-01', 'expiry_date' => '2028-12-31',
    'term_months' => 36, 'base_rent_monthly' => 48000, 'service_charge_monthly' => 0, 'has_marketing_levy' => false,
    'security_deposit' => 144000, 'billing_frequency' => 'monthly', 'payment_terms_days' => 7, 'escalation_type' => 'none']);
LeaseCreationService::seedStandardCharges($l, 48000, 0, $l->commencement_date);
$l = $l->fresh('charges');

$depHeldBefore = qa_role_balance('deposits_held');
$inv = app(BillSecurityDepositService::class)->bill($l->fresh());
printf("  deposit invoice %s total=%s\n", $inv->number, number_format((float) $inv->total, 2));
qa_eq('it bills the contractual deposit', 144000.00, (float) $inv->total);
qa_eq('a deposit carries NO VAT (a security is not a supply)', 0.00, (float) $inv->vat_amount);
qa_eq('an UNPAID deposit invoice is not held', 0.00, $l->fresh()->depositHeld());
Artisan::call('accounting:sync-ledger', ['--all' => true]);
$e = JournalEntry::where('source_type', $inv->getMorphClass())->where('source_id', $inv->id)->where('status', 'posted')->first();
qa_dump_entry($e, 'deposit invoice');
$acct = fn ($r) => app(AccountResolver::class)->id($r);
qa_eq('it credits DEPOSITS HELD (a liability), not rent revenue', 144000.00,
    (float) $e->lines->firstWhere('ledger_account_id', $acct('deposits_held'))?->credit);
qa_ok('…and no rent revenue is recognised', $e->lines->firstWhere('ledger_account_id', $acct('rent_revenue')) === null);

qa_section('DEPOSITS 2 — paying it makes it HELD, and both rails reconcile');
$p = Payment::create(['tenant_id' => $l->tenant_id, 'amount' => 144000, 'payment_date' => now()->toDateString(),
    'method' => 'bank_transfer', 'status' => 'captured']);
DB::transaction(function () use ($p, $inv) {
    $p->invoices()->sync([$inv->id => ['allocated_amount' => 144000]]);
    $p->assertInvoicesNotOverAllocated([$inv->id]);
});
$inv->fresh()->recomputeTotals();
qa_eq('the settled deposit is now held', 144000.00, $l->fresh()->depositHeld());
qa_eq('no shortfall remains', 0.00, round(144000 - $l->fresh()->depositHeld(), 2));
qa_ok('NO DepositTransaction row is written (it derives, never copies)',
    DepositTransaction::where('lease_id', $l->id)->doesntExist());
Artisan::call('accounting:sync-ledger', ['--all' => true]);
printf("  deposits_held control: %s → %s\n", number_format($depHeldBefore, 2), number_format(qa_role_balance('deposits_held'), 2));
qa_eq('the liability rose by the deposit', -144000.00, round(qa_role_balance('deposits_held') - $depHeldBefore, 2));
printf("  DepositHoldings: recorded=%s billed&settled=%s held=%s gl=%s\n",
    number_format(DepositHoldings::recorded(), 2), number_format(DepositHoldings::billedAndSettled(), 2),
    number_format(DepositHoldings::held(), 2), number_format((float) DepositHoldings::glBalance(), 2));
qa_eq('the register agrees with the GL liability', round((float) DepositHoldings::glBalance(), 2), round(DepositHoldings::held(), 2), 0.05);
$rec = app(BooksReconciliationService::class);
qa_eq('the deposits tie-out check is clean', 0, count($rec->depositTieOutDiscrepancies()));

qa_section('DEPOSITS 3 — it bills the SHORTFALL, never the contractual figure twice');
qa_refuses('re-billing a fully-held deposit is refused', fn () => app(BillSecurityDepositService::class)->bill($l->fresh()));
$l2 = Lease::create(['tenant_id' => $tenant->id, 'unit_id' => $free[1]->id, 'reference' => 'QA-DEP2-'.uniqid(),
    'status' => 'active', 'currency' => 'EGP', 'commencement_date' => '2026-01-01', 'expiry_date' => '2028-12-31',
    'term_months' => 36, 'base_rent_monthly' => 30000, 'service_charge_monthly' => 0, 'has_marketing_levy' => false,
    'security_deposit' => 90000, 'billing_frequency' => 'monthly', 'payment_terms_days' => 7, 'escalation_type' => 'none']);
LeaseCreationService::seedStandardCharges($l2, 30000, 0, $l2->commencement_date);
DepositTransaction::create(['lease_id' => $l2->id, 'tenant_id' => $l2->tenant_id, 'asset_id' => $asset->id,
    'type' => 'receipt', 'amount' => 40000, 'transaction_date' => '2026-02-01', 'status' => 'recorded']);
$inv2 = app(BillSecurityDepositService::class)->bill($l2->fresh());
qa_eq('it bills only the 50,000 shortfall', 50000.00, (float) $inv2->total);

qa_section('DEPOSITS 4 — the deposit tracks rent when a MULTIPLE is agreed');
$l3 = Lease::create(['tenant_id' => $tenant->id, 'unit_id' => $free[2]->id, 'reference' => 'QA-DEP3-'.uniqid(),
    'status' => 'active', 'currency' => 'EGP', 'commencement_date' => '2026-01-01', 'expiry_date' => '2028-12-31',
    'term_months' => 36, 'base_rent_monthly' => 50000, 'service_charge_monthly' => 0, 'has_marketing_levy' => false,
    'security_deposit_months' => 3, 'billing_frequency' => 'monthly', 'payment_terms_days' => 7,
    'escalation_type' => 'fixed_percent', 'escalation_rate' => 10]);
qa_eq('the deposit is derived from the multiple', 150000.00, (float) $l3->fresh()->security_deposit);
app(LeaseRentChangeService::class)->apply($l3->fresh(), ['base_rent_monthly' => 55000, 'reason' => 'QA step']);
qa_eq('…and follows the rent when it escalates', 165000.00, (float) $l3->fresh()->security_deposit);
$l4 = Lease::create(['tenant_id' => $tenant->id, 'unit_id' => $free[3]->id, 'reference' => 'QA-DEP4-'.uniqid(),
    'status' => 'active', 'currency' => 'EGP', 'commencement_date' => '2026-01-01', 'expiry_date' => '2028-12-31',
    'term_months' => 36, 'base_rent_monthly' => 50000, 'service_charge_monthly' => 0, 'has_marketing_levy' => false,
    'security_deposit' => 77000, 'billing_frequency' => 'monthly', 'payment_terms_days' => 7, 'escalation_type' => 'none']);
app(LeaseRentChangeService::class)->apply($l4->fresh(), ['base_rent_monthly' => 60000, 'reason' => 'QA step']);
qa_eq('a FLAT deposit (no multiple) does not move', 77000.00, (float) $l4->fresh()->security_deposit);

qa_section('OWNER STATEMENTS — the third-party money');
$owner = User::whereHas('ownedAssets')->first() ?? User::where('email', 'owner@atriom.test')->first();
if ($owner) {
    printf("  owner: %s\n", $owner->email);
    try {
        // `generate()` takes the PERIOD, not a start/end pair — a statement run is filed against
        // an accounting period, so the service resolves its own window from it. This script still
        // passed two Carbons and died on a TypeError.
        $period = AccountingPeriod::query()
            ->whereDate('starts_on', '<=', '2026-06-01')
            ->whereDate('ends_on', '>=', '2026-06-01')
            ->first();
        if (! $period) {
            qa_ok('a June 2026 accounting period exists to file the run against', false);

            throw new RuntimeException('no accounting period covering 2026-06');
        }
        $run = app(GenerateOwnerStatementRunService::class)->generate($asset, $period);
        printf("  run #%d status=%s statements=%d\n", $run->id, $run->status, $run->statements()->count());
        qa_ok('a statement run generates', $run->exists);
        foreach ($run->statements as $st) {
            printf("    owner=%s gross=%s expenses=%s net=%s\n", $st->owner?->name ?? $st->user_id,
                number_format((float) $st->gross_revenue, 2), number_format((float) $st->total_expenses, 2),
                number_format((float) $st->net_due, 2));
            qa_eq('net = gross − expenses (owner '.$st->id.')',
                round((float) $st->gross_revenue - (float) $st->total_expenses, 2), round((float) $st->net_due, 2), 0.05);
        }
        $fin = app(FinaliseOwnerStatementRunService::class)->finalise($run->fresh(),
            User::where('email', 'admin@mall.test')->first(), '2026-08-01');
        qa_eq('the run finalises', 'finalised', $fin->status);
        Artisan::call('accounting:sync-ledger', ['--all' => true]);
        $oe = JournalEntry::where('source_type', $fin->getMorphClass())->where('source_id', $fin->id)->where('status', 'posted')->first();
        qa_dump_entry($oe, 'owner statement run');
        qa_ok('finalising posts a journal entry', $oe !== null);
        if ($oe) {
            qa_eq('…and it balances', (float) $oe->lines->sum('debit'), (float) $oe->lines->sum('credit'));
            qa_ok('…crediting DUE TO OWNER (a liability)',
                $oe->lines->firstWhere('ledger_account_id', $acct('due_to_owner')) !== null);
        }
        // F-13 FIXED (2026-08-19): finalise() is idempotent and RETURNS the finalised run rather
        // than throwing. The same stale expectation lived in `91b_owner_correct.php` — one script
        // was updated and this sibling was not, which is the defect this codebase repeats most.
        $again = app(FinaliseOwnerStatementRunService::class)->finalise($fin->fresh(),
            User::where('email', 'admin@mall.test')->first(), '2026-08-01');
        qa_eq('a finalised run finalises again idempotently, returning the same run', $fin->id, $again->id);
    } catch (Throwable $e) {
        qa_ok('owner statement run', false, get_class($e).': '.mb_substr($e->getMessage(), 0, 200));
    }
} else {
    echo "  (no owner user found)\n";
}

qa_section('FINAL TIE-OUT');
Artisan::call('accounting:sync-ledger', ['--all' => true]);
qa_assert_tb('after deposits + owner statements');
$tie = $rec->glTieOut();
qa_eq('AR ties', 0.0, $tie['ar']['delta']);
qa_eq('AP ties', 0.0, $tie['ap']['delta']);
qa_eq('no GL drift', 0, count($rec->glDriftDiscrepancies()));
qa_eq('deposits tie out', 0, count($rec->depositTieOutDiscrepancies()));

qa_summary();
