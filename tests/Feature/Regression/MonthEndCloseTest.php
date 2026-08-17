<?php

use App\Filament\Admin\Pages\MonthEndClose;
use App\Models\AccountingPeriod;
use App\Models\Asset;
use App\Models\Charge;
use App\Models\FiscalYear;
use App\Models\Lease;
use App\Models\TenantSalesDeclaration;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Services\Accounting\MonthEndReadinessService;
use App\Services\MonthlyBillingService;
use App\Support\Vat;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * The month-end close checklist (UX-04, docs/benchmarks/yardi/08-yardi-ui-ux.md).
 *
 * The failure mode a checklist has, that no other screen has, is being **green for the wrong
 * reason** — a row that reports "clear" because it could not read its own input tells the operator
 * to close a month that is not ready. So these tests assert each step goes RED when its condition
 * is genuinely outstanding and GREEN when it is cleared, rather than only asserting the page loads.
 *
 * (One real instance of that bug was caught while writing this: the books-tie-out step read a
 * `$check['ok']` key that `BooksReconciliationService` does not emit — it emits `passed` — so the
 * row would have reported clean on every month forever. It now treats an unreadable result as a
 * failure.)
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->asset = makeAsset();
    $this->month = CarbonImmutable::parse('2026-06-01');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

function readinessFor(?int $assetId = null, ?CarbonImmutable $month = null): array
{
    return app(MonthEndReadinessService::class)
        ->for($month ?? CarbonImmutable::parse('2026-06-01'), $assetId);
}

function stepStatus(array $readiness, string $key): string
{
    return collect($readiness['steps'])->firstWhere('key', $key)['status'];
}

function stepCount(array $readiness, string $key): int
{
    return collect($readiness['steps'])->firstWhere('key', $key)['count'];
}

function billableLeaseForClose(Asset $asset, array $attrs = []): Lease
{
    $lease = makeLease(makeUnit($asset), null, array_merge([
        'status' => 'active',
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2027-12-31',
        'base_rent_monthly' => 20000,
    ], $attrs));

    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Base rent', 'type' => 'base_rent',
        'amount' => 20000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => false, 'vat_rate' => Vat::EXEMPT, 'is_active' => true,
    ]);

    return $lease;
}

/* ---- step 1: billing ------------------------------------------------------ */

it('reports billing outstanding until the month is actually billed', function () {
    billableLeaseForClose($this->asset);

    expect(stepStatus(readinessFor($this->asset->id), 'billing_posted'))
        ->toBe(MonthEndReadinessService::ATTENTION)
        ->and(stepCount(readinessFor($this->asset->id), 'billing_posted'))->toBe(1);

    app(MonthlyBillingService::class)->runForPeriod($this->month, $this->asset->id);

    expect(stepStatus(readinessFor($this->asset->id), 'billing_posted'))
        ->toBe(MonthEndReadinessService::OK);
});

/* ---- step 2: sales declarations ------------------------------------------- */

it('reports a percentage-rent lease with no declaration, and clears when it files', function () {
    $lease = billableLeaseForClose($this->asset, [
        'has_percentage_rent' => true,
        'percentage_rent_rate' => 8,
        'percentage_rent_threshold' => 100000,
    ]);

    expect(stepCount(readinessFor($this->asset->id), 'sales_declared'))->toBe(1);

    TenantSalesDeclaration::create([
        'lease_id' => $lease->id,
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-30',
        'declared_sales' => 500000,
        'declared_at' => '2026-07-03',
        'status' => 'submitted',
    ]);

    expect(stepStatus(readinessFor($this->asset->id), 'sales_declared'))
        ->toBe(MonthEndReadinessService::OK);
});

it('uses the same definition of "owes a declaration" as the reminder scan', function () {
    // One definition, two callers (Lease::missingSalesDeclarationsFor). A lease still inside its
    // fit-out grace is not billable, so it is not chaseable and must not sit on the checklist.
    billableLeaseForClose($this->asset, [
        'has_percentage_rent' => true,
        'commencement_date' => '2026-06-01',
        'rent_commencement_date' => '2026-12-01',
        // Gross grace: nothing bills, so nothing is chased.
        'fit_out_scope' => Lease::FIT_OUT_GROSS,
    ]);

    expect(stepCount(readinessFor($this->asset->id), 'sales_declared'))->toBe(0);
});

/* ---- step 4: vendor bills ------------------------------------------------- */

it('reports a draft vendor bill dated in the month as an unposted expense', function () {
    $vendor = Vendor::create([
        'name' => 'Cleaning Co', 'type' => 'service_provider', 'status' => 'active',
    ]);

    VendorBill::create([
        'vendor_id' => $vendor->id,
        'asset_id' => $this->asset->id,
        'category' => 'cleaning_security',
        'status' => 'draft',
        'bill_date' => '2026-06-15',
        'due_date' => '2026-07-15',
        'subtotal' => 5000, 'vat_amount' => 700, 'total' => 5700, 'balance' => 5700,
    ]);

    expect(stepStatus(readinessFor($this->asset->id), 'vendor_bills_posted'))
        ->toBe(MonthEndReadinessService::ATTENTION);

    // A bill in a DIFFERENT month is not this month's problem.
    expect(stepStatus(readinessFor($this->asset->id, CarbonImmutable::parse('2026-07-01')), 'vendor_bills_posted'))
        ->toBe(MonthEndReadinessService::OK);
});

/* ---- step 7: the period --------------------------------------------------- */

