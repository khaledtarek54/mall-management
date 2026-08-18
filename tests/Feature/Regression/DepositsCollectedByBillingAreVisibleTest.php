<?php

/**
 * A security deposit collected by INVOICE is held, is owed back, and writes no movement row.
 *
 * `BillSecurityDepositService` raises an invoice with a `security_deposit` line (`Dr AR / Cr
 * Deposits Held`); the tenant pays it (`Dr Bank / Cr AR`). The pair nets to exactly what a directly
 * recorded `DepositTransaction` posts in one step — so the money and the ledger were always right.
 *
 * What was wrong is that `deposit_transactions` is the only thing the register and the lease's
 * Deposits tab read, and the billing road writes no row there. Reported from the field: "I paid the
 * security deposit invoice and no security deposit record is done." On the reporter's data the
 * register showed 390,000 against a `deposits_held` liability of 534,000 — understating the
 * landlord's obligation by exactly the deposit that had just been paid — and no reconcile check
 * compared the two, so nothing would ever have said so.
 *
 * The fix DERIVES rather than writing the missing row (see App\Support\DepositHoldings for why a
 * row would double-credit the liability and become a stored copy of a moving number). These tests
 * are therefore about the derived figure being right, being visible, and being checked.
 */

use App\Filament\Admin\RelationManagers\LeaseDepositsRelationManager;
use App\Filament\Admin\Resources\Leases\Pages\EditLease;
use App\Models\AccountMapping;
use App\Models\DepositTransaction;
use App\Models\Lease;
use App\Services\Accounting\FiscalCalendar;
use App\Services\BillSecurityDepositService;
use App\Services\Reconciliation\BooksReconciliationService;
use App\Support\DepositHoldings;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);

    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->tenant = makeTenant();
    $this->lease = makeLease(makeUnit($this->asset), $this->tenant, [
        'security_deposit' => 30000,
    ]);
});

/**
 * Seed the chart and the fiscal year — only for the tests that actually tie out to the ledger.
 *
 * Not in `beforeEach`: `ChartOfAccountsSeeder` dominates this file's runtime, and six of the ten
 * cases never look at the GL. Pest parallelises per FILE, so a slow file sets a floor under the
 * whole suite that no other optimisation can get below.
 */
function seedLedgerForDeposits(): void
{
    test()->seed(ChartOfAccountsSeeder::class);
    test()->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);
}

/** Bill the lease's outstanding deposit and settle it in full — the road under test. */
function billAndPayDeposit(Lease $lease): void
{
    $invoice = app(BillSecurityDepositService::class)->bill($lease);
    settleInvoiceInFull($invoice);
    $lease->refresh()->unsetRelation('deposits');
}

/* ---- the money was never the problem ------------------------------------- */

it('holds the deposit once the invoice is settled, and not before', function () {
    $invoice = app(BillSecurityDepositService::class)->bill($this->lease);
    $this->lease->refresh()->unsetRelation('deposits');

    // Billed but unpaid is a RECEIVABLE, not money in the bank. Counting it as held would refund
    // at move-out what was never received.
    expect($this->lease->depositHeld())->toBe(0.0)
        ->and($this->lease->depositShortfall())->toBe(30000.0);

    settleInvoiceInFull($invoice);
    $this->lease->refresh()->unsetRelation('deposits');

    expect($this->lease->depositHeld())->toBe(30000.0)
        ->and($this->lease->depositShortfall())->toBe(0.0);
});

it('writes no DepositTransaction row — that is the design, not the bug', function () {
    billAndPayDeposit($this->lease);

    // Pinned deliberately. If someone later "fixes" this by inserting a receipt row, the liability
    // is credited twice and this test is where they find out.
    expect(DepositTransaction::where('lease_id', $this->lease->id)->count())->toBe(0)
        ->and($this->lease->depositHeld())->toBe(30000.0);
});

/* ---- the aggregate counts BOTH roads ------------------------------------- */

it('counts recorded movements and billed deposits as one holding', function () {
    $assetIds = [$this->asset->id];

    // Road 1: recorded directly.
    DepositTransaction::create([
        'lease_id' => $this->lease->id,
        'tenant_id' => $this->tenant->id,
        'asset_id' => $this->asset->id,
        'type' => 'receipt',
        'amount' => 5000,
        'status' => 'recorded',
        'transaction_date' => '2026-02-01',
        'method' => 'bank',
    ]);

    // Road 2: billed and settled. The shortfall is now 25,000, not the full 30,000.
    billAndPayDeposit($this->lease);

    expect(DepositHoldings::recorded($assetIds))->toBe(5000.0)
        ->and(DepositHoldings::billedAndSettled($assetIds))->toBe(25000.0)
        ->and(DepositHoldings::held($assetIds))->toBe(30000.0);
});