it('tracks the period itself: missing, open, then closed', function () {
    // No accounting period at all — flagged, but not treated as a blocker (a MISSING period is
    // allowed; only a CLOSED one refuses postings).
    expect(stepStatus(readinessFor($this->asset->id), 'period_closed'))
        ->toBe(MonthEndReadinessService::ATTENTION);

    $year = FiscalYear::create([
        'year' => 2026, 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open',
    ]);
    $period = AccountingPeriod::create([
        'fiscal_year_id' => $year->id, 'period_no' => 6,
        'starts_on' => '2026-06-01', 'ends_on' => '2026-06-30', 'status' => 'open',
    ]);

    expect(stepStatus(readinessFor($this->asset->id), 'period_closed'))
        ->toBe(MonthEndReadinessService::ATTENTION)
        ->and(readinessFor($this->asset->id)['closed'])->toBeFalse();

    $period->update(['status' => 'closed']);

    $r = readinessFor($this->asset->id);
    expect(stepStatus($r, 'period_closed'))->toBe(MonthEndReadinessService::DONE)
        ->and($r['closed'])->toBeTrue();
});

/* ---- the roll-up ---------------------------------------------------------- */

it('is only "ready" when every step is clear', function () {
    // The ledger-sync step calls the SAME assertion PeriodService::closePeriod() runs, so an
    // unmapped chart of accounts legitimately blocks it — the GL cannot be synced, so the close
    // would fail. Seed the mappings so this test exercises readiness rather than misconfiguration.
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);

    billableLeaseForClose($this->asset);

    expect(readinessFor($this->asset->id)['ready'])->toBeFalse();

    app(MonthlyBillingService::class)->runForPeriod($this->month, $this->asset->id);

    // The ledger-sync step is the real close gate, and a freshly-billed invoice is not on the GL
    // until the sweep posts it — so this walks the sequence the gate's own message prescribes
    // ("run Post to GL now, then close") through the REAL command, not a stub. Without this the
    // test would be asserting readiness on a month that genuinely is not ready.
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    // The sweep provisions the fiscal calendar itself, so take the period it made rather than
    // minting a second one — and close it, which is the last step of the checklist.
    AccountingPeriod::forDate(CarbonImmutable::parse('2026-06-01'))->update(['status' => 'closed']);

    $r = readinessFor($this->asset->id);
    expect($r['ready'])->toBeTrue()
        ->and($r['blocking'])->toBe(0)
        ->and($r['closed'])->toBeTrue();
});

it('blocks the close when the ledger cannot be synced — the same gate closePeriod() enforces', function () {
    // No chart of accounts mapped: LedgerPoster cannot resolve AR, so nothing can post. The
    // checklist must say BLOCKED rather than waving the operator through to a close that throws.
    $year = FiscalYear::create([
        'year' => 2026, 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open',
    ]);
    AccountingPeriod::create([
        'fiscal_year_id' => $year->id, 'period_no' => 6,
        'starts_on' => '2026-06-01', 'ends_on' => '2026-06-30', 'status' => 'open',
    ]);

    billableLeaseForClose($this->asset);
    app(MonthlyBillingService::class)->runForPeriod($this->month, $this->asset->id);

    $r = readinessFor($this->asset->id);

    expect(stepStatus($r, 'ledger_in_sync'))->toBe(MonthEndReadinessService::BLOCKED)
        ->and($r['ready'])->toBeFalse()
        ->and($r['blocking'])->toBeGreaterThan(0)
        // The service's own message, not a paraphrase — so the operator reads the real reason.
        ->and(collect($r['steps'])->firstWhere('key', 'ledger_in_sync')['detail'])->not->toBeNull();
});

it('goes red when the books stop tying out — the step that was green for the wrong reason', function () {
    // This is the bug this file was written around: the step read a `$check['ok']` key that
    // BooksReconciliationService does not emit (it emits `passed`), so `?? true` made the row
    // report clean on every month forever. Drift a stored balance away from its derived value and
    // the row must go red; if it stays green, the checklist is lying again.
    billableLeaseForClose($this->asset);
    app(MonthlyBillingService::class)->runForPeriod($this->month, $this->asset->id);

    expect(stepStatus(readinessFor($this->asset->id), 'books_tie_out'))
        ->toBe(MonthEndReadinessService::OK);

    // A raw UPDATE, deliberately: this simulates the drift the audit exists to catch — a write
    // that bypassed Invoice::recomputeTotals().
    DB::table('invoices')->update(['balance' => 999999]);

    $r = readinessFor($this->asset->id);
    expect(stepStatus($r, 'books_tie_out'))->toBe(MonthEndReadinessService::BLOCKED)
        ->and($r['ready'])->toBeFalse();
});

it('scopes every step to the current property', function () {
    $otherMall = makeAsset();
    billableLeaseForClose($otherMall);

    // A lease in another mall must not show up as this mall's unbilled work.
    expect(stepCount(readinessFor($this->asset->id), 'billing_posted'))->toBe(0)
        ->and(stepCount(readinessFor($otherMall->id), 'billing_posted'))->toBe(1);
});

/* ---- the page ------------------------------------------------------------- */

it('renders the checklist with every step on it', function () {
    billableLeaseForClose($this->asset);

    $this->actingAs(makeUser('accounting', [$this->asset->id]));
    Filament::setTenant($this->asset);

    expect(MonthEndClose::canAccess())->toBeTrue();

    Livewire::test(MonthEndClose::class)
        ->assertOk()
        ->assertSee(__('admin.month_end.steps.billing_posted'))
        ->assertSee(__('admin.month_end.steps.ledger_in_sync'))
        ->assertSee(__('admin.month_end.steps.books_tie_out'));
});

it('defaults to last month, because you close a month once it is over', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-14'));

    $this->actingAs(makeUser('accounting', [$this->asset->id]));
    Filament::setTenant($this->asset);

    expect(Livewire::test(MonthEndClose::class)->instance()->period)->toBe('2026-06');

    CarbonImmutable::setTestNow();
});