it('scopes to the selected property', function () {
    // The register is property-isolated, so a summary above it that counted the portfolio would
    // describe a different population from the table underneath.
    $other = makeAsset();
    $otherLease = makeLease(makeUnit($other), makeTenant(), ['security_deposit' => 7000]);

    billAndPayDeposit($this->lease);
    billAndPayDeposit($otherLease);

    expect(DepositHoldings::held([$this->asset->id]))->toBe(30000.0)
        ->and(DepositHoldings::held([$other->id]))->toBe(7000.0)
        ->and(DepositHoldings::held())->toBe(37000.0);
});

/* ---- and it ties to the ledger ------------------------------------------- */

it('ties the derived holding to the deposits-held liability', function () {
    seedLedgerForDeposits();
    billAndPayDeposit($this->lease);

    // The REAL sweep, not LedgerPoster::post() by hand: per the GL invariant, at least one test
    // per source must drive the actual dispatch path and assert the tie-out, because a journalizer
    // with correct arithmetic that nothing ever calls posts nothing.
    $this->artisan('accounting:sync-ledger', ['--since' => now()->subDay()->toDateString()])->assertSuccessful();

    $gl = DepositHoldings::glBalance();

    // The two are computed from different places — one from movements and invoice settlements, the
    // other from posted journal lines — so agreement is evidence rather than a tautology.
    expect($gl)->not->toBeNull()
        ->and($gl)->toBe(30000.0)
        ->and(DepositHoldings::held())->toBe($gl);
});

it('passes the reconcile check when the books agree', function () {
    seedLedgerForDeposits();
    billAndPayDeposit($this->lease);
    $this->artisan('accounting:sync-ledger', ['--since' => now()->subDay()->toDateString()])->assertSuccessful();

    expect(app(BooksReconciliationService::class)->depositTieOutDiscrepancies())->toBe([]);
});

it('FAILS the reconcile check when the holding and the ledger disagree', function () {
    seedLedgerForDeposits();
    // The control that makes the check worth having. A tie-out that only ever passes is
    // indistinguishable from one that never looks — so break the books on purpose and watch it
    // notice. A recorded receipt whose GL entry is deliberately never posted is exactly the shape
    // of drift the check exists for.
    billAndPayDeposit($this->lease);
    $this->artisan('accounting:sync-ledger', ['--since' => now()->subDay()->toDateString()])->assertSuccessful();

    DepositTransaction::create([
        'lease_id' => $this->lease->id,
        'tenant_id' => $this->tenant->id,
        'asset_id' => $this->asset->id,
        'type' => 'receipt',
        'amount' => 4000,
        'status' => 'recorded',
        'transaction_date' => now()->toDateString(),
        'method' => 'bank',
    ]);
    // deliberately NOT synced to the ledger

    $found = app(BooksReconciliationService::class)->depositTieOutDiscrepancies();

    expect($found)->toHaveCount(1)
        ->and($found[0]['detail'])->toContain('4000');
});

it('reports no discrepancy when the deposits-held role is unmapped', function () {
    // A fresh install has no chart. "Not comparable" must not render as "the books are broken", or
    // every new deployment fails its first reconcile for a reason that is not a problem.
    AccountMapping::query()->delete();

    expect(DepositHoldings::glBalance())->toBeNull()
        ->and(app(BooksReconciliationService::class)->depositTieOutDiscrepancies())->toBe([]);
});

/* ---- what the operator actually sees -------------------------------------- */

it('tells the operator the deposit is held even though the table is empty', function () {
    // The reported symptom, at the screen. An empty movements table on a lease holding 30,000 read
    // as "they never paid a deposit"; it must now say where the money went instead.
    billAndPayDeposit($this->lease);

    $this->actingAs(makeUser('manager', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);

    expect(DepositTransaction::where('lease_id', $this->lease->id)->count())->toBe(0);

    Livewire::test(LeaseDepositsRelationManager::class, [
        'ownerRecord' => $this->lease,
        'pageClass' => EditLease::class,
    ])
        ->assertSee(__('admin.lease_deposits.empty_but_held_heading'))
        // …and the summary states the figure, so it is readable without arithmetic.
        ->assertSee('30,000.00');

    Filament::setTenant(null, isQuiet: true);
});

it('still says nothing was paid when nothing was', function () {
    // The control. A summary that always claimed money was held would pass the test above.
    $this->actingAs(makeUser('manager', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);

    Livewire::test(LeaseDepositsRelationManager::class, [
        'ownerRecord' => $this->lease,
        'pageClass' => EditLease::class,
    ])
        ->assertSee(__('admin.lease_deposits.empty_heading'))
        ->assertDontSee(__('admin.lease_deposits.empty_but_held_heading'));

    Filament::setTenant(null, isQuiet: true);
});
